<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\SetCurrentOrg;
use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Niche;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * THE TENANT IS PINNED BEFORE ROUTE-MODEL BINDING RESOLVES `{model}`.
 *
 * Commit 45bc5e50 put SetCurrentOrg ahead of SubstituteBindings in the middleware priority list.
 * Before it, `set-current-org` sat after bindings, so a `{lead}` / `{conversation}` / `{discoveryRun}`
 * was looked up under whatever org was pinned BEFORE the request — nothing for the NERBY service
 * user (404 on every lead-bound callback), and for a person the org their membership resolves to on
 * demand, which happened to be right. This suite pins the order so it cannot quietly regress:
 *
 *  - structurally, on one bound route per module;
 *  - behaviourally, with a STALE FOREIGN PIN left in the process (what a long-lived worker such as
 *    Octane, or a test, can leave behind): the request must re-pin to the caller's own org before it
 *    binds, so the caller's record resolves and the other org's record is a 404, never a leak;
 *  - for crm_admin, crm_bd, the orgless super_admin, an orgless ordinary user, and service tokens.
 *
 * Every test here passes with the current order; scenarios marked ORDER-SENSITIVE fail if
 * SetCurrentOrg is moved back after SubstituteBindings.
 */
class TheTenantIsPinnedBeforeBindingTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function otherOrg(string $name = 'Kliyente Dos Inc'): Org
    {
        $org = Org::create(['name' => $name, 'is_active' => true]);
        Entitlements::enable($org->id, ['crm']);
        Subscription::create([
            'org_id' => $org->id, 'plan' => 'full', 'monthly_price' => 0, 'seat_limit' => 50,
            'status' => Subscription::ACTIVE, 'current_period_end' => now()->addYear(),
        ]);

        return $org;
    }

    /** A person with exactly one org (org_user.user_id is unique: one membership per account). */
    private function member(string $role, Org $org): User
    {
        $user = User::factory()->orgless()->create(['status' => 'active']);
        $user->assignRole($role);
        $org->users()->attach($user->id, ['is_default' => true]);

        return $user;
    }

    private function leadIn(Org $org, User $creator, array $attributes = []): Lead
    {
        return CurrentOrg::runAs($org->id, fn (): Lead => Lead::factory()->create([
            'created_by' => $creator->id, 'assigned_to' => $creator->id, ...$attributes,
        ]));
    }

    /** Act as $user with the process pinned to a DIFFERENT org — the stale pin the middleware must replace. */
    private function actAsWithStalePin(User $user, Org $stale): void
    {
        $this->app['auth']->forgetGuards();
        CurrentOrg::clear();
        CurrentOrg::set($stale->id);
        Sanctum::actingAs($user);
    }

    // ── structure ────────────────────────────────────────────────────────────────────────────

    public function test_set_current_org_runs_after_authentication_and_before_binding_on_every_module(): void
    {
        $router = app('router');

        foreach ([
            ['GET', '/api/v1/crm/leads/1'],
            ['GET', '/api/v1/crm/conversations/1'],
            ['GET', '/api/v1/crm/discovery/runs/'.Str::uuid()],
            ['PUT', '/api/v1/crm/leads/1/website-enrichment'],
            ['GET', '/api/v1/hr/me/payslips/1'],
            ['GET', '/api/v1/accounting/invoices/1'],
        ] as [$method, $uri]) {
            $route = $router->getRoutes()->match(Request::create($uri, $method));
            $names = array_map(
                static fn ($m): string => is_string($m) ? explode(':', $m, 2)[0] : get_class($m),
                $router->gatherRouteMiddleware($route),
            );

            $auth = array_search(Authenticate::class, $names, true);
            $pin = array_search(SetCurrentOrg::class, $names, true);
            $bind = array_search(SubstituteBindings::class, $names, true);

            $this->assertNotFalse($pin, "{$method} {$uri}: set-current-org missing");
            $this->assertNotFalse($bind, "{$method} {$uri}: bindings missing");
            $this->assertNotFalse($auth, "{$method} {$uri}: auth missing");
            $this->assertLessThan($pin, $auth, "{$method} {$uri}: the org is pinned before the user is known");
            $this->assertLessThan($bind, $pin, "{$method} {$uri}: {model} binds before the org is pinned");
        }
    }

    // ── people, with a stale foreign pin (ORDER-SENSITIVE) ───────────────────────────────────

    public function test_a_crm_admin_reaches_their_own_lead_and_never_the_pinned_orgs(): void
    {
        $other = $this->otherOrg();
        $adminA = $this->member('crm_admin', $this->tenant());
        $adminB = $this->member('crm_admin', $other);
        $own = $this->leadIn($this->tenant(), $adminA, ['business_name' => 'Own Lead']);
        $foreign = $this->leadIn($other, $adminB, ['business_name' => 'Foreign Lead']);

        $this->actAsWithStalePin($adminA, $other);
        $this->getJson("/api/v1/crm/leads/{$own->id}")->assertOk()->assertJsonPath('data.business', 'Own Lead');

        $this->actAsWithStalePin($adminA, $other);
        $this->getJson("/api/v1/crm/leads/{$foreign->id}")->assertNotFound();
    }

    public function test_a_crm_bd_reaches_their_own_lead_and_never_the_pinned_orgs(): void
    {
        $other = $this->otherOrg();
        $bdA = $this->member('crm_bd', $this->tenant());
        $bdB = $this->member('crm_bd', $other);
        $own = $this->leadIn($this->tenant(), $bdA);
        $foreign = $this->leadIn($other, $bdB);

        $this->actAsWithStalePin($bdA, $other);
        $this->getJson("/api/v1/crm/leads/{$own->id}")->assertOk();

        $this->actAsWithStalePin($bdA, $other);
        $this->getJson("/api/v1/crm/leads/{$foreign->id}")->assertNotFound();
    }

    public function test_conversations_bind_in_the_callers_org(): void
    {
        $other = $this->otherOrg();
        $adminA = $this->member('crm_admin', $this->tenant());
        $own = CurrentOrg::runAs($this->tenant()->id, fn () => Conversation::factory()->create());
        $foreign = CurrentOrg::runAs($other->id, fn () => Conversation::factory()->create());

        $this->actAsWithStalePin($adminA, $other);
        $this->getJson("/api/v1/crm/conversations/{$own->id}")->assertOk();

        $this->actAsWithStalePin($adminA, $other);
        $this->getJson("/api/v1/crm/conversations/{$foreign->id}")->assertNotFound();
    }

    public function test_discovery_runs_bind_in_the_callers_org(): void
    {
        $other = $this->otherOrg();
        $adminA = $this->member('crm_admin', $this->tenant());
        $adminB = $this->member('crm_admin', $other);
        $run = fn (Org $org, User $by) => CurrentOrg::runAs($org->id, fn () => DiscoveryRun::query()->create([
            'uuid' => (string) Str::uuid(), 'requested_by' => $by->id,
            'scope_class' => DiscoveryRun::SCOPE_NATIONWIDE, 'status' => DiscoveryRun::STATUS_RUNNING,
            'scope' => ['niches' => ['bakery']],
        ]));
        $own = $run($this->tenant(), $adminA);
        $foreign = $run($other, $adminB);

        $this->actAsWithStalePin($adminA, $other);
        $this->getJson("/api/v1/crm/discovery/runs/{$own->uuid}")->assertOk();

        $this->actAsWithStalePin($adminA, $other);
        $this->getJson("/api/v1/crm/discovery/runs/{$foreign->uuid}")->assertNotFound();
    }

    public function test_settings_records_bind_in_the_callers_org(): void
    {
        $other = $this->otherOrg();
        $adminA = $this->member('crm_admin', $this->tenant());
        $own = CurrentOrg::runAs($this->tenant()->id, fn () => Niche::create(['name' => 'Own Niche', 'is_custom' => true]));
        $foreign = CurrentOrg::runAs($other->id, fn () => Niche::create(['name' => 'Foreign Niche', 'is_custom' => true]));

        // Own record binds (validation or success, never "not found"); the other org's never does.
        $this->actAsWithStalePin($adminA, $other);
        $this->assertContains($this->patchJson("/api/v1/crm/niches/{$own->id}", ['name' => 'Own Niche Renamed'])->status(), [200, 422]);

        $this->actAsWithStalePin($adminA, $other);
        $this->patchJson("/api/v1/crm/niches/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Foreign Niche', CurrentOrg::runAs($other->id, fn () => Niche::query()->find($foreign->id)->name));
    }

    // ── service tokens (ORDER-SENSITIVE) ─────────────────────────────────────────────────────

    public function test_a_service_token_binds_in_its_own_org_whatever_was_pinned_before(): void
    {
        $other = $this->otherOrg();
        $creatorA = $this->member('crm_admin', $this->tenant());
        $creatorB = $this->member('crm_admin', $other);
        $tenantLead = $this->leadIn($this->tenant(), $creatorA, ['website' => null, 'qualified_at' => null]);
        $otherLead = $this->leadIn($other, $creatorB, ['website' => null, 'qualified_at' => null]);
        $body = ['candidates' => [], 'tiers_run' => ['probe']];

        // Bound to the OTHER org, with the process still pinned to the tenant.
        $this->actingAsNerbyService($other->id);
        CurrentOrg::set($this->tenant()->id);
        $this->postJson("/api/v1/crm/leads/{$otherLead->id}/website-candidates", $body)->assertOk();

        CurrentOrg::set($this->tenant()->id);
        $this->postJson("/api/v1/crm/leads/{$tenantLead->id}/website-candidates", $body)->assertNotFound();
    }

    /** The exact production failure 45bc5e50 fixed: the service user has no org, and nothing is pinned yet. */
    public function test_a_service_token_reaches_its_own_orgs_lead_with_nothing_pinned(): void
    {
        $creator = $this->member('crm_admin', $this->tenant());
        $lead = $this->leadIn($this->tenant(), $creator, ['website' => null, 'qualified_at' => null]);

        $this->actingAsNerbyService($this->tenant()->id);
        CurrentOrg::clear();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", ['candidates' => [], 'tiers_run' => ['probe']])
            ->assertOk();
    }

    // ── super_admin, orgless users, invalid orgs ─────────────────────────────────────────────

    public function test_an_orgless_super_admin_works_in_the_provider_org_and_is_fenced_from_others(): void
    {
        $other = $this->otherOrg();
        $super = User::factory()->orgless()->create(['status' => 'active']);
        $super->assignRole('super_admin');
        $creator = $this->member('crm_admin', $this->tenant());
        $creatorB = $this->member('crm_admin', $other);
        $providerLead = $this->leadIn($this->tenant(), $creator);
        $foreignLead = $this->leadIn($other, $creatorB);

        // The provider org is the tenant (lowest id): a super_admin with no membership reads it.
        $this->actAsWithStalePin($super, $other);
        CurrentOrg::clear();
        $this->getJson("/api/v1/crm/leads/{$providerLead->id}")->assertOk();

        // Another customer's lead is not reachable through the tenant-scoped CRM routes…
        $this->actAsWithStalePin($super, $other);
        CurrentOrg::clear();
        $this->getJson("/api/v1/crm/leads/{$foreignLead->id}")->assertNotFound();

        // …while the platform admin surface, which binds the unscoped Org, still reaches it.
        $this->actAsWithStalePin($super, $this->tenant());
        $this->getJson("/api/v1/admin/orgs/{$other->id}/features")->assertOk();

        // And that admin surface is super_admin only.
        $this->actAsWithStalePin($creator, $this->tenant());
        $this->getJson("/api/v1/admin/orgs/{$other->id}/features")->assertForbidden();
    }

    public function test_a_person_with_no_org_reaches_no_tenant_record(): void
    {
        $creator = $this->member('crm_admin', $this->tenant());
        $lead = $this->leadIn($this->tenant(), $creator);
        $loner = User::factory()->orgless()->create(['status' => 'active']);
        $loner->assignRole('crm_admin');

        $this->actAsWithStalePin($loner, $this->tenant());
        CurrentOrg::clear();
        $status = $this->getJson("/api/v1/crm/leads/{$lead->id}")->status();

        $this->assertContains($status, [402, 403, 404], "an orgless user got {$status} on a tenant's lead");
    }

    public function test_a_service_token_bound_to_a_nonexistent_org_reaches_nothing(): void
    {
        $creator = $this->member('crm_admin', $this->tenant());
        $lead = $this->leadIn($this->tenant(), $creator, ['website' => null, 'qualified_at' => null]);
        $service = app(\App\Modules\Crm\Services\Discovery\NerbyServiceIdentity::class);
        $plain = $service->user()->createToken('nerby:test', [\App\Support\ServiceTokens::CALLBACK_ABILITY, 'org:999999'], now()->addHour())->plainTextToken;

        $this->app['auth']->forgetGuards();
        CurrentOrg::clear();
        $status = $this->withToken($plain)
            ->postJson("/api/v1/crm/leads/{$lead->id}/website-candidates", ['candidates' => [], 'tiers_run' => ['probe']])
            ->status();

        // The lead is looked up in org 999999, where nothing exists: not found, never the tenant's lead.
        $this->assertSame(404, $status);
    }
}
