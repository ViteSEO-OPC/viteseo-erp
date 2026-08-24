<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Management Dashboard's "BD Performance Overview" row (`GET /dashboard/bd-performance`).
 *
 * The card reports three live figures per BD, and the two added last carry approved definitions
 * that this endpoint must NOT restate in its own terms:
 *
 *  - **Converted** is the existence of a Deal (Business-Computation-Mapping §3.3). It is never
 *    read from `leads.status` — no status in the enum means "won", so a status-derived count would
 *    be an invented business rule. The third test pins exactly that.
 *  - **Rate** is converted ÷ ENGAGED (§3.5), not converted ÷ assigned. The denominator is the
 *    canonical {@see Lead::scopeEngaged()} predicate shared with M-04, so "of the leads we actually
 *    worked, how many converted" — which means an unworked portfolio shrinks the denominator, not
 *    the numerator.
 *
 * The zero-denominator case is the one most likely to regress into a plausible-looking bug: a BD
 * who has engaged nobody must report `null`, rendered as an em dash. A fabricated `0` would claim
 * they worked leads and converted none, which is a different and false statement.
 */
class DashboardBdPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, string $name = 'Test Person'): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    /** A lead that counts as ENGAGED (outreach was sent). `source` is null, so it is also active. */
    private function engagedLead(User $bd): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $bd->id,
            'email_outreach' => 'sent',
            'cold_calling' => 'not-called',
        ]);
    }

    /** A lead that is active but NOT engaged — no outreach of any kind. */
    private function untouchedLead(User $bd): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $bd->id,
            'email_outreach' => 'none',
            'cold_calling' => 'not-called',
        ]);
    }

    private function rowFor(User $admin, User $bd): array
    {
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', (string) $bd->id);
        $this->assertNotNull($row, "no row returned for BD {$bd->name}");

        return $row;
    }

    public function test_assigned_still_counts_every_active_lead_regardless_of_engagement(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $this->engagedLead($bd);
        $this->untouchedLead($bd);
        $this->untouchedLead($bd);

        // Another BD's lead must not leak into this row.
        $this->engagedLead($this->user('crm_bd', 'Other Dev'));

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(3, $row['assigned'], 'Assigned must remain a raw count of the BD\'s active leads');
    }

    public function test_converted_counts_leads_with_at_least_one_deal(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $won = $this->engagedLead($bd);
        Deal::factory()->create(['lead_id' => $won->id]);

        // Two deals on ONE lead is still ONE converted lead — the metric counts leads, not deals.
        Deal::factory()->create(['lead_id' => $won->id]);

        $this->engagedLead($bd);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(1, $row['converted'], 'Converted counts LEADS holding ≥1 deal, not deals');
    }

    public function test_a_lead_without_a_deal_never_counts_as_converted_whatever_its_status(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // The furthest-along status in the enum, deliberately with no Deal attached. Conversion is
        // the Deal, so this must not count — this is the guard against a status-derived proxy.
        Lead::factory()->create([
            'assigned_to' => $bd->id,
            'status' => 'interested',
            'email_outreach' => 'replied',
            'cold_calling' => 'called',
        ]);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(0, $row['converted'], 'status must never stand in for a Deal');
    }

    public function test_rate_is_converted_over_engaged_to_one_decimal(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // 3 engaged, 1 of them converted → 33.3%. The 2 untouched leads inflate `assigned` to 5 but
        // must NOT enter the denominator; assigned-based maths would give 20.0%.
        foreach (range(1, 3) as $i) {
            $lead = $this->engagedLead($bd);
            if ($i === 1) {
                Deal::factory()->create(['lead_id' => $lead->id]);
            }
        }
        $this->untouchedLead($bd);
        $this->untouchedLead($bd);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(5, $row['assigned']);
        $this->assertSame(1, $row['converted']);
        $this->assertSame(33.3, $row['rate'], 'rate denominator must be engaged leads, not assigned');
    }

    public function test_rate_is_null_and_never_zero_when_the_bd_has_engaged_nobody(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $this->untouchedLead($bd);
        $this->untouchedLead($bd);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(2, $row['assigned']);
        $this->assertSame(0, $row['converted']);
        $this->assertNull($row['rate'], 'an undefined rate must be null — 0% would be a false claim');
        $this->assertNotSame(0, $row['rate']);
        $this->assertNotSame(0.0, $row['rate']);
    }

    public function test_a_bd_with_no_leads_at_all_reports_zeroes_and_a_null_rate(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(0, $row['assigned']);
        $this->assertSame(0, $row['converted']);
        $this->assertNull($row['rate']);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_bds(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');

        $first = $this->user('crm_bd', 'Solo Dev');
        Deal::factory()->create(['lead_id' => $this->engagedLead($first)->id]);

        Sanctum::actingAs($admin);

        // Warm-up: the FIRST authenticated request of a test also resolves the acting user's roles
        // and permissions, which are then cached. Measuring it would compare a cold request against
        // a warm one and read the difference as a query-count change that has nothing to do with
        // the aggregates under test.
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();
        $withOneBd = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Six more BDs, each with their own leads and deals.
        foreach (range(1, 6) as $i) {
            $bd = $this->user('crm_bd', "Extra Dev {$i}");
            Deal::factory()->create(['lead_id' => $this->engagedLead($bd)->id]);
            $this->untouchedLead($bd);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();
        $withSevenBds = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withOneBd,
            $withSevenBds,
            "N+1 regression: {$withOneBd} queries for 1 BD but {$withSevenBds} for 7 — the per-BD "
            .'figures must stay GROUP BY aggregates, never a per-row service call',
        );
    }
}
