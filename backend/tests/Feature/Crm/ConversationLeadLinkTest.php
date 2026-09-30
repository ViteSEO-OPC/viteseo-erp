<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\ConversationLeadMatcher;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3H.3A — attaching a conversation to a lead by hand.
 *
 * THE OPERATION IS ONE COLUMN, SO ALMOST EVERYTHING HERE IS ABOUT WHAT IT REFUSES. Writing
 * `lead_id` is trivial; refusing to write it over somebody else's answer, refusing to reach another
 * tenant's records, refusing to let a client name the lead in a payload, and refusing to become a
 * second way to read a lead's contact details are the reasons this slice needed a service, a
 * request class and a resource rather than three lines in a controller.
 *
 * NO PROVIDER TRAFFIC IS POSSIBLE. `Http::preventStrayRequests()` is installed before every test,
 * so a request that escaped would fail the test rather than leave the building. Linking touches no
 * credential and no Meta endpoint, and the assertions say so out loud.
 */
class ConversationLeadLinkTest extends TestCase
{
    use RefreshDatabase;

    private const LINK_PERMISSION = 'crm.conversations.link_lead';

    private const VIEW_PERMISSION = 'crm.conversations.view';

    private const LEAD_EDIT_PERMISSION = 'crm.leads.edit';

    /** Fields a link response must never carry — the reason it is not LeadResource. */
    private const FORBIDDEN_IN_RESPONSE = [
        'email',
        'phone',
        'address',
        'latitude',
        'longitude',
        'placeId',
        'place_id',
        'external_contact_id',
        'externalContactId',
        'external_conversation_id',
        'metadata',
        'provider_account_id',
        'providerAccountId',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------- fixtures

    private function tenantId(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    /**
     * A user with exactly the abilities asked for and no role.
     *
     * Roles are avoided deliberately: `crm_admin` holds all three permissions AND satisfies
     * `Lead::isVisibleTo()` unconditionally, which would make every authorization test below pass
     * for the wrong reason. Granting abilities one at a time is what lets test 9 and test 10
     * distinguish "lacks the link ability" from "lacks authority over this lead".
     */
    private function user(
        bool $mayView = true,
        bool $mayLink = true,
        bool $mayEditLeads = true,
        bool $superAdmin = false,
    ): User {
        $user = User::factory()->create();

        if ($mayView) {
            $user->givePermissionTo(Permission::findOrCreate(self::VIEW_PERMISSION, 'web'));
        }

        if ($mayLink) {
            $user->givePermissionTo(Permission::findOrCreate(self::LINK_PERMISSION, 'web'));
        }

        if ($mayEditLeads) {
            $user->givePermissionTo(Permission::findOrCreate(self::LEAD_EDIT_PERMISSION, 'web'));
        }

        if ($superAdmin) {
            $user->assignRole('super_admin');
        }

        return $user;
    }

    /**
     * A lead the given user owns.
     *
     * `assigned_to` is what makes `LeadPolicy@update` pass for a user with no admin role — the
     * ownership half of the policy. A lead created without an owner is the fixture for test 10.
     */
    private function lead(?User $owner = null, array $overrides = []): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            ...$overrides,
        ]);
    }

    private function conversation(array $overrides = []): Conversation
    {
        return Conversation::query()->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => $overrides['external_conversation_id'] ?? 'thread_'.uniqid(),
            'external_contact_id' => $overrides['external_contact_id'] ?? 'psid_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subHours(2),
            'last_message_at' => now(),
        ]);
    }

    private function url(Conversation $conversation, Lead|int $lead): string
    {
        $leadId = $lead instanceof Lead ? $lead->id : $lead;

        return '/api/v1/crm/conversations/'.$conversation->id.'/lead/'.$leadId;
    }

    /** The raw, unscoped value — so an assertion cannot be satisfied by a filtered read. */
    private function storedLeadId(Conversation $conversation): ?int
    {
        $value = DB::table('crm_conversations')->where('id', $conversation->id)->value('lead_id');

        return $value === null ? null : (int) $value;
    }

    private function linkActivityCount(): int
    {
        return Activity::query()->withoutGlobalScopes()->where('event', 'conversation.linked')->count();
    }

    // ----------------------------------------------------------- HAPPY PATH

    /** 1 — an authorized operator attaches an unlinked thread, and the decision is recorded once. */
    public function test_an_authorized_operator_links_an_unlinked_conversation(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $response = $this->postJson($this->url($conversation, $lead));

        $response->assertStatus(200);
        $response->assertJsonPath('changed', true);
        $response->assertJsonPath('lead.id', (string) $lead->id);
        $response->assertJsonPath('lead.businessName', $lead->business_name);

        $this->assertSame($lead->id, $this->storedLeadId($conversation));
        $this->assertSame(1, $this->linkActivityCount());

        $activity = Activity::query()->withoutGlobalScopes()->where('event', 'conversation.linked')->firstOrFail();
        $this->assertSame(Lead::class, $activity->subject_type);
        $this->assertSame($lead->id, (int) $activity->subject_id);
        $this->assertSame($user->id, (int) $activity->causer_id);
        $this->assertSame((int) $conversation->org_id, (int) $activity->org_id);
        $this->assertSame($conversation->id, $activity->properties['conversation_id']);
        $this->assertSame('messenger', $activity->properties['channel']);
        $this->assertNull($activity->properties['previous_lead_id']);

        Http::assertNothingSent();
    }

    /**
     * 2 / 13 — the same link again is a no-op, not a second event.
     *
     * A double-click and an HTTP retry are both ordinary, and both must be safe. Mirrors
     * LeadAssignmentService, which returns early on a no-change handover for the same reason: an
     * audit trail that records non-events is one a person stops reading.
     */
    public function test_relinking_the_same_lead_is_an_idempotent_no_op(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $lead))->assertStatus(200)->assertJsonPath('changed', true);

        $repeat = $this->postJson($this->url($conversation, $lead));

        $repeat->assertStatus(200);
        $repeat->assertJsonPath('changed', false);
        $repeat->assertJsonPath('lead.id', (string) $lead->id);

        $this->assertSame($lead->id, $this->storedLeadId($conversation));
        $this->assertSame(1, $this->linkActivityCount(), 'A repeat must not write a second Activity.');

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------- REFUSAL

    /**
     * 3 / 14 — a thread that already names a different lead is a conflict, never a replacement.
     *
     * This is the invariant the whole slice exists to preserve: ConversationResolver::advance()
     * refuses to move an association automatically, and the manual path must not become the
     * loophole. It doubles as the conflict branch of the concurrency guard — a request arriving
     * after somebody else won the race sees exactly this.
     */
    public function test_a_conversation_linked_to_another_lead_is_a_conflict(): void
    {
        $this->tenantId();
        $user = $this->user();
        $incumbent = $this->lead($user);
        $challenger = $this->lead($user);

        $conversation = $this->conversation();
        $conversation->lead_id = $incumbent->id;
        $conversation->save();

        Sanctum::actingAs($user);

        $response = $this->postJson($this->url($conversation, $challenger));

        $response->assertStatus(409);
        $response->assertJsonPath('changed', false);
        $response->assertJsonPath('lead', null);

        $this->assertSame($incumbent->id, $this->storedLeadId($conversation), 'The incumbent must survive.');
        $this->assertSame(0, $this->linkActivityCount(), 'A conflict writes no Activity.');

        // The response must not name the lead that won — LeadPolicy@view gates that, and a
        // conflict body volunteering a business name would be a way around it.
        $this->assertStringNotContainsString($incumbent->business_name, $response->getContent());

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- REACHABILITY
    //
    // Four different causes, ONE indistinguishable answer. The point of these four is not that each
    // 404s — it is that a caller cannot tell them apart and so cannot use the endpoint to discover
    // whether another tenant's lead id exists.

    /** 4 — a lead belonging to another tenant. */
    public function test_a_lead_from_another_tenant_is_unreachable(): void
    {
        $orgA = $this->tenantId();
        $user = $this->user();
        $conversation = $this->conversation();

        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreignLead = CurrentOrg::runAs($orgB->id, fn (): Lead => Lead::factory()->create());

        $this->assertSame(
            $orgB->id,
            (int) DB::table('crm_leads')->where('id', $foreignLead->id)->value('org_id'),
            'The fixture must really belong to the other tenant.',
        );

        CurrentOrg::set($orgA);
        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $foreignLead))->assertStatus(404);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
        Http::assertNothingSent();
    }

    /** 5 — a conversation belonging to another tenant. */
    public function test_a_conversation_from_another_tenant_is_unreachable(): void
    {
        $orgA = $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);

        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreign = CurrentOrg::runAs($orgB->id, fn (): Conversation => $this->conversation());

        $this->assertSame(
            $orgB->id,
            (int) DB::table('crm_conversations')->where('id', $foreign->id)->value('org_id'),
            'The fixture must really belong to the other tenant.',
        );

        CurrentOrg::set($orgA);
        Sanctum::actingAs($user);

        $this->postJson($this->url($foreign, $lead))->assertStatus(404);

        $this->assertNull($this->storedLeadId($foreign));
        $this->assertSame(0, $this->linkActivityCount());
        Http::assertNothingSent();
    }

    /** 6 — a lead id that was never issued. */
    public function test_a_missing_lead_is_unreachable(): void
    {
        $this->tenantId();
        $user = $this->user();
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, 987654321))->assertStatus(404);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    /**
     * 7 — an archived lead.
     *
     * Route-model binding resolves Lead through SoftDeletes, so an archived lead is unreachable for
     * the same reason and with the same answer as one that never existed. This is what keeps
     * linking from re-creating the dangling association that the ingest fallback below has to
     * tolerate for history.
     */
    public function test_an_archived_lead_is_unreachable(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        $lead->delete();
        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $lead))->assertStatus(404);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    // -------------------------------------------------------- AUTHORIZATION

    /**
     * 8 — no inbox access, no linking.
     *
     * This is the case that made a dedicated ability necessary. In production `crm_bd` holds
     * `crm.leads.edit` but NOT `crm.conversations.view`, so a gate built on the lead permission
     * alone would have authorized a user who cannot read a single message in the thread.
     */
    public function test_a_user_without_conversation_view_is_denied(): void
    {
        $this->tenantId();
        $user = $this->user(mayView: false);
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $lead))->assertStatus(403);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    /** 9 — reading the inbox does not imply filing its threads. */
    public function test_a_user_who_may_view_but_not_link_is_denied(): void
    {
        $this->tenantId();
        $user = $this->user(mayLink: false);
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $lead))->assertStatus(403);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    /**
     * 10 — the ability is not enough; authority over THIS lead is also required.
     *
     * Two independent halves of LeadPolicy@update, tested separately: the `crm.leads.edit` ability,
     * and `Lead::isVisibleTo()` — which for a user with no admin role means the lead must be
     * assigned to them. Without the second half, any inbox reader could attach threads to leads
     * belonging to a colleague.
     */
    public function test_a_user_failing_the_lead_policy_is_denied(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();

        // (a) holds every conversation ability but not crm.leads.edit.
        $noEdit = $this->user(mayEditLeads: false);
        Sanctum::actingAs($noEdit);
        $this->postJson($this->url($conversation, $this->lead($noEdit)))->assertStatus(403);

        // (b) holds crm.leads.edit, but the lead belongs to someone else.
        $notOwner = $this->user();
        $colleague = User::factory()->create();
        Sanctum::actingAs($notOwner);
        $this->postJson($this->url($conversation, $this->lead($colleague)))->assertStatus(403);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    /**
     * 11 — super_admin passes on `Gate::before`, holding none of the three grants explicitly.
     *
     * Documented rather than merely permitted: CrmServiceProvider registers a blanket
     * `Gate::before(super_admin => true)`, which answers every ability check — the two `permission:`
     * middlewares and `LeadPolicy@update` alike — before any table is consulted. So a super_admin
     * can link any thread to any live lead in their org, including one they do not own.
     */
    public function test_a_super_admin_may_link_without_explicit_grants(): void
    {
        $this->tenantId();
        $superAdmin = $this->user(mayView: false, mayLink: false, mayEditLeads: false, superAdmin: true);
        $somebodyElsesLead = $this->lead(User::factory()->create());
        $conversation = $this->conversation();

        Sanctum::actingAs($superAdmin);

        $this->postJson($this->url($conversation, $somebodyElsesLead))
            ->assertStatus(200)
            ->assertJsonPath('changed', true);

        $this->assertSame($somebodyElsesLead->id, $this->storedLeadId($conversation));
        Http::assertNothingSent();
    }

    /**
     * 14 (spec §14) — the ability row is ABSENT in production, and must fail closed, not explode.
     *
     * Spatie's PermissionMiddleware authorizes through Laravel's `canAny()`, and Spatie's own
     * `Gate::before` swallows PermissionDoesNotExist and answers false. So an unseeded name is a
     * clean 403 rather than a 500 with a stack trace — which is what lets this code deploy BEFORE
     * the targeted production grant runs.
     */
    public function test_the_endpoint_fails_closed_while_the_permission_row_is_absent(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Permission::findByName(self::LINK_PERMISSION, 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNull(Permission::query()->where('name', self::LINK_PERMISSION)->first());

        Sanctum::actingAs($user);

        $response = $this->postJson($this->url($conversation, $lead));

        $response->assertStatus(403);
        $this->assertStringNotContainsString('PermissionDoesNotExist', $response->getContent());

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    /** And a super_admin still passes with the row absent, because Gate::before never asks the table. */
    public function test_a_super_admin_passes_while_the_permission_row_is_absent(): void
    {
        $this->tenantId();
        $superAdmin = $this->user(mayView: false, mayLink: false, mayEditLeads: false, superAdmin: true);
        $lead = $this->lead();
        $conversation = $this->conversation();

        Permission::findByName(self::LINK_PERMISSION, 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($superAdmin);

        $this->postJson($this->url($conversation, $lead))->assertStatus(200);
    }

    // ------------------------------------------------------------ CONTRACT

    /**
     * 12 — a caller cannot name the lead, the thread or the tenant in the body, and is TOLD so.
     *
     * None of these has anywhere to land — the linker takes two bound models and an org id and
     * reads nothing from the request — so the prohibition is not about a field that would otherwise
     * take effect. It is about telling a client that believes it is choosing, immediately, rather
     * than letting `validated()` drop it and linking something else.
     */
    #[DataProvider('forbiddenFields')]
    public function test_a_caller_cannot_supply_server_owned_fields(string $field): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $lead), [$field => 'anything'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertNull($this->storedLeadId($conversation));
        $this->assertSame(0, $this->linkActivityCount());
    }

    /** @return array<string, array{string}> */
    public static function forbiddenFields(): array
    {
        $fields = [
            'leadId', 'lead_id', 'lead',
            'conversationId', 'conversation_id', 'conversation',
            'orgId', 'org_id',
            'provider', 'channel',
            'provider_account_id', 'providerAccountId',
            'external_contact_id', 'externalContactId',
            'external_conversation_id', 'externalConversationId',
        ];

        return array_combine($fields, array_map(static fn (string $f): array => [$f], $fields));
    }

    /**
     * 19 — the response is four fields, and the omissions are the contract.
     *
     * A link confirmation that carried the lead's email, phone, address or coordinates would make
     * the inbox a second, unaudited route to a lead's contact details for anyone who can open a
     * conversation. This pins the shape against a future "just use LeadResource" refactor.
     */
    public function test_the_response_exposes_only_the_inbox_lead_summary(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user, [
            'email' => 'private-contact@example.invalid',
            'phone' => '+63 917 000 0001',
            'address' => '77 Secret Street',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);
        $conversation = $this->conversation(['external_contact_id' => 'psid_do_not_leak_me']);

        Sanctum::actingAs($user);

        $response = $this->postJson($this->url($conversation, $lead));
        $response->assertStatus(200);

        $response->assertJsonStructure(['changed', 'lead' => ['id', 'businessName', 'contactName', 'status']]);
        $this->assertSame(
            ['id', 'businessName', 'contactName', 'status'],
            array_keys($response->json('lead')),
            'The lead payload must be exactly the four InboxLeadSummary fields.',
        );

        $body = $response->getContent();

        foreach (self::FORBIDDEN_IN_RESPONSE as $key) {
            $this->assertStringNotContainsString($key, $body, "Response leaked the key [{$key}].");
        }

        $this->assertStringNotContainsString('private-contact@example.invalid', $body);
        $this->assertStringNotContainsString('917 000 0001', $body);
        $this->assertStringNotContainsString('77 Secret Street', $body);
        $this->assertStringNotContainsString('psid_do_not_leak_me', $body);
    }

    // ------------------------------------------------------ PROSPECT TARGETS

    /**
     * §12 — an unqualified prospect is a valid target, deliberately.
     *
     * There is no `active()` constraint anywhere on this path. An inbound enquiry from an unknown
     * business is precisely the case most likely to exist already as a discovered prospect in the
     * Nearby pool, and refusing those would defeat the feature. Pinned as a test because
     * `GET /leads` DOES apply `active()`, so a future author reusing that query for the 3H.4 picker
     * would silently narrow the contract this endpoint promises.
     */
    public function test_an_unqualified_prospect_is_a_valid_link_target(): void
    {
        $this->tenantId();
        $user = $this->user();
        $prospect = $this->lead($user, ['source' => 'nerby', 'qualified_at' => null]);
        $conversation = $this->conversation();

        $this->assertTrue($prospect->fresh()->isProspect(), 'The fixture must really be an unqualified prospect.');

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $prospect))
            ->assertStatus(200)
            ->assertJsonPath('changed', true);

        $this->assertSame($prospect->id, $this->storedLeadId($conversation));
    }

    // ---------------------------------------------------------- THE MATCHER

    /**
     * 15 — a manual link is sticky, and outranks contrary evidence arriving later.
     *
     * The thread is linked to a lead with no contact details at all, then a later inbound turn
     * carries an email that exactly matches a DIFFERENT lead — the strongest automatic signal the
     * matcher has short of stickiness. It must still answer `sticky`. No matcher change was made
     * for this slice; the test proves none was needed.
     */
    public function test_the_matcher_preserves_a_manually_linked_lead(): void
    {
        $this->tenantId();
        $user = $this->user();

        $chosen = $this->lead($user, ['email' => null, 'phone' => null]);
        $other = $this->lead($user, ['email' => 'someone.else@example.invalid']);

        $conversation = $this->conversation(['external_conversation_id' => 't_sticky_1']);

        Sanctum::actingAs($user);
        $this->postJson($this->url($conversation, $chosen))->assertStatus(200);

        $result = app(InboundConversationService::class)->ingest(new InboundConversationMessage(
            provider: 'meta',
            channel: 'messenger',
            externalConversationId: 't_sticky_1',
            externalMessageId: 'mid_sticky_'.uniqid(),
            senderExternalId: $conversation->external_contact_id,
            senderEmail: 'someone.else@example.invalid',
            text: 'A later turn carrying contrary evidence',
            occurredAt: now()->toIso8601String(),
        ));

        $this->assertSame(ConversationLeadMatcher::STRATEGY_STICKY, $result->leadMatchStrategy);
        $this->assertSame($chosen->id, $this->storedLeadId($conversation));
        $this->assertNotSame($other->id, $this->storedLeadId($conversation));
    }

    // --------------------------------------------- ARCHIVED-LEAD INGEST FIX

    /**
     * 16 — an inbound turn on a thread whose linked lead was archived still lands.
     *
     * THE DEFECT THIS CLOSES. `logActivity()` used to resolve the subject with
     * `$conversation->lead()->firstOrFail()`. Lead uses SoftDeletes, archiving does not clear
     * `crm_conversations.lead_id`, and the resulting ModelNotFoundException is caught by NEITHER of
     * ingest()'s two catch clauses — so the whole transaction rolled back, the provider retried,
     * and every retry failed identically. The thread went permanently deaf to inbound.
     *
     * Unreachable in production today because nothing is linked; reachable the moment this slice
     * ships, with six leads already archived.
     */
    public function test_an_inbound_turn_survives_its_linked_lead_being_archived(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation(['external_conversation_id' => 't_archived_1']);

        Sanctum::actingAs($user);
        $this->postJson($this->url($conversation, $lead))->assertStatus(200);

        $lead->delete();
        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);

        $result = app(InboundConversationService::class)->ingest(new InboundConversationMessage(
            provider: 'meta',
            channel: 'messenger',
            externalConversationId: 't_archived_1',
            externalMessageId: 'mid_archived_1',
            senderExternalId: $conversation->external_contact_id,
            text: 'Still here',
            occurredAt: now()->toIso8601String(),
        ));

        // The message persisted — the whole point.
        $this->assertTrue($result->created);
        $this->assertSame(1, ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('external_message_id', 'mid_archived_1')
            ->count());

        // The association is untouched: the fix changes where the Activity is FILED, nothing else.
        $this->assertSame($lead->id, $this->storedLeadId($conversation));

        // And the audit trail exists, on the conversation rather than on the unreachable lead.
        $activity = Activity::query()
            ->withoutGlobalScopes()
            ->where('event', 'conversation.message_received')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Conversation::class, $activity->subject_type);
        $this->assertSame($conversation->id, (int) $activity->subject_id);
    }

    /** 17 — and the ordinary case is unchanged: a live linked lead still owns the Activity. */
    public function test_an_inbound_turn_on_a_live_linked_lead_still_files_against_the_lead(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation(['external_conversation_id' => 't_live_1']);

        Sanctum::actingAs($user);
        $this->postJson($this->url($conversation, $lead))->assertStatus(200);

        app(InboundConversationService::class)->ingest(new InboundConversationMessage(
            provider: 'meta',
            channel: 'messenger',
            externalConversationId: 't_live_1',
            externalMessageId: 'mid_live_1',
            senderExternalId: $conversation->external_contact_id,
            text: 'Ordinary turn',
            occurredAt: now()->toIso8601String(),
        ));

        $activity = Activity::query()
            ->withoutGlobalScopes()
            ->where('event', 'conversation.message_received')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(Lead::class, $activity->subject_type);
        $this->assertSame($lead->id, (int) $activity->subject_id);
    }

    // ------------------------------------------------------------ NO PROVIDER

    /**
     * 18 — linking is CRM bookkeeping and touches no provider.
     *
     * No credential is resolved, no Meta endpoint is called, and no outbound row is written.
     * `preventStrayRequests()` would already have failed the test; this states the property rather
     * than relying on the absence of a failure.
     */
    public function test_linking_performs_no_provider_traffic_and_writes_no_message(): void
    {
        $this->tenantId();
        $user = $this->user();
        $lead = $this->lead($user);
        $conversation = $this->conversation();

        Sanctum::actingAs($user);

        $this->postJson($this->url($conversation, $lead))->assertStatus(200);

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->count());
    }
}
