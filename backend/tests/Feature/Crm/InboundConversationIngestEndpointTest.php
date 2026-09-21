<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\FeatureRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The trusted ingest boundary as an HTTP surface.
 *
 * What these tests are really pinning is the difference between what the caller may SAY and what
 * the server DECIDES: the tenant, the lead association and the direction are all server-side, and
 * a request that tries to choose any of them is refused rather than quietly ignored.
 */
class InboundConversationIngestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/automation/conversations/ingest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The provisioned shape: no role, one ability, one org.
     *
     * The membership is re-pointed explicitly rather than left to UserFactory, which attaches every
     * user it builds to whichever org already exists. That default is right for ordinary fixtures
     * and wrong here, where the whole subject is WHICH tenant an identity belongs to.
     */
    private function integrationAccount(?Org $org = null): User
    {
        $org ??= $this->tenant();

        $user = User::factory()->create(['email' => 'meta-inbound-org'.$org->id.'@crm.local']);
        DB::table('org_user')->where('user_id', $user->id)->delete();
        $org->users()->attach($user->id, ['is_default' => true]);
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.ingest', 'web'));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_http_1',
            'external_message_id' => 'mid_http_1',
            'external_event_id' => 'evt_http_1',
            'sender_external_id' => 'psid_http_1',
            'sender_name' => 'Maria Santos',
            'message_type' => 'text',
            'text' => 'Magkano po?',
            'occurred_at' => '2026-09-20T08:00:00+00:00',
            'raw_payload' => ['object' => 'page'],
        ], $overrides);
    }

    // ── authentication ──────────────────────────────────────────────────────────────────────

    /** 1 */
    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->postJson(self::URL, $this->payload())->assertUnauthorized();
        $this->assertSame(0, ConversationMessage::count());
    }

    /** 2 */
    public function test_a_request_with_an_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->postJson(self::URL, $this->payload())
            ->assertUnauthorized();
    }

    /** 3 — the scraper's own account is the realistic "wrong credential": it exists, it is a
     * service account, and it holds a DIFFERENT narrow ability. */
    public function test_a_token_without_the_ingest_ability_is_rejected(): void
    {
        $scraper = User::factory()->create(['email' => 'scraper-service@crm.local']);
        $scraper->givePermissionTo(Permission::findOrCreate('crm.leads.import', 'web'));

        Sanctum::actingAs($scraper);

        $this->postJson(self::URL, $this->payload())->assertForbidden();
        $this->assertSame(0, ConversationMessage::count());
    }

    /** 4 — expiry is written into every machine token; an aged one is no longer an identity. */
    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->integrationAccount();
        $token = $user->createToken('meta-inbound', ['*'], now()->subMinute());

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson(self::URL, $this->payload())
            ->assertUnauthorized();
    }

    // ── tenancy ─────────────────────────────────────────────────────────────────────────────

    /** 5 */
    public function test_the_org_comes_from_the_account_not_the_payload(): void
    {
        $user = $this->integrationAccount();
        Sanctum::actingAs($user);

        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->assertSame(
            $this->tenant()->id,
            (int) Conversation::query()->withoutGlobalScopes()->value('org_id'),
        );
    }

    /** 6 — a caller that tries to choose a tenant is told no, not silently ignored. */
    public function test_a_request_supplied_org_id_is_refused(): void
    {
        $other = Org::create(['name' => 'Another Tenant', 'is_active' => true]);
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['org_id' => $other->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('org_id');

        $this->assertSame(0, ConversationMessage::count());
    }

    /** 6b */
    public function test_a_request_supplied_tenant_id_is_refused(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['tenant_id' => 99]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id');
    }

    /** 6c — association is the CRM's decision, never the caller's. */
    public function test_a_request_supplied_lead_id_is_refused(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['lead_id' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lead_id');
    }

    /** 6d */
    public function test_a_request_supplied_direction_is_refused(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['direction' => 'outbound']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('direction');
    }

    /**
     * 7 — an incompletely provisioned identity is refused, and never with a 500.
     *
     * NOTE ON THE STATUS, because it differs from the design's 403. The platform's own
     * `subscription-active` gate runs BEFORE this route's controller (group middleware always
     * precedes route middleware) and answers 402 first: with no org there is no subscription to
     * find, so an orgless account is indistinguishable from an unsubscribed one at that layer.
     * Reaching the controller's `integration_account_has_no_org` guard would mean inserting a check
     * ahead of a gate shared by every CRM route — not a change to make unilaterally. The guard stays
     * as defence in depth (it is what answers if that ordering ever changes), and what this test
     * pins is the stated requirement: an orgless authenticated integration account is REJECTED
     * BEFORE INGEST. The 402 is accepted for Phase 3B rather than reordering shared middleware to
     * manufacture a 403 — so this asserts refusal, not a server error, and nothing written.
     */
    public function test_an_orgless_account_is_rejected_before_ingest(): void
    {
        $user = User::factory()->create(['email' => 'orphan-inbound@crm.local']);
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.ingest', 'web'));
        // UserFactory attaches every user to the existing org; an identity provisioned incompletely
        // has no such row, which is the condition under test.
        DB::table('org_user')->where('user_id', $user->id)->delete();

        // Tests\TestCase pins CurrentOrg in setUp() so fixtures can be built outside a request.
        // That pin is an override and would mask the very thing under test — in production nothing
        // pins it, and an orgless account resolves to null. Clearing restores the real condition.
        CurrentOrg::clear();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::URL, $this->payload());

        $this->assertContains($response->status(), [402, 403], 'an orgless identity must be refused');
        $this->assertLessThan(500, $response->status(), 'and never as a server error');
        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(0, Conversation::count());
    }

    /** 8 — one identity, one tenant, enforced by the database rather than by convention. */
    public function test_an_integration_account_cannot_hold_a_second_org_membership(): void
    {
        $user = $this->integrationAccount();
        $other = Org::create(['name' => 'Second Tenant', 'is_active' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        $other->users()->attach($user->id, ['is_default' => true]);
    }

    // ── ingest ──────────────────────────────────────────────────────────────────────────────

    /** 9 */
    public function test_a_valid_message_is_created(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $response = $this->postJson(self::URL, $this->payload())->assertCreated();

        $response->assertJsonPath('data.created', true)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.lead_linked', false)
            ->assertJsonPath('data.match_strategy', 'unlinked');

        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame('Magkano po?', ConversationMessage::firstOrFail()->body);
    }

    /** 10 */
    public function test_a_replay_returns_two_hundred_and_duplicates_nothing(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $first = $this->postJson(self::URL, $this->payload())->assertCreated();
        $second = $this->postJson(self::URL, $this->payload())->assertOk();

        $second->assertJsonPath('data.created', false)
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.message_id', $first->json('data.message_id'));

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, Activity::query()->where('event', 'conversation.message_received')->count());
    }

    /** Response privacy: a linked conversation says so, and says nothing more. */
    public function test_the_response_never_reveals_the_lead_or_the_exact_match(): void
    {
        $owner = User::factory()->create();
        $lead = Lead::factory()->create(['email' => 'maria@example.com', 'created_by' => $owner->id]);

        Sanctum::actingAs($this->integrationAccount());

        $response = $this->postJson(self::URL, $this->payload(['sender_email' => 'maria@example.com']))
            ->assertCreated();

        $response->assertJsonPath('data.lead_linked', true)
            ->assertJsonPath('data.match_strategy', 'linked')
            ->assertJsonMissingPath('data.lead_id')
            ->assertJsonMissingPath('data.raw_payload');

        $body = $response->getContent();
        $this->assertStringNotContainsString('maria@example.com', (string) $body);
        $this->assertStringNotContainsString('email', (string) $response->json('data.match_strategy'));
        $this->assertSame($lead->id, Conversation::firstOrFail()->lead_id, 'the association itself still happened');
    }

    // ── validation ──────────────────────────────────────────────────────────────────────────

    /** 11 */
    public function test_an_unknown_provider_or_channel_is_refused(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['provider' => 'tiktok_business']))
            ->assertStatus(422)->assertJsonValidationErrors('provider');

        $this->postJson(self::URL, $this->payload(['channel' => 'carrier_pigeon']))
            ->assertStatus(422)->assertJsonValidationErrors('channel');

        $this->postJson(self::URL, $this->payload(['provider' => 'META']))
            ->assertStatus(422)->assertJsonValidationErrors('provider');
    }

    /** 12 */
    public function test_oversized_text_and_payloads_are_refused(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['text' => str_repeat('a', 20001)]))
            ->assertStatus(422)->assertJsonValidationErrors('text');

        $this->postJson(self::URL, $this->payload(['raw_payload' => ['blob' => str_repeat('x', 70000)]]))
            ->assertStatus(422)->assertJsonValidationErrors('raw_payload');

        $deep = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => ['l8' => ['l9' => 'too deep']]]]]]]]];
        $this->postJson(self::URL, $this->payload(['raw_payload' => $deep]))
            ->assertStatus(422)->assertJsonValidationErrors('raw_payload');

        $this->assertSame(0, ConversationMessage::count());
    }

    /** 12b — the caps are bounds, not obstacles: what sits inside them is accepted. */
    public function test_a_payload_within_the_caps_is_accepted_and_retained(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $payload = ['object' => 'page', 'entry' => [['id' => 'page_42', 'messaging' => [['mid' => 'm1']]]]];

        $this->postJson(self::URL, $this->payload(['raw_payload' => $payload]))->assertCreated();

        $this->assertSame($payload, ConversationMessage::firstOrFail()->payload);
    }

    /** 13 */
    public function test_an_out_of_range_occurred_at_is_refused(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::URL, $this->payload(['occurred_at' => '2019-12-31T00:00:00+00:00']))
            ->assertStatus(422)->assertJsonValidationErrors('occurred_at');

        $this->postJson(self::URL, $this->payload(['occurred_at' => Carbon::now()->addHour()->toIso8601String()]))
            ->assertStatus(422)->assertJsonValidationErrors('occurred_at');

        $this->postJson(self::URL, $this->payload(['occurred_at' => 'the day before yesterday']))
            ->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    }

    /** A message with no provider timestamp is legitimate — our own clock orders it. */
    public function test_a_message_without_occurred_at_is_accepted(): void
    {
        Sanctum::actingAs($this->integrationAccount());

        $payload = $this->payload();
        unset($payload['occurred_at']);

        $this->postJson(self::URL, $payload)->assertCreated();

        $this->assertNull(ConversationMessage::firstOrFail()->occurred_at);
        $this->assertNotNull(ConversationMessage::firstOrFail()->received_at);
    }

    /** The ingest account writes inbound turns and nothing else. */
    public function test_every_stored_message_is_inbound(): void
    {
        Sanctum::actingAs($this->integrationAccount());
        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->assertSame('inbound', ConversationMessage::firstOrFail()->direction);
    }

    /** Tenant isolation end to end: another org's identical thread id stays another org's. */
    public function test_two_orgs_using_the_same_thread_id_stay_separate(): void
    {
        Sanctum::actingAs($this->integrationAccount());
        $this->postJson(self::URL, $this->payload())->assertCreated();

        $otherOrg = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        Entitlements::enable($otherOrg->id, FeatureRegistry::keys());
        Subscription::create([
            'org_id' => $otherOrg->id,
            'plan' => 'full',
            'monthly_price' => 0,
            'seat_limit' => 500,
            'status' => Subscription::ACTIVE,
            'current_period_end' => now()->addYear(),
        ]);

        Sanctum::actingAs($this->integrationAccount($otherOrg));
        $this->postJson(self::URL, $this->payload())->assertCreated();

        $this->assertSame(2, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            [$this->tenant()->id, $otherOrg->id],
            Conversation::query()->withoutGlobalScopes()->orderBy('id')->pluck('org_id')->map(fn ($v) => (int) $v)->all(),
        );

        CurrentOrg::set($this->tenant()->id);
        $this->assertSame(1, Conversation::count(), 'each tenant sees only its own thread');
        $this->assertSame(1, DB::table('crm_conversations')->where('org_id', $otherOrg->id)->count());
    }
}
