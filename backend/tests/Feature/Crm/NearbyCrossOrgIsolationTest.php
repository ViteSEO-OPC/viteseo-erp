<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Nearby board is a SHARED pool — and sharing has a fence.
 *
 * {@see NearbyClaimedVisibilityTest} and {@see NearbyLeadsOwnershipBoundaryTest} arrived from the
 * single-tenant Zerem line, so both pin the half that says "every BD sees the same list". Neither
 * says WHICH BDs, because when they were written there was only ever one answer. Under tenancy
 * that half is now the dangerous one: `NearbyClientController::index()` runs a bare
 * `Lead::query()->discovered()` with no `where('org_id', …)` anywhere in the controller, and the
 * only thing standing between one customer's prospect list and another's is the BelongsToOrg
 * global scope.
 *
 * That is a correct design, but it is invisible at the call site. Anyone who later reaches for
 * `withoutGlobalScope('org')` to "fix" a missing row, or who rewrites the query as a join or a raw
 * select, removes the fence without touching a line that mentions tenancy. These tests fail loudly
 * when that happens.
 *
 * The leak matters in both directions. Reading another org's board exposes an entire prospecting
 * pipeline — who they are chasing and who on their team took each one. Writing across it is worse:
 * qualifying a foreign prospect would plant an owner from the wrong company on another tenant's
 * lead. So the claim path is pinned here too, not just the listing path.
 */
class NearbyCrossOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const NEARBY = '/api/v1/crm/nearby-clients';

    /**
     * An org with the CRM availed, plus a BD inside it — the only role besides Super Admin that
     * the Nearby route admits.
     *
     * @return array{Org, User}
     */
    private function orgWithBd(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        // `orgless()` matters: UserFactory otherwise auto-attaches every generated user to the
        // LOWEST org id (the base tenant Tests\TestCase pins), and `org_user.user_id` is uniquely
        // indexed, so a plain create() followed by this attach dies on the unique index rather
        // than placing the BD in the org the test means.
        $bd = User::factory()->orgless()->create(['status' => 'active', 'name' => "BD {$slug}"]);
        $bd->assignRole('crm_bd');
        $org->users()->attach($bd->id, ['is_default' => true]);

        return [$org, $bd];
    }

    /** A discovered prospect owned by no one, created inside $org. */
    private function prospectIn(Org $org, string $name, User $creator): Lead
    {
        return CurrentOrg::runAs($org->id, fn (): Lead => Lead::factory()->create([
            'assigned_to' => null,
            'qualified_at' => null,
            'qualified_by' => null,
            'source' => 'nearby',
            'business_name' => $name,
            'created_by' => $creator->id,
        ]));
    }

    /**
     * Authenticate as $actor for a REAL request.
     *
     * The `CurrentOrg::clear()` is load-bearing, not hygiene. Tests\TestCase::setUp pins the base
     * tenant as an explicit override so that code touched outside a request has a tenant at all.
     * That override outranks everything in CurrentOrg::id() — including the org SetCurrentOrg is
     * about to resolve from this user. Route-model binding runs in the `api` group's
     * SubstituteBindings, BEFORE route middleware, so `{lead}` would be looked up against the
     * pinned base tenant rather than the caller's org.
     *
     * Leaving it pinned does not merely break the positive cases: it makes the NEGATIVE ones pass
     * for the wrong reason. A cross-org claim would 404 because the harness was pointed at a third
     * org, not because tenancy refused it — a green test pinning nothing at all. Clearing the
     * override puts the request back on the production path, where the org is resolved from the
     * authenticated user and the 404 is the fence doing its job.
     */
    private function actAs(User $actor): void
    {
        CurrentOrg::clear();
        Sanctum::actingAs($actor);
    }

    /** The business names on the Nearby board as $actor sees it. */
    private function boardNamesFor(User $actor): array
    {
        $this->actAs($actor);

        return collect($this->getJson(self::NEARBY)->assertOk()->json('data'))
            ->pluck('business')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ── Reading across the fence ────────────────────────────────────────────────────────────────

    public function test_the_board_shows_the_callers_own_orgs_pool(): void
    {
        // The positive control. Without it a total failure to return anything would pass every
        // isolation assertion below.
        [$ourOrg, $ourBd] = $this->orgWithBd('alpha');
        $this->prospectIn($ourOrg, 'Alpha Bakery', $ourBd);

        $this->assertContains('Alpha Bakery', $this->boardNamesFor($ourBd));
    }

    public function test_another_orgs_discovered_prospect_is_absent_from_the_board(): void
    {
        [$ourOrg, $ourBd] = $this->orgWithBd('alpha');
        [$theirOrg, $theirBd] = $this->orgWithBd('beta');

        $this->prospectIn($ourOrg, 'Alpha Bakery', $ourBd);
        $this->prospectIn($theirOrg, 'Beta Dental', $theirBd);

        $names = $this->boardNamesFor($ourBd);

        $this->assertContains('Alpha Bakery', $names);
        $this->assertNotContains('Beta Dental', $names, "Alpha read Beta's prospect pool.");
    }

    public function test_the_fence_holds_in_the_other_direction_too(): void
    {
        // Not symmetric by construction: the scope resolves per authenticated user, so a bug that
        // pins one org for the whole process would pass the test above and fail this one.
        [$ourOrg, $ourBd] = $this->orgWithBd('alpha');
        [$theirOrg, $theirBd] = $this->orgWithBd('beta');

        $this->prospectIn($ourOrg, 'Alpha Bakery', $ourBd);
        $this->prospectIn($theirOrg, 'Beta Dental', $theirBd);

        $names = $this->boardNamesFor($theirBd);

        $this->assertContains('Beta Dental', $names);
        $this->assertNotContains('Alpha Bakery', $names, "Beta read Alpha's prospect pool.");
    }

    public function test_a_claimed_prospect_does_not_leak_its_claimant_across_orgs(): void
    {
        // The claimed-visibility widening (a298bf02) is what puts a colleague's NAME on the board.
        // Within an org that is the point; across orgs it would name another company's staff.
        [$ourOrg, $ourBd] = $this->orgWithBd('alpha');
        [$theirOrg, $theirBd] = $this->orgWithBd('beta');

        $this->prospectIn($ourOrg, 'Alpha Bakery', $ourBd);
        $theirs = $this->prospectIn($theirOrg, 'Beta Dental', $theirBd);

        $this->actAs($theirBd);
        $this->postJson("/api/v1/crm/leads/{$theirs->id}/qualify")->assertCreated();

        $this->actAs($ourBd);
        $rows = $this->getJson(self::NEARBY)->assertOk()->json('data');

        $this->assertNotContains('Beta Dental', collect($rows)->pluck('business')->all());

        // The claimant's name must not appear anywhere in the payload, under any key.
        $this->assertStringNotContainsString(
            $theirBd->name,
            json_encode($rows),
            "Another tenant's BD was named on Alpha's board.",
        );
    }

    // ── Writing across the fence ────────────────────────────────────────────────────────────────

    public function test_a_bd_cannot_claim_another_orgs_prospect(): void
    {
        [, $ourBd] = $this->orgWithBd('alpha');
        [$theirOrg, $theirBd] = $this->orgWithBd('beta');

        $theirs = $this->prospectIn($theirOrg, 'Beta Dental', $theirBd);

        $this->actAs($ourBd);
        // Route-model binding runs through the global scope, so the row is not merely forbidden —
        // it does not resolve at all. 404 rather than 403 is the correct answer: a 403 would
        // confirm the lead exists to a caller who should not know that.
        $this->postJson("/api/v1/crm/leads/{$theirs->id}/qualify")->assertNotFound();

        $fresh = CurrentOrg::runAs($theirOrg->id, fn (): Lead => $theirs->fresh());

        $this->assertNull($fresh->qualified_by, "A foreign BD claimed another tenant's prospect.");
        $this->assertNull($fresh->assigned_to);
        $this->assertNull($fresh->qualified_at);
    }

    public function test_a_foreign_claim_attempt_does_not_forge_a_lead_in_the_callers_org(): void
    {
        // The failure mode a plain 404 assertion would miss: the request is refused for the target
        // org but a stamped copy lands in the caller's, because `creating` auto-stamps org_id.
        [$ourOrg, $ourBd] = $this->orgWithBd('alpha');
        [$theirOrg, $theirBd] = $this->orgWithBd('beta');

        $theirs = $this->prospectIn($theirOrg, 'Beta Dental', $theirBd);

        $this->actAs($ourBd);
        $this->postJson("/api/v1/crm/leads/{$theirs->id}/qualify")->assertNotFound();

        $ourCount = CurrentOrg::runAs($ourOrg->id, fn (): int => Lead::query()->count());

        $this->assertSame(0, $ourCount, 'A refused cross-org claim still created a lead locally.');
    }
}
