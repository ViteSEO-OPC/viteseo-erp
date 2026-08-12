<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Leads list "Recent / Newest" sort means MOST RECENTLY ENTERED THE CRM, not highest row id.
 *
 * A discovered prospect is inserted into the Nearby pool the day it is scraped, so its id and
 * `created_at` record DISCOVERY time. Accepting it into the CRM months later used to leave it filed
 * under its old id — page 9 of the list for a real BD — which read as "Accept into CRM did nothing".
 *
 * The ordering key is therefore COALESCE(qualified_at, created_at) DESC, with id DESC as the stable
 * tiebreaker. Leads created or imported directly never carry a `qualified_at`, so their order is
 * unchanged; only accepted prospects move.
 */
class LeadListRecentSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /** A lead created directly in the CRM (manual/import): no `source`, no `qualified_at`. */
    private function crmLead(User $owner, string $name, Carbon $createdAt): Lead
    {
        return Lead::factory()->create([
            'business_name' => $name,
            'assigned_to' => $owner->id,
            'cold_calling' => 'not-called',
            'source' => null,
            'qualified_at' => null,
            'created_at' => $createdAt,
        ]);
    }

    /** An unqualified discovered prospect sitting in the Nearby pool. */
    private function prospect(string $name, Carbon $discoveredAt): Lead
    {
        return Lead::factory()->create([
            'business_name' => $name,
            'assigned_to' => null,
            'cold_calling' => 'not-called',
            'source' => 'nearby',
            'qualified_at' => null,
            'created_at' => $discoveredAt,
        ]);
    }

    /** @return list<string> business names in list order */
    private function listedNames(User $user, array $params = []): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/crm/leads?'.http_build_query($params))
            ->assertOk()
            ->json('data.*.business');
    }

    // ---------------------------------------------------------------- the reported defect

    public function test_an_old_prospect_accepted_today_sorts_as_the_most_recent_lead(): void
    {
        $bd = $this->bd();
        // Two leads created in the CRM recently — under the old id-based sort these outranked
        // anything discovered months earlier, no matter when it was accepted.
        $this->crmLead($bd, 'Created Last Week', now()->subWeek());
        $this->crmLead($bd, 'Created Yesterday', now()->subDay());
        // An old discovery: low id, months-old created_at.
        $old = $this->prospect('Old Discovery', now()->subMonths(3));

        // Accepted right now, through the real endpoint.
        Sanctum::actingAs($bd);
        $this->postJson("/api/v1/crm/leads/{$old->id}/qualify")->assertCreated();

        $names = $this->listedNames($bd);

        $this->assertSame('Old Discovery', $names[0] ?? null,
            'a prospect accepted today must lead the Recent sort, not sit at its discovery position');
        $this->assertSame(['Old Discovery', 'Created Yesterday', 'Created Last Week'], $names);
    }

    public function test_the_full_nearby_accept_flow_puts_the_business_on_page_one(): void
    {
        $bd = $this->bd();
        // Enough recent CRM leads to fill the first page twice over, so an id-ordered list would
        // bury the accepted prospect exactly the way it did in production.
        foreach (range(1, 25) as $i) {
            $this->crmLead($bd, "Filler {$i}", now()->subDays(30 - $i));
        }
        $old = $this->prospect('Serenity Spa', now()->subMonths(2));

        // 1. it is NOT in the Leads list while it is still an unqualified prospect
        $this->assertNotContains('Serenity Spa', $this->listedNames($bd));

        // 2. Accept into CRM
        Sanctum::actingAs($bd);
        $this->postJson("/api/v1/crm/leads/{$old->id}/qualify")->assertCreated();

        // 3. it is now the first row of page one
        $this->assertSame('Serenity Spa', $this->listedNames($bd)[0] ?? null);

        // 4. and it really is owned by the accepting BD (the list is ownership-scoped)
        $this->assertSame($bd->id, $old->fresh()->assigned_to);
    }

    // ---------------------------------------------------------------- no collateral change

    public function test_leads_without_a_qualified_at_keep_their_creation_order(): void
    {
        $bd = $this->bd();
        $this->crmLead($bd, 'Oldest', now()->subMonths(2));
        $this->crmLead($bd, 'Middle', now()->subMonth());
        $this->crmLead($bd, 'Newest', now()->subDay());

        // COALESCE falls through to created_at for these, so the pre-existing ordering is intact.
        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->listedNames($bd));
    }

    public function test_id_desc_still_breaks_ties_when_timestamps_are_identical(): void
    {
        $bd = $this->bd();
        // A bulk import stamps every row the same second — without the tiebreaker the page order
        // would be arbitrary and pagination non-deterministic.
        $at = now()->subDay();
        $first = $this->crmLead($bd, 'Import A', $at);
        $second = $this->crmLead($bd, 'Import B', $at);

        $this->assertGreaterThan($first->id, $second->id);
        $this->assertSame(['Import B', 'Import A'], $this->listedNames($bd));
    }

    /** @param list<string> $expected */
    #[DataProvider('untouchedSorts')]
    public function test_the_other_sort_options_are_unchanged(string $sort, array $expected): void
    {
        $bd = $this->bd();
        // Alma is the OLDEST by id/creation but was accepted today — it would jump to the front under
        // the default sort. None of these sorts may be affected by that.
        $alma = $this->prospect('Alma', now()->subMonths(3));
        $zeta = $this->crmLead($bd, 'Zeta', now()->subDay());

        Sanctum::actingAs($bd);
        $this->postJson("/api/v1/crm/leads/{$alma->id}/qualify")->assertCreated();

        // Scores are pinned AFTER qualification, and quietly. LeadObserver computes the one-time Lead
        // Score on the `qualified_at` transition, so a score set beforehand is overwritten by a value
        // derived from LeadFactory's randomised scoring inputs — which made this assertion flaky
        // rather than wrong. Setting the attribute directly also sidesteps mass-assignment rules.
        $alma = $alma->fresh();
        $alma->score = 99;
        $alma->saveQuietly();
        $zeta->score = 10;
        $zeta->saveQuietly();

        $this->assertSame($expected, $this->listedNames($bd, ['sort' => $sort]));
    }

    public static function untouchedSorts(): array
    {
        return [
            'oldest keeps id asc' => ['oldest', ['Alma', 'Zeta']],
            'score-desc' => ['score-desc', ['Alma', 'Zeta']],
            'score-asc' => ['score-asc', ['Zeta', 'Alma']],
            'name-asc' => ['name-asc', ['Alma', 'Zeta']],
            'name-desc' => ['name-desc', ['Zeta', 'Alma']],
        ];
    }
}
