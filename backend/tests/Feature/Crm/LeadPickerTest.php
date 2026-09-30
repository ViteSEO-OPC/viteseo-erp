<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Policies\LeadPolicy;
use App\Modules\Crm\Services\ConversationLinkCapability;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3H.4A — the lead picker behind "link this conversation to a lead".
 *
 * TWO PROPERTIES CARRY THE WHOLE SLICE, and most of this file defends one or the other.
 *
 * FIRST: every row the picker returns must be a lead the SAME operator could actually link. A
 * picker that lists a lead the endpoint then refuses is worse than one that lists nothing — the
 * mistake is invisible until after the operator has chosen a name. `test_every_returned_candidate_passes_the_link_policy`
 * asserts it directly against `LeadPolicy@update` rather than trusting the query.
 *
 * SECOND: the ordinary `GET /leads` must be untouched. It serves the Leads table, two summary
 * endpoints and the export, and this change adds a branch to its controller. Four tests pin its
 * active-only filter, its resource, its page size and its indifference to `per_page`.
 *
 * NO PROVIDER TRAFFIC IS POSSIBLE. `Http::preventStrayRequests()` runs before every test; linking
 * and searching touch no credential and no Meta endpoint.
 */
class LeadPickerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/leads';

    /** Fields the picker must never serialize — the reason it is not LeadResource. */
    private const FORBIDDEN_KEYS = [
        'email', 'phone', 'address', 'latitude', 'longitude', 'placeId', 'place_id',
        'source', 'score', 'amount', 'website', 'facebook', 'instagram', 'tiktok',
        'linkedin', 'shopee', 'lazada', 'tiktokShop', 'otherMarketplace',
        'assignedToId', 'assignedTo', 'region', 'province', 'city', 'industry',
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
     * A user holding exactly the abilities asked for and no role.
     *
     * Roles are avoided for the same reason as in ConversationLeadLinkTest: `crm_admin` holds every
     * ability AND satisfies `Lead::isVisibleTo` unconditionally, which would make the authorization
     * tests pass for the wrong reason.
     */
    private function user(array $abilities = ConversationLinkCapability::ABILITIES, bool $superAdmin = false): User
    {
        $user = User::factory()->create();

        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }

        if ($superAdmin) {
            $user->assignRole('super_admin');
        }

        return $user;
    }

    /** An operator with every ability the picker needs. */
    private function operator(): User
    {
        return $this->user();
    }

    /**
     * Make the super_admin ROLE look the way production actually has it.
     *
     * THE SEEDER AND PRODUCTION DISAGREE, AND THE TEST MUST FOLLOW PRODUCTION. `$crmPermissions` in
     * RolesAndPermissionsSeeder includes `crm.conversations.link_lead` and is handed to super_admin
     * wholesale, so a freshly seeded database gives super_admin a real `role_has_permissions` row.
     * Production was never re-seeded: Phase 3H.3A-2 created the ability and granted it to
     * `crm_admin` ONLY, so live super_admins hold 136 permissions and this is not among them — they
     * pass solely through `Gate::before(super_admin)`.
     *
     * Leaving the seeded row in place would have let these tests pass for the wrong reason: the
     * ordinary permission lookup would succeed and the Gate bypass would never be exercised, so the
     * exact regression they exist to catch — an implementation reading `getAllPermissions()` — would
     * sail through. Stripping the row first is what makes them bite.
     */
    private function stripSuperAdminLinkGrant(): void
    {
        Role::findByName('super_admin', 'web')
            ->revokePermissionTo(Permission::findByName('crm.conversations.link_lead', 'web'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function activeLead(?User $owner = null, array $overrides = []): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'source' => null,
            'qualified_at' => null,
            ...$overrides,
        ]);
    }

    private function prospect(?User $owner = null, array $overrides = []): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'source' => 'nerby',
            'qualified_at' => null,
            ...$overrides,
        ]);
    }

    /** @return array<int, string> the ids the picker returned */
    private function idsFrom(array $json): array
    {
        return array_map(static fn (array $row): string => $row['id'], $json['data']);
    }

    // ---------------------------------------------------------- HAPPY PATH

    /** 1 / 2 / 3 — the operator can query, and BOTH populations are reachable. */
    public function test_an_authorized_operator_sees_active_leads_and_prospects(): void
    {
        $this->tenantId();
        $operator = $this->operator();

        $active = $this->activeLead($operator);
        $prospect = $this->prospect($operator);

        Sanctum::actingAs($operator);

        $response = $this->getJson(self::URL.'?scope=linkable');
        $response->assertStatus(200);

        $ids = $this->idsFrom($response->json());

        $this->assertContains((string) $active->id, $ids, 'An active CRM lead must be linkable.');
        $this->assertContains(
            (string) $prospect->id,
            $ids,
            'An unqualified prospect must be linkable — in production they are 625 of 1049 live leads.',
        );

        Http::assertNothingSent();
    }

    /** 4 — an archived lead is not a valid target, so the picker must not offer one. */
    public function test_an_archived_lead_is_excluded(): void
    {
        $this->tenantId();
        $operator = $this->operator();

        $live = $this->activeLead($operator);
        $archived = $this->activeLead($operator);
        $archived->delete();
        $this->assertSoftDeleted('crm_leads', ['id' => $archived->id]);

        Sanctum::actingAs($operator);

        $ids = $this->idsFrom($this->getJson(self::URL.'?scope=linkable')->assertStatus(200)->json());

        $this->assertContains((string) $live->id, $ids);
        $this->assertNotContains((string) $archived->id, $ids);
    }

    /** 5 — another tenant's lead is not reachable, however the search is phrased. */
    public function test_a_cross_org_lead_is_excluded(): void
    {
        $orgA = $this->tenantId();
        $operator = $this->operator();
        $mine = $this->activeLead($operator, ['business_name' => 'Shared Trading Name']);

        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreign = CurrentOrg::runAs($orgB->id, fn (): Lead => Lead::factory()->create([
            'business_name' => 'Shared Trading Name',
            'source' => null,
            'qualified_at' => null,
        ]));

        $this->assertSame(
            $orgB->id,
            (int) DB::table('crm_leads')->where('id', $foreign->id)->value('org_id'),
            'The fixture must really belong to the other tenant.',
        );

        CurrentOrg::set($orgA);
        Sanctum::actingAs($operator);

        $ids = $this->idsFrom(
            $this->getJson(self::URL.'?scope=linkable&search=Shared+Trading+Name')->assertStatus(200)->json(),
        );

        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $foreign->id, $ids);
    }

    // -------------------------------------------------------- AUTHORIZATION

    /**
     * 6 — each of the four abilities is individually required.
     *
     * Written as a loop rather than one "missing permission" case because dropping any single one
     * must fail: it is the combination that establishes authority, and a future edit that quietly
     * removes one from the list should break here.
     */
    public function test_every_required_ability_is_individually_necessary(): void
    {
        $this->tenantId();

        foreach (ConversationLinkCapability::ABILITIES as $withheld) {
            $abilities = array_values(array_diff(ConversationLinkCapability::ABILITIES, [$withheld]));
            $user = $this->user($abilities);
            $this->activeLead($user);

            Sanctum::actingAs($user);

            $this->getJson(self::URL.'?scope=linkable')
                ->assertStatus(403, "Withholding [{$withheld}] must deny the picker.");
        }
    }

    /**
     * 7 — crm_bd, exactly as production has it today.
     *
     * Production's crm_bd holds `crm.leads.view` and `crm.leads.edit` but NOT
     * `crm.conversations.link_lead`, and NOT `crm.conversations.view` (the seeder grants the latter;
     * production has never been re-seeded). Either omission alone denies the picker — which is the
     * point: a lead-only gate would have let a BD enumerate link targets for an inbox they cannot
     * read.
     */
    public function test_the_production_crm_bd_permission_model_is_denied(): void
    {
        $this->tenantId();
        $bd = $this->user(['crm.leads.view', 'crm.leads.edit']);
        $this->activeLead($bd);

        Sanctum::actingAs($bd);

        $this->getJson(self::URL.'?scope=linkable')->assertStatus(403);
    }

    /**
     * 8 / 14 — THE SUPER_ADMIN TRAP.
     *
     * A super_admin holds NO `role_has_permissions` row for `crm.conversations.link_lead`; they are
     * authorized by `Gate::before(super_admin)` in CrmServiceProvider, which never touches the
     * permission tables. Any implementation that read `$user->permissions`, `hasDirectPermission()`
     * or Spatie's `getAllPermissions()` would answer FALSE and lock out the most privileged account
     * in the system — which is exactly what would happen if the FRONTEND made this decision from
     * the auth payload, and exactly why the capability is computed on the server.
     *
     * The first two assertions pin the trap itself, so this test still fails if someone "fixes" it
     * by granting the row instead of honouring the Gate.
     */
    public function test_a_super_admin_passes_through_gate_before_without_any_direct_grant(): void
    {
        $this->tenantId();
        $this->stripSuperAdminLinkGrant();
        $superAdmin = $this->user(abilities: [], superAdmin: true);
        $lead = $this->activeLead();

        $this->assertFalse(
            $superAdmin->hasDirectPermission('crm.conversations.link_lead'),
            'The fixture must hold no DIRECT grant, or this proves nothing.',
        );
        $this->assertNotContains(
            'crm.conversations.link_lead',
            $superAdmin->getAllPermissions()->pluck('name')->all(),
            'The role must hold no row either — the pass must come from Gate::before.',
        );

        Sanctum::actingAs($superAdmin);

        $ids = $this->idsFrom($this->getJson(self::URL.'?scope=linkable')->assertStatus(200)->json());
        $this->assertContains((string) $lead->id, $ids);
    }

    /** 9 — ownership narrows the candidate set for an operator with no admin role. */
    public function test_ownership_visibility_is_respected(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $colleague = User::factory()->create();

        $mine = $this->activeLead($operator);
        $theirs = $this->activeLead($colleague);
        $unassigned = $this->activeLead();

        Sanctum::actingAs($operator);

        $ids = $this->idsFrom($this->getJson(self::URL.'?scope=linkable')->assertStatus(200)->json());

        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $theirs->id, $ids, "A colleague's lead is not linkable by this operator.");
        $this->assertNotContains((string) $unassigned->id, $ids, 'An unowned lead fails isVisibleTo for a non-admin.');
    }

    /**
     * 10 / 15 (§15) — THE CENTRAL INVARIANT, asserted against the policy itself.
     *
     * Not "the query looks right" — every row is fed back through `LeadPolicy@update`, the exact
     * gate the live link endpoint enforces. If the picker ever widens past what the endpoint
     * accepts, this fails, whatever the query happens to say.
     *
     * The fixture is the restricted case the spec asks for: an operator who HAS `link_lead` but
     * owns only some of the org's leads.
     */
    public function test_every_returned_candidate_passes_the_link_policy(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $colleague = User::factory()->create();

        $this->activeLead($operator);
        $this->prospect($operator);
        $this->activeLead($colleague);
        $this->prospect($colleague);
        $this->activeLead();

        Sanctum::actingAs($operator);

        $ids = $this->idsFrom($this->getJson(self::URL.'?scope=linkable')->assertStatus(200)->json());

        $this->assertNotEmpty($ids, 'A vacuous pass would prove nothing.');

        $policy = app(LeadPolicy::class);

        foreach ($ids as $id) {
            $lead = Lead::query()->findOrFail($id);

            $this->assertTrue(
                $policy->update($operator, $lead),
                "The picker returned lead [{$id}], which LeadPolicy@update would refuse.",
            );
        }
    }

    // --------------------------------------------- ORDINARY MODE UNCHANGED

    /** 11 — no scope means active-only, exactly as before. */
    public function test_the_ordinary_list_remains_active_only(): void
    {
        $this->tenantId();
        $operator = $this->operator();

        $active = $this->activeLead($operator);
        $prospect = $this->prospect($operator);

        Sanctum::actingAs($operator);

        $ids = $this->idsFrom($this->getJson(self::URL)->assertStatus(200)->json());

        $this->assertContains((string) $active->id, $ids);
        $this->assertNotContains(
            (string) $prospect->id,
            $ids,
            'The Leads screen must keep excluding unqualified prospects.',
        );
    }

    /** 12 — and it still returns the full LeadResource, with its aggregate. */
    public function test_the_ordinary_list_still_returns_the_full_lead_resource(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $this->activeLead($operator, ['email' => 'ordinary@example.invalid', 'phone' => '+63 917 000 0002']);

        Sanctum::actingAs($operator);

        $json = $this->getJson(self::URL)->assertStatus(200)->json();

        $row = $json['data'][0];
        $this->assertArrayHasKey('email', $row);
        $this->assertArrayHasKey('phone', $row);
        $this->assertArrayHasKey('websiteStatus', $row);
        $this->assertArrayHasKey('assignedToId', $row);
        $this->assertArrayNotHasKey('isProspect', $row, 'LeadResource gained a field it should not have.');

        $this->assertArrayHasKey('industry_counts', $json, 'The niche chips depend on this aggregate.');
    }

    /**
     * 13 / 14 — page size, and the pre-existing `per_page` behaviour, both unchanged.
     *
     * `IndexLeadRequest` has never had a `per_page` rule, so `validated()` drops it and the
     * controller paginates at a hard-coded 10 — which means `assign-leads-modal.tsx` has been
     * silently receiving 10 rows while asking for 20. That is pre-existing debt and this slice
     * deliberately does NOT fix it; the test exists so the behaviour is stated rather than assumed,
     * and so a future fix is a conscious change rather than an accident.
     */
    public function test_pagination_is_unchanged_in_both_modes_and_per_page_is_ignored(): void
    {
        $this->tenantId();
        $operator = $this->operator();

        for ($i = 0; $i < 12; $i++) {
            $this->activeLead($operator);
        }

        Sanctum::actingAs($operator);

        $ordinary = $this->getJson(self::URL.'?per_page=50')->assertStatus(200)->json();
        $this->assertCount(10, $ordinary['data'], 'Ordinary mode must still ignore per_page and page at 10.');
        $this->assertSame(10, $ordinary['meta']['per_page']);
        $this->assertSame(12, $ordinary['meta']['total']);

        $picker = $this->getJson(self::URL.'?scope=linkable&per_page=50')->assertStatus(200)->json();
        $this->assertCount(10, $picker['data'], 'Linkable mode deliberately shares the same page size.');
        $this->assertSame(10, $picker['meta']['per_page']);
    }

    /** An unknown scope is a 422, not a silent fallback to the ordinary list. */
    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->tenantId();
        Sanctum::actingAs($this->operator());

        $this->getJson(self::URL.'?scope=everything')
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    // ------------------------------------------------------ PICKER PAYLOAD

    /** 15 / 16 — exactly five keys, and nothing sensitive among them. */
    public function test_the_picker_row_is_exactly_five_fields(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $this->activeLead($operator, [
            'email' => 'picker-private@example.invalid',
            'phone' => '+63 917 000 0003',
            'address' => '12 Hidden Road',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);

        Sanctum::actingAs($operator);

        $response = $this->getJson(self::URL.'?scope=linkable')->assertStatus(200);
        $row = $response->json('data.0');

        $this->assertSame(
            ['id', 'businessName', 'contactName', 'status', 'isProspect'],
            array_keys($row),
            'The picker row must be exactly the five agreed fields.',
        );

        $body = $response->getContent();

        foreach (self::FORBIDDEN_KEYS as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $body, "Picker response leaked the key [{$key}].");
        }

        $this->assertStringNotContainsString('picker-private@example.invalid', $body);
        $this->assertStringNotContainsString('917 000 0003', $body);
        $this->assertStringNotContainsString('12 Hidden Road', $body);
    }

    /** 22 / 23 — isProspect follows the model's own definition, not `status`. */
    public function test_is_prospect_reflects_the_model_definition(): void
    {
        $this->tenantId();
        $operator = $this->operator();

        // Same sales status on both, so the flag cannot be passing by coincidence.
        $prospect = $this->prospect($operator, ['status' => 'new']);
        $qualified = $this->activeLead($operator, ['status' => 'new', 'source' => 'nerby', 'qualified_at' => now()]);

        Sanctum::actingAs($operator);

        $rows = collect($this->getJson(self::URL.'?scope=linkable')->assertStatus(200)->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[(string) $prospect->id]['isProspect']);
        $this->assertFalse(
            $rows[(string) $qualified->id]['isProspect'],
            'A discovered lead that has been qualified is no longer a prospect.',
        );
        $this->assertSame($rows[(string) $prospect->id]['status'], $rows[(string) $qualified->id]['status']);
    }

    // ------------------------------------------------------------- SEARCH

    /**
     * 17–20 — all four search columns still match, and two of them are never returned.
     *
     * MATCHING IS NOT RETURNING, and that is the whole point of this test: an operator who reads a
     * phone number or an email address out of a chat message must be able to find the lead it
     * belongs to, without the response handing those values to anyone who can open the inbox.
     */
    public function test_search_matches_all_four_columns_without_returning_the_private_ones(): void
    {
        $this->tenantId();
        $operator = $this->operator();

        $lead = $this->prospect($operator, [
            'business_name' => 'Zamboanga Marine Supplies',
            'contact_name' => 'Lorenzo Batumbakal',
            'email' => 'unique-needle@example.invalid',
            'phone' => '+63 917 555 8123',
        ]);
        $this->activeLead($operator, ['business_name' => 'Unrelated Co']);

        Sanctum::actingAs($operator);

        foreach ([
            'business_name' => 'Zamboanga Marine',
            'contact_name' => 'Batumbakal',
            'email' => 'unique-needle@example.invalid',
            'phone' => '917 555 8123',
        ] as $column => $term) {
            $response = $this->getJson(self::URL.'?scope=linkable&search='.urlencode($term))->assertStatus(200);

            $this->assertSame(
                [(string) $lead->id],
                $this->idsFrom($response->json()),
                "Searching by {$column} must find exactly that lead.",
            );

            $this->assertStringNotContainsString('unique-needle@example.invalid', $response->getContent());
            $this->assertStringNotContainsString('555 8123', $response->getContent());
        }
    }

    /** 21 — the envelope a paginated collection always carries. */
    public function test_the_pagination_envelope_is_intact(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $this->activeLead($operator);

        Sanctum::actingAs($operator);

        $this->getJson(self::URL.'?scope=linkable')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'businessName', 'contactName', 'status', 'isProspect']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    // --------------------------------------------------------- CAPABILITY

    private function conversation(?int $leadId = null): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'psid_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subHour(),
            'last_message_at' => now(),
        ]);

        if ($leadId !== null) {
            $conversation->lead_id = $leadId;
            $conversation->save();
        }

        return $conversation->refresh();
    }

    private function capability(Conversation $conversation): array
    {
        return $this->getJson('/api/v1/crm/conversations/'.$conversation->id)
            ->assertStatus(200)
            ->json('data.link');
    }

    /** 24 — an authorized operator, an unlinked thread. */
    public function test_the_capability_is_enabled_for_an_authorized_operator_on_an_unlinked_thread(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $conversation = $this->conversation();

        Sanctum::actingAs($operator);

        $this->assertSame(['enabled' => true, 'reason' => null], $this->capability($conversation));
    }

    /** 25 — and for a super_admin, through Gate::before, with no grant of any kind. */
    public function test_the_capability_is_enabled_for_a_super_admin_through_gate_before(): void
    {
        $this->tenantId();
        $this->stripSuperAdminLinkGrant();
        $superAdmin = $this->user(abilities: [], superAdmin: true);
        $conversation = $this->conversation();

        $this->assertNotContains(
            'crm.conversations.link_lead',
            $superAdmin->getAllPermissions()->pluck('name')->all(),
        );

        Sanctum::actingAs($superAdmin);

        $this->assertSame(['enabled' => true, 'reason' => null], $this->capability($conversation));
    }

    /** 26 — a viewer who may read the inbox but not link is told which it is. */
    public function test_the_capability_is_permission_denied_for_an_unauthorized_viewer(): void
    {
        $this->tenantId();
        $viewer = $this->user(['crm.conversations.view']);
        $conversation = $this->conversation();

        Sanctum::actingAs($viewer);

        $this->assertSame(
            ['enabled' => false, 'reason' => ConversationLinkCapability::PERMISSION_DENIED],
            $this->capability($conversation),
        );
    }

    /** 27 — a linked thread offers nothing, because 3H.3A ships LINK only. */
    public function test_the_capability_is_already_linked_when_the_thread_names_a_lead(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $lead = $this->activeLead($operator);
        $conversation = $this->conversation($lead->id);

        Sanctum::actingAs($operator);

        $this->assertSame(
            ['enabled' => false, 'reason' => ConversationLinkCapability::ALREADY_LINKED],
            $this->capability($conversation),
        );
    }

    /**
     * 28 — THE STATE MOST LIKELY TO BE GOT WRONG.
     *
     * `lead_id` is set but the lead is archived, so `lead` serializes as null while `leadLinked`
     * stays true. A capability computed from the `lead` relation would call this thread unlinked
     * and offer a Link button the server answers with a 409. It must read `lead_id`.
     */
    public function test_an_archived_linked_lead_is_still_already_linked(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $lead = $this->activeLead($operator);
        $conversation = $this->conversation($lead->id);

        $lead->delete();
        $this->assertSoftDeleted('crm_leads', ['id' => $lead->id]);

        Sanctum::actingAs($operator);

        $detail = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertStatus(200);

        $detail->assertJsonPath('data.leadLinked', true);
        $detail->assertJsonPath('data.lead', null);
        $detail->assertJsonPath('data.link.enabled', false);
        $detail->assertJsonPath('data.link.reason', ConversationLinkCapability::ALREADY_LINKED);
    }

    /**
     * 29 — the capability performs NO database work.
     *
     * A correctness property, not an optimisation: a capability that resolved `$conversation->lead`
     * would return null for an archived lead and could be mistaken for "unlinked". Counting queries
     * is how that stays true after a refactor.
     */
    public function test_the_capability_issues_no_queries(): void
    {
        $this->tenantId();
        $operator = $this->operator();
        $lead = $this->activeLead($operator);
        $conversation = $this->conversation($lead->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = app(ConversationLinkCapability::class)->for($conversation, $operator);

        $this->assertSame(0, $queries, 'The link capability must not touch the database.');
        $this->assertSame(ConversationLinkCapability::ALREADY_LINKED, $result['reason']);
    }

    /** 30 — the reply capability is untouched by any of this. */
    public function test_the_reply_capability_is_unchanged(): void
    {
        $this->tenantId();
        config(['meta.outbound.enabled' => false]);

        $operator = $this->user([...ConversationLinkCapability::ABILITIES, 'crm.conversations.reply']);
        $conversation = $this->conversation();

        Sanctum::actingAs($operator);

        $detail = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertStatus(200);

        // Outbound is disabled, mirroring production — so the reason is the deployment state, and
        // adding `link` beside it changed nothing about how it is computed.
        $detail->assertJsonPath('data.reply.enabled', false);
        $detail->assertJsonPath('data.reply.reason', 'outbound_disabled');
        $detail->assertJsonPath('data.link.enabled', true);

        Http::assertNothingSent();
    }
}
