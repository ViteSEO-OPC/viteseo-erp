<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two-surface boundary: Nearby is SHARED, the Leads table is OWNED.
 *
 * These are the same rows in one table, read through two deliberately different lenses, and the
 * difference is load-bearing product behaviour rather than an implementation detail:
 *
 *  - `GET /nearby-clients` applies NO visibility scope. Every BD and Super Admin sees the identical
 *    discovered pool, because a prospecting board that showed each BD a different map would make
 *    "has anyone taken this yet?" unanswerable. A claimed business stays listed, marked, and locked.
 *  - `GET /leads` applies `active()->visibleTo()`. A BD sees only what they own, so a colleague's
 *    claimed lead is not merely hidden from their board — it is absent from their pipeline, their
 *    counts, and their exports.
 *
 * The risk this class exists to pin is that those two rules are enforced in different places
 * (a controller query vs a model scope + policy) and could drift apart in either direction: a
 * `visibleTo()` added to the Nearby query would silently fragment the shared pool, and a dropped
 * `visibleTo()` in the lead list would silently leak another BD's pipeline. Both regressions would
 * pass every existing per-surface test, because each surface would still be internally consistent.
 *
 * Adjacent coverage that is deliberately NOT restated here: the claim race and its 409
 * (LeadQualificationTest), the claimed-state chip and claimant name (NearbyClaimedVisibilityTest),
 * and role access to the board itself, including Admin's 403 (NearbyClientsAuthorizationTest).
 */
class NearbyLeadsOwnershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const NEARBY = '/api/v1/crm/nearby-clients';

    private const LEADS = '/api/v1/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` to an existing user and falls back to id 1, which would
        // violate the FK with no users present. This one owns nothing and affects no assertion.
        User::factory()->create(['status' => 'active']);
    }

    private function bd(string $name): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole('crm_bd');

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Super Admin']);
        $user->assignRole('super_admin');

        return $user;
    }

    /** An unclaimed discovered business — the shared Prospect Pool state. */
    private function prospect(string $name): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => null,
            'qualified_at' => null,
            'qualified_by' => null,
            'source' => 'nearby',
            'business_name' => $name,
        ]);
    }

    /** Claim through the REAL endpoint, so the test exercises the path production uses. */
    private function claim(User $claimant, Lead $lead): void
    {
        Sanctum::actingAs($claimant);
        $this->postJson(self::LEADS."/{$lead->id}/qualify")->assertCreated();
    }

    /** @return list<string> business names on the Nearby board, as the acting user sees it. */
    private function boardNames(): array
    {
        return collect($this->getJson(self::NEARBY)->assertOk()->json('data'))
            ->pluck('business')
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> business names in the Leads table, as the acting user sees it. */
    private function leadNames(): array
    {
        return collect($this->getJson(self::LEADS)->assertOk()->json('data'))
            ->pluck('business')
            ->sort()
            ->values()
            ->all();
    }

    // ── Nearby: one shared pool ─────────────────────────────────────────────────────────────────

    public function test_a_bd_and_a_super_admin_receive_the_identical_discovered_pool(): void
    {
        $bd = $this->bd('Rea Villanueva');
        $other = $this->bd('Jhamire Aranda');
        $this->prospect('Alpha Pharmacy');
        $this->prospect('Beta Dental');
        $claimed = $this->prospect('Gamma Clinic');
        $this->claim($other, $claimed);

        Sanctum::actingAs($bd);
        $asBd = $this->boardNames();

        Sanctum::actingAs($this->superAdmin());
        $asSuperAdmin = $this->boardNames();

        $this->assertSame(
            ['Alpha Pharmacy', 'Beta Dental', 'Gamma Clinic'],
            $asBd,
            'a BD did not receive the whole shared pool'
        );
        $this->assertSame($asBd, $asSuperAdmin, 'the discovery pool differed between a BD and Super Admin');
    }

    /**
     * THE INVARIANT AT VOLUME. The three-row cases above prove the rule; this one proves it does not
     * quietly acquire a per-user dimension as the pool grows — which is exactly what the
     * Luzon/Visayas/Mindanao expansion does to it. If the scraper has landed N valid prospects, every
     * authorized reader receives N, and the same N: no partitioning, no per-BD sampling, no cap that
     * happens to bite only once the pool is large.
     *
     * Claims are scattered through the set deliberately: ownership must change the Accept state and
     * the Leads table, never the size or membership of the shared board.
     */
    public function test_every_authorized_role_receives_the_whole_pool_at_volume(): void
    {
        $one = $this->bd('Rea Villanueva');
        $two = $this->bd('Jhamire Aranda');
        $three = $this->bd('Divine Lacsamana');

        $pool = [];
        foreach (['Makati', 'Cebu', 'Davao'] as $cityIndex => $city) {
            foreach (range(1, 10) as $n) {
                $pool[] = $this->prospect("{$city} Business {$n}");
            }
        }
        // A realistic mix of claimed and unclaimed, spread across the pool and across claimants.
        foreach ([0, 7, 14, 21, 29] as $offset) {
            $this->claim($offset % 2 === 0 ? $one : $two, $pool[$offset]);
        }

        Sanctum::actingAs($one);
        $asOne = $this->boardNames();
        Sanctum::actingAs($two);
        $asTwo = $this->boardNames();
        Sanctum::actingAs($three);
        $asThree = $this->boardNames();
        Sanctum::actingAs($this->superAdmin());
        $asSuperAdmin = $this->boardNames();

        $this->assertCount(30, $asOne, 'a BD did not receive every discovered prospect');
        $this->assertSame($asOne, $asTwo, 'two BDs received different pools');
        $this->assertSame($asOne, $asThree, 'a BD with no claims received a different pool');
        $this->assertSame($asOne, $asSuperAdmin, 'Super Admin and BD pools diverged');
        $this->assertSame(
            Lead::query()->discovered()->count(),
            count($asOne),
            'the board returned fewer rows than the discovered pool holds'
        );
    }

    public function test_claims_scattered_through_a_large_pool_only_move_the_leads_table(): void
    {
        $one = $this->bd('Rea Villanueva');
        $two = $this->bd('Jhamire Aranda');
        $pool = [];
        foreach (range(1, 12) as $n) {
            $pool[] = $this->prospect("Business {$n}");
        }
        $this->claim($one, $pool[2]);
        $this->claim($one, $pool[5]);
        $this->claim($two, $pool[9]);

        Sanctum::actingAs($one);
        $this->assertCount(12, $this->boardNames(), 'the board shrank for the claimant');
        $this->assertSame(['Business 3', 'Business 6'], $this->leadNames());

        Sanctum::actingAs($two);
        $this->assertCount(12, $this->boardNames(), 'the board shrank for the other BD');
        $this->assertSame(['Business 10'], $this->leadNames());

        Sanctum::actingAs($this->superAdmin());
        $this->assertCount(12, $this->boardNames());
        $this->assertSame(['Business 10', 'Business 3', 'Business 6'], $this->leadNames());
    }

    public function test_the_pool_is_identical_for_two_different_bds(): void
    {
        $one = $this->bd('Rea Villanueva');
        $two = $this->bd('Divine Lacsamana');
        $this->prospect('Alpha Pharmacy');
        $this->claim($one, $this->prospect('Beta Dental'));

        Sanctum::actingAs($one);
        $asOne = $this->boardNames();
        Sanctum::actingAs($two);
        $asTwo = $this->boardNames();

        $this->assertSame($asOne, $asTwo, 'two BDs received different prospect pools');
        $this->assertContains('Beta Dental', $asTwo, 'the claimed business vanished for the other BD');
    }

    // ── Leads: ownership only ───────────────────────────────────────────────────────────────────

    public function test_a_lead_claimed_by_another_bd_is_absent_from_the_leads_table(): void
    {
        $claimant = $this->bd('Rea Villanueva');
        $observer = $this->bd('Jhamire Aranda');
        $lead = $this->prospect('Gamma Clinic');
        $this->claim($claimant, $lead);

        Sanctum::actingAs($observer);

        $this->assertSame([], $this->leadNames(), "another BD's claimed lead appeared in the Leads table");
        $this->assertContains('Gamma Clinic', $this->boardNames(), 'the shared board must still show it');
    }

    public function test_the_claimant_does_see_their_own_claimed_lead(): void
    {
        $claimant = $this->bd('Rea Villanueva');
        $lead = $this->prospect('Gamma Clinic');
        $this->claim($claimant, $lead);

        Sanctum::actingAs($claimant);

        $this->assertSame(['Gamma Clinic'], $this->leadNames());
    }

    public function test_a_bd_cannot_read_another_bds_claimed_lead_directly(): void
    {
        $claimant = $this->bd('Rea Villanueva');
        $observer = $this->bd('Jhamire Aranda');
        $lead = $this->prospect('Gamma Clinic');
        $this->claim($claimant, $lead);

        Sanctum::actingAs($observer);

        $this->getJson(self::LEADS."/{$lead->id}")->assertForbidden();
    }

    public function test_being_visible_on_nearby_does_not_grant_leads_visibility(): void
    {
        $claimant = $this->bd('Rea Villanueva');
        $observer = $this->bd('Jhamire Aranda');
        $lead = $this->prospect('Gamma Clinic');
        $this->claim($claimant, $lead);

        Sanctum::actingAs($observer);
        $board = collect($this->getJson(self::NEARBY)->assertOk()->json('data'))
            ->firstWhere('business', 'Gamma Clinic');

        // The board tells the observer it is taken, and by whom — and that is ALL it grants.
        $this->assertTrue($board['qualified']);
        $this->assertSame('Rea Villanueva', $board['qualifiedBy']['name']);
        $this->assertSame([], $this->leadNames());
        $this->getJson(self::LEADS."/{$board['id']}")->assertForbidden();
    }

    public function test_an_unclaimed_prospect_is_in_no_ones_leads_table(): void
    {
        $bd = $this->bd('Rea Villanueva');
        $this->prospect('Alpha Pharmacy');

        Sanctum::actingAs($bd);
        $this->assertSame([], $this->leadNames(), 'an unqualified prospect leaked into a BD pipeline');

        Sanctum::actingAs($this->superAdmin());
        $this->assertSame([], $this->leadNames(), 'an unqualified prospect leaked into the Leads table');
    }

    // ── Super Admin ─────────────────────────────────────────────────────────────────────────────

    public function test_a_super_admin_sees_every_bds_leads(): void
    {
        $one = $this->bd('Rea Villanueva');
        $two = $this->bd('Jhamire Aranda');
        $this->claim($one, $this->prospect('Alpha Pharmacy'));
        $this->claim($two, $this->prospect('Beta Dental'));

        Sanctum::actingAs($this->superAdmin());

        $this->assertSame(['Alpha Pharmacy', 'Beta Dental'], $this->leadNames());
    }

    public function test_a_super_admin_can_read_a_lead_owned_by_a_bd(): void
    {
        $bd = $this->bd('Rea Villanueva');
        $lead = $this->prospect('Gamma Clinic');
        $this->claim($bd, $lead);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson(self::LEADS."/{$lead->id}")->assertOk();
    }
}
