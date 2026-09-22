<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\Discovery\CoverageLedger;
use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REORDERING, AND NOTHING BUT REORDERING.
 *
 * The planner owns geography and keeps owning it: these tests pin that coverage evidence can change
 * which cell is searched FIRST and can never change what the cells are, how many there are, which
 * island group they belong to, or where their corners sit.
 *
 * The strongest test here is the dullest one — with the flag off, the plan is identical to the plan
 * the planner produced before any of this existed. Everything else is an optimisation; that one is
 * the guarantee that a paid nationwide sweep cannot be altered by accident.
 */
class DiscoveryCoverageOrderingTest extends TestCase
{
    use RefreshDatabase;

    private const NICHE = 'pharmacy';

    private int $orgId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (int) User::factory()->create(['status' => 'active'])->id;
        $this->orgId = (int) DB::table('orgs')->insertGetId([
            'name' => 'Coverage Org', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        CurrentOrg::set($this->orgId);
    }

    private function flag(bool $on): void
    {
        config(['discovery.nationwide.coverage_ordering' => $on]);
    }

    /** The planner as it behaves with no evidence reader at all — the pre-change baseline. */
    private function baselinePlanner(): NationwidePlanner
    {
        return new NationwidePlanner(app(PhilippinesBoundary::class), null);
    }

    private function planner(): NationwidePlanner
    {
        return app(NationwidePlanner::class);
    }

    /** Record a completed sweep of one cell for one niche. */
    private function searched(float $south, float $west, string $niche, int $discovered, int $limit = 20): void
    {
        $runId = DB::table('crm_discovery_runs')->insertGetId([
            'org_id' => $this->orgId,
            'uuid' => (string) Str::uuid(),
            'requested_by' => $this->userId,
            'scope_class' => 'nationwide',
            'status' => 'completed',
            'scope' => json_encode(['niches' => [$niche], 'cellDegrees' => 0.25]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('crm_discovery_run_targets')->insert([
            'org_id' => $this->orgId,
            'discovery_run_id' => $runId,
            'area' => 'Anywhere',
            'category' => $niche,
            'bbox_south' => sprintf('%.6f', $south),
            'bbox_west' => sprintf('%.6f', $west),
            'bbox_north' => sprintf('%.6f', $south + 0.25),
            'bbox_east' => sprintf('%.6f', $west + 0.25),
            'island_group' => 'visayas',
            'depth' => 0,
            'limit_per_task' => $limit,
            'status' => 'completed',
            'discovered' => $discovered,
            'inserted' => $discovered,
            'duplicates' => 0, 'rejected' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> the plan's cells, in order, as "south|west" */
    private function sequence(array $plan): array
    {
        $out = [];
        $seen = [];
        foreach ($plan as $task) {
            $key = sprintf('%.6f|%.6f', $task['bbox'][0], $task['bbox'][1]);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $key;
            }
        }

        return $out;
    }

    // --- THE SAFETY TEST ----------------------------------------------------

    public function test_with_the_flag_off_the_plan_is_identical_to_the_planner_without_coverage(): void
    {
        $this->flag(false);
        // Evidence exists and is deliberately ignored: the flag, not the data, decides.
        $this->searched(4.655707, 119.204926, self::NICHE, 3);

        $this->assertSame(
            $this->baselinePlanner()->plan([self::NICHE], 0.25, 400),
            $this->planner()->plan([self::NICHE], 0.25, 400),
            'the flag is off and the plan changed anyway',
        );
    }

    public function test_with_the_flag_on_but_no_evidence_the_plan_is_still_identical(): void
    {
        $this->flag(true);

        $this->assertSame(
            $this->baselinePlanner()->plan([self::NICHE], 0.25, 400),
            $this->planner()->plan([self::NICHE], 0.25, 400),
            'an empty ledger reordered the plan',
        );
    }

    // --- what the reordering does ------------------------------------------

    public function test_a_searched_cell_is_pushed_behind_unsearched_ones(): void
    {
        $this->flag(false);
        $first = $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX))[0];
        [$s, $w] = array_map('floatval', explode('|', $first));

        // Exhaust exactly the cell the planner would otherwise reach first.
        $this->searched($s, $w, self::NICHE, 3);
        $this->flag(true);

        $after = $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX));
        $this->assertNotSame($first, $after[0], 'the exhausted cell is still searched first');
        $this->assertContains($first, $after, 'the exhausted cell was dropped rather than deprioritised');
    }

    public function test_coverage_is_category_specific(): void
    {
        $this->flag(false);
        $first = $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX))[0];
        [$s, $w] = array_map('floatval', explode('|', $first));

        $this->searched($s, $w, 'printing shop', 3);
        $this->flag(true);

        // Swept for printing shops; still unexplored for pharmacies.
        $this->assertSame($first, $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX))[0]);
    }

    public function test_a_multi_niche_run_treats_a_cell_unsearched_for_any_niche_as_unexplored(): void
    {
        $this->flag(false);
        $first = $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX))[0];
        [$s, $w] = array_map('floatval', explode('|', $first));

        $this->searched($s, $w, self::NICHE, 3);
        $this->flag(true);

        // One niche is done here, the other has never been asked — so the cell stays first.
        $this->assertSame(
            $first,
            $this->sequence($this->planner()->plan([self::NICHE, 'printing shop'], 0.25, PHP_INT_MAX))[0],
        );
    }

    // --- invariants ---------------------------------------------------------

    public function test_the_same_cells_are_planned_in_the_same_numbers(): void
    {
        $this->flag(false);
        $before = $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX));

        $this->searched(4.655707, 119.204926, self::NICHE, 3);
        $this->searched(5.655707, 120.704926, self::NICHE, 20);
        $this->flag(true);

        $after = $this->sequence($this->planner()->plan([self::NICHE], 0.25, PHP_INT_MAX));

        sort($before);
        sort($after);
        $this->assertSame($before, $after, 'the cell set changed — a cell was added, dropped or duplicated');
    }

    public function test_the_island_group_interleave_is_preserved(): void
    {
        $this->flag(true);
        $this->searched(4.655707, 119.204926, self::NICHE, 3);

        $plan = $this->planner()->plan([self::NICHE], 0.25, 91);
        $groups = array_count_values(array_map(static fn (array $t): string => $t['group'], $plan));

        // Balanced to within one, exactly as the round-robin has always produced.
        $this->assertLessThanOrEqual(1, max($groups) - min($groups));
        $this->assertCount(3, $groups);
    }

    public function test_geometry_is_untouched(): void
    {
        $this->flag(true);
        $this->searched(4.655707, 119.204926, self::NICHE, 3);

        $this->assertSame($this->baselinePlanner()->cells(0.25), $this->planner()->cells(0.25));
        $this->assertSame(
            $this->baselinePlanner()->subdivide([5.0, 120.0, 5.25, 120.25]),
            $this->planner()->subdivide([5.0, 120.0, 5.25, 120.25]),
        );
    }

    public function test_a_failing_evidence_reader_degrades_to_the_current_ordering(): void
    {
        $this->flag(true);
        $this->app->bind(CoverageLedger::class, function () {
            return new class extends CoverageLedger
            {
                public function evidenceFor(string $category, float $cellDegrees): array
                {
                    throw new \RuntimeException('evidence unavailable');
                }
            };
        });

        // Evidence is an optimisation, never a prerequisite.
        $this->assertSame(
            $this->baselinePlanner()->plan([self::NICHE], 0.25, 200),
            $this->planner()->plan([self::NICHE], 0.25, 200),
        );
    }

    public function test_a_planner_without_a_reader_still_plans(): void
    {
        $this->flag(true);

        $this->assertSame(
            $this->baselinePlanner()->plan([self::NICHE], 0.25, 200),
            (new NationwidePlanner(app(PhilippinesBoundary::class), null))->plan([self::NICHE], 0.25, 200),
        );
    }
}
