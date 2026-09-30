<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\ConversationLeadMatcher;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who an inbound conversation belongs to — the decision with the highest blast radius in this
 * subsystem, because a wrong answer files one prospect's private messages on another company's
 * record.
 *
 * Two properties are pinned here. First, only EXACT identity evidence associates a lead, and an
 * ambiguous signal associates nothing. Second, every strategy stops at the tenant boundary: the
 * cross-org cases below are written with the same address, the same phone and the same provider
 * contact id in both orgs, because that is the shape a real collision would take.
 */
class InboundConversationLeadMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InboundConversationService
    {
        return app(InboundConversationService::class);
    }

    private function message(array $overrides = []): InboundConversationMessage
    {
        return new InboundConversationMessage(
            provider: $overrides['provider'] ?? 'meta',
            channel: $overrides['channel'] ?? 'messenger',
            externalConversationId: $overrides['externalConversationId'] ?? 't_2001',
            externalMessageId: $overrides['externalMessageId'] ?? 'mid_'.uniqid(),
            externalEventId: 'evt_1',
            senderExternalId: $overrides['senderExternalId'] ?? 'psid_555',
            senderName: 'Juan Dela Cruz',
            senderEmail: $overrides['senderEmail'] ?? null,
            senderPhone: $overrides['senderPhone'] ?? null,
            text: $overrides['text'] ?? 'Interested po ako',
            occurredAt: '2026-09-20T08:00:00+00:00',
            rawPayload: ['stub' => true],
        );
    }

    /** A second tenant, plus its own lead, created entirely inside that tenant's org context. */
    private function otherTenantWithLead(array $leadAttributes): array
    {
        $org = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $user = User::factory()->create();

        $lead = CurrentOrg::runAs($org->id, fn (): Lead => Lead::factory()->create([
            ...$leadAttributes,
            'created_by' => $user->id,
        ]));

        return [$org, $lead];
    }

    /** 6 — an exact email match is safe evidence, so the thread is associated. */
    public function test_a_lead_is_associated_on_an_exact_email_match(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['email' => 'juan@example.com', 'created_by' => $user->id]);

        $result = $this->service()->ingest($this->message(['senderEmail' => 'JUAN@Example.com']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_EMAIL, $result->leadMatchStrategy);
        $this->assertSame($lead->id, Conversation::firstOrFail()->lead_id);
    }

    /** 6b — a phone in any common Philippine format resolves to the same canonical number. */
    public function test_a_lead_is_associated_on_an_exact_canonical_phone_match(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['phone' => '0917-123-4567', 'email' => null, 'created_by' => $user->id]);

        $result = $this->service()->ingest($this->message(['senderPhone' => '+63 917 123 4567']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_PHONE, $result->leadMatchStrategy);
        $this->assertSame($lead->id, Conversation::firstOrFail()->lead_id);
    }

    /** 6c — a contact already known on this channel carries the association to a new thread. */
    public function test_a_known_provider_contact_carries_its_lead_to_a_new_thread(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['email' => null, 'created_by' => $user->id]);

        Conversation::factory()->forLead($lead->id)->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_old',
            'external_contact_id' => 'psid_555',
        ]);

        $result = $this->service()->ingest($this->message([
            'externalConversationId' => 't_new',
            'senderExternalId' => 'psid_555',
        ]));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_CONTACT_IDENTITY, $result->leadMatchStrategy);
        $this->assertSame($lead->id, Conversation::query()->where('external_conversation_id', 't_new')->value('lead_id'));
    }

    /** The same contact id on ANOTHER channel is not evidence — different id namespace. */
    public function test_a_contact_id_from_another_channel_is_not_treated_as_the_same_person(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['email' => null, 'created_by' => $user->id]);

        Conversation::factory()->forLead($lead->id)->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_old',
            'external_contact_id' => 'id_shared',
        ]);

        $result = $this->service()->ingest($this->message([
            'channel' => 'instagram_dm',
            'externalConversationId' => 'ig_new',
            'senderExternalId' => 'id_shared',
        ]));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_NONE, $result->leadMatchStrategy);
        $this->assertNull(Conversation::query()->where('external_conversation_id', 'ig_new')->value('lead_id'));
    }

    /** 7 — with no usable signal the thread simply has no lead, and that is a valid state. */
    public function test_a_conversation_can_exist_without_a_lead(): void
    {
        $result = $this->service()->ingest($this->message());

        $this->assertSame(ConversationLeadMatcher::STRATEGY_NONE, $result->leadMatchStrategy);
        $this->assertNull(Conversation::firstOrFail()->lead_id);
        $this->assertSame(1, ConversationMessage::count());
    }

    /** Two leads share the address: the evidence is ambiguous, so nothing is associated. */
    public function test_an_ambiguous_email_match_associates_nothing(): void
    {
        $user = User::factory()->create();
        Lead::factory()->create(['email' => 'shared@example.com', 'created_by' => $user->id]);
        Lead::factory()->create(['email' => 'shared@example.com', 'created_by' => $user->id]);

        $result = $this->service()->ingest($this->message(['senderEmail' => 'shared@example.com']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_AMBIGUOUS, $result->leadMatchStrategy);
        $this->assertNull(Conversation::firstOrFail()->lead_id);
    }

    /** Association is sticky: a later turn never re-opens the question. */
    public function test_an_existing_association_is_never_re_decided_by_a_later_message(): void
    {
        $user = User::factory()->create();
        $original = Lead::factory()->create(['email' => null, 'created_by' => $user->id]);
        Lead::factory()->create(['email' => 'someone@example.com', 'created_by' => $user->id]);

        Conversation::factory()->forLead($original->id)->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_2001',
        ]);

        $result = $this->service()->ingest($this->message(['senderEmail' => 'someone@example.com']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_STICKY, $result->leadMatchStrategy);
        $this->assertSame($original->id, Conversation::query()->where('external_conversation_id', 't_2001')->value('lead_id'));
    }

    /** 15 — the same address in two tenants must never cross. */
    public function test_email_matching_never_crosses_org_boundaries(): void
    {
        [, $theirLead] = $this->otherTenantWithLead(['email' => 'john@example.com']);

        $result = $this->service()->ingest($this->message(['senderEmail' => 'john@example.com']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_NONE, $result->leadMatchStrategy);
        $this->assertNull(Conversation::firstOrFail()->lead_id);
        $this->assertNotNull($theirLead->id);
        $this->assertSame(0, Conversation::query()->where('lead_id', $theirLead->id)->count());
    }

    /** 15b — and when both tenants hold it, each resolves only its own. */
    public function test_each_org_matches_only_its_own_lead_for_a_shared_address(): void
    {
        $user = User::factory()->create();
        $ourLead = Lead::factory()->create(['email' => 'john@example.com', 'created_by' => $user->id]);
        [$theirOrg, $theirLead] = $this->otherTenantWithLead(['email' => 'john@example.com']);

        $ours = $this->service()->ingest($this->message([
            'externalConversationId' => 't_ours',
            'senderEmail' => 'john@example.com',
        ]));

        $theirs = CurrentOrg::runAs($theirOrg->id, fn () => $this->service()->ingest($this->message([
            'externalConversationId' => 't_theirs',
            'senderEmail' => 'john@example.com',
        ])));

        $this->assertSame($ourLead->id, $ours->conversation->lead_id);
        $this->assertSame($theirLead->id, $theirs->conversation->lead_id);
        $this->assertNotSame($ours->conversation->lead_id, $theirs->conversation->lead_id);
    }

    /** 16 — the same phone number in two tenants must never cross. */
    public function test_phone_matching_never_crosses_org_boundaries(): void
    {
        [, $theirLead] = $this->otherTenantWithLead(['phone' => '0917-123-4567', 'email' => null]);

        $result = $this->service()->ingest($this->message(['senderPhone' => '+639171234567']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_NONE, $result->leadMatchStrategy);
        $this->assertSame(0, Conversation::query()->where('lead_id', $theirLead->id)->count());
    }

    /** 17 — a provider contact id known in another tenant is not evidence here. */
    public function test_contact_identity_matching_never_crosses_org_boundaries(): void
    {
        $user = User::factory()->create();
        [$theirOrg, $theirLead] = $this->otherTenantWithLead(['email' => null]);

        CurrentOrg::runAs($theirOrg->id, fn () => Conversation::factory()->forLead($theirLead->id)->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_theirs',
            'external_contact_id' => 'psid_555',
        ]));

        $result = $this->service()->ingest($this->message(['senderExternalId' => 'psid_555']));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_NONE, $result->leadMatchStrategy);
        $this->assertNull(Conversation::query()->where('external_conversation_id', 't_2001')->value('lead_id'));
        $this->assertNotNull($user->id);
    }

    /** An identical thread id in two tenants stays two threads. */
    public function test_conversation_lookup_never_crosses_org_boundaries(): void
    {
        $org = Org::create(['name' => 'Third Tenant Inc', 'is_active' => true]);

        $this->service()->ingest($this->message(['externalConversationId' => 't_shared', 'externalMessageId' => 'mid_a']));
        CurrentOrg::runAs($org->id, fn () => $this->service()->ingest(
            $this->message(['externalConversationId' => 't_shared', 'externalMessageId' => 'mid_a']),
        ));

        $this->assertSame(
            2,
            Conversation::query()->withoutGlobalScopes()->where('external_conversation_id', 't_shared')->count(),
        );
        $this->assertSame(2, ConversationMessage::query()->withoutGlobalScopes()->count());
    }
}
