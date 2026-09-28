<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\Discovery\CoverageLedger;
use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WHAT COUNTS AS HAVING SEARCHED A CELL, AND WHAT DELIBERATELY DOES NOT.
 *
 * Every exclusion here is a correctness rule rather than caution: a subdivided child is a corner and
 * not the square, a scoped run has no square at all, and a 0.5° box and a 0.25° box are different
 * ground. Reading any of them as coverage would report explored country that was never searched.
 */
class DiscoveryCoverageLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const S = '5.655707';

    private const W = '120.704926';

    private int $orgId;

    private int $otherOrgId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) User::factory()->create(['status' => 'active'])->id;
        $this->orgId = $this->org('Coverage Org');
        $this->otherOrgId = $this->org('Another Tenant');

        CurrentOrg::set($this->orgId);
    }

    private function org(string $name): int
    {
        return (int) DB::table('orgs')->insertGetId([
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ledger(): CoverageLedger
    {
        return app(CoverageLedger::class);
    }

    /** One nationwide run, and one target inside it. */
    private function target(array $overrides = [], array $runOverrides = []): void
    {
        $runId = DB::table('crm_discovery_runs')->insertGetId(array_merge([
            'org_id' => $this->orgId,
            'uuid' => (string) Str::uuid(),
            'requested_by' => $this->userId,
            'scope_class' => 'nationwide',
            'status' => 'completed',
            'scope' => json_encode(['niches' => ['pharmacy'], 'cellDegrees' => 0.25]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $runOverrides));

        DB::table('crm_discovery_run_targets')->insert(array_merge([
            'org_id' => $this->orgId,
            'discovery_run_id' => $runId,
            'area' => 'General Santos',
            'category' => 'pharmacy',
            'bbox_south' => self::S,
            'bbox_west' => self::W,
            'bbox_north' => '5.905707',
            'bbox_east' => '120.954926',
            'island_group' => 'mindanao',
            'depth' => 0,
            'limit_per_task' => 20,
            'status' => 'completed',
            'discovered' => 5,
            'inserted' => 5,
            'duplicates' => 0,
            'rejected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function tierOf(string $category = 'pharmacy'): int
    {
        return $this->ledger()->tier($this->ledger()->evidenceFor($category, 0.25), (float) self::S, (float) self::W);
    }

    // --- coverage is per (cell, category) -----------------------------------

    public function test_a_cell_searched_for_one_category_is_not_coverage_for_another(): void
    {
        $this->target();

        $this->assertSame(CoverageLedger::TIER_EXHAUSTED, $this->tierOf('pharmacy'));
        // The whole point: one niche's history must not suppress another niche's exploration.
        $this->assertSame(CoverageLedger::TIER_UNSEARCHED, $this->tierOf('printing shop'));
    }

    public function test_a_category_is_matched_as_typed_apart_from_case_and_space(): void
    {
        $this->target(['category' => 'Printing Shop']);

        $this->assertSame(CoverageLedger::TIER_EXHAUSTED, $this->tierOf('  printing shop '));
    }

    // --- the conclusion mirrors the orchestrator's own ----------------------

    public function test_fewer_than_the_limit_is_exhausted_and_the_full_limit_is_capped(): void
    {
        $this->target(['discovered' => 19, 'limit_per_task' => 20]);
        $this->assertSame(CoverageLedger::TIER_EXHAUSTED, $this->tierOf());
    }

    public function test_a_full_cell_is_capped_because_there_is_more_there(): void
    {
        $this->target(['discovered' => 20, 'limit_per_task' => 20]);
        $this->assertSame(CoverageLedger::TIER_CAPPED, $this->tierOf());
    }

    public function test_a_cell_that_returned_nothing_ranks_above_a_productive_one(): void
    {
        $this->target(['discovered' => 0, 'inserted' => 0]);
        // It may have been asked badly rather than be empty — a different claim from a real answer.
        $this->assertSame(CoverageLedger::TIER_BARREN, $this->tierOf());
        $this->assertLessThan(CoverageLedger::TIER_EXHAUSTED, $this->tierOf());
    }

    // --- what must never be read as coverage --------------------------------

    public function test_a_subdivided_child_is_not_coverage_of_a_cell(): void
    {
        $this->target(['depth' => 1]);
        // A quarter of a square is a corner, not the square.
        $this->assertSame(CoverageLedger::TIER_UNSEARCHED, $this->tierOf());
    }

    public function test_a_scoped_run_has_no_cell_and_is_ignored(): void
    {
        $this->target(['bbox_south' => null, 'bbox_west' => null, 'bbox_north' => null, 'bbox_east' => null],
            ['scope_class' => 'scoped', 'scope' => json_encode(['niches' => ['pharmacy'], 'locations' => ['gensan']])]);

        $this->assertSame(CoverageLedger::TIER_UNSEARCHED, $this->tierOf());
    }

    public function test_a_different_tiling_is_different_ground_and_is_ignored(): void
    {
        $this->target([], ['scope' => json_encode(['niches' => ['pharmacy'], 'cellDegrees' => 0.5])]);

        $this->assertSame(CoverageLedger::TIER_UNSEARCHED, $this->tierOf());
    }

    public function test_another_organizations_searching_is_invisible(): void
    {
        $this->target(['org_id' => $this->otherOrgId]);

        $this->assertSame(CoverageLedger::TIER_UNSEARCHED, $this->tierOf());
    }

    // --- identity -----------------------------------------------------------

    public function test_cell_keys_match_planner_generated_cells_exactly(): void
    {
        $planner = app(NationwidePlanner::class);
        $ledger = $this->ledger();

        $keys = [];
        foreach ($planner->cells(0.25) as $cell) {
            $keys[$ledger->cellKey($cell['bbox'][0], $cell['bbox'][1])] = true;
        }

        // Exact, not approximate: the stored columns are decimal(9,6), so no float is ever compared.
        $this->assertArrayHasKey($ledger->cellKey((float) self::S, (float) self::W), $keys);
        $this->assertCount(736, $keys);
    }

    public function test_repeated_passes_over_one_cell_are_summed_not_duplicated(): void
    {
        $this->target(['discovered' => 8]);
        $this->target(['discovered' => 14]);

        $evidence = $this->ledger()->evidenceFor('pharmacy', 0.25);
        $cell = $evidence[$this->ledger()->cellKey((float) self::S, (float) self::W)];
        $this->assertSame(22, $cell['discovered']);
        $this->assertSame(2, $cell['passes']);
    }
}
