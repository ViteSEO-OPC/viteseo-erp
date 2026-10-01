<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DensityEvidence;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\EnablesDiscoveryRuntime;
use Tests\TestCase;

/**
 * DENSITY-FIRST NATIONWIDE ORDERING — behind `discovery.nationwide.density_ordering`, default OFF.
 *
 * NO PROVIDER IS CONTACTED: the engine (searches AND the density probe) is a recording double.
 *
 * WHAT THIS PINS
 *  - OFF means today: the probe is never started and the plan is the one that predates it.
 *  - ON reorders and nothing else: same cells, same count, empty cells kept (at the end of their
 *    group), and the Luzon/Visayas/Mindanao round-robin intact.
 *  - The FIRST PASS: the densest cells of each island group are searched before any of them is
 *    subdivided; the held children come straight after, in the existing order — and that survives
 *    a slice boundary.
 *  - Every failure keeps today's order: a busy Overpass, an unsupported niche, an exception.
 *  - Google Places is untouched: the probe describes OpenStreetMap, never what Google searches.
 */
class NationwideDensityOrderingTest extends TestCase
{
    use EnablesDiscoveryRuntime;
    use RefreshDatabase;

    private const DEG = 1.0;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<array{niche: string, country: string}> */
    public array $probes = [];

    /** niche => probe response */
    public array $probeAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Sleep::fake();
        Queue::fake();
        Cache::flush();

        // Coarse tiles and a fence wide enough to walk the whole plan: ordering is about the WHOLE
        // plan, so the tests must be able to see all of it. Neither number narrows where it looks.
        config()->set('discovery.nationwide.cell_degrees', self::DEG);
        config()->set('discovery.nationwide.max_tasks', 2000);
        config()->set('discovery.nationwide.max_tasks_without_google', 2000);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);
        config()->set('discovery.nationwide.slice_seconds', 100000);
        config()->set('discovery.nationwide.coverage_ordering', false);
        config()->set('discovery.nationwide.quick_pass_tasks', 6);
    }

    // ── harness ─────────────────────────────────────────────────────────────────────────────

    private function planner(): NationwidePlanner
    {
        return new NationwidePlanner(app(PhilippinesBoundary::class), null);
    }

    /** @return list<array{area: string, group: string, bbox: array{0: float, 1: float, 2: float, 3: float}}> */
    private function cells(): array
    {
        return $this->planner()->cells(self::DEG);
    }

    /** The LAST cell of a group in emission order — so density, not raster order, must bring it forward. */
    private function lastCellOf(string $group, int $fromEnd = 0): array
    {
        $ofGroup = array_values(array_filter($this->cells(), fn (array $c): bool => $c['group'] === $group));

        return $ofGroup[count($ofGroup) - 1 - $fromEnd];
    }

    /** `$n` positions at a cell's centre — what the probe would report for `$n` businesses there. */
    private function pointsIn(array $cell, int $n): array
    {
        [$s, $w, $north, $e] = $cell['bbox'];

        return array_fill(0, $n, [($s + $north) / 2, ($w + $e) / 2]);
    }

    private function engine(?callable $respond = null): void
    {
        $test = $this;
        $this->app->instance(ScraperProcessRunner::class, new class($test, $respond) extends ScraperProcessRunner
        {
            public function __construct(private $test, private $respond) {}

            public function run(string $area, string $category, int $limit, string $provider, string $metricsPath,
                ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                $call = ['category' => $category, 'bbox' => $bbox, 'provider' => $provider];
                $this->test->calls[] = $call;
                $discovered = $this->respond === null ? 1 : ($this->respond)(count($this->test->calls), $call);

                return [
                    'exitCode' => 0, 'executionStatus' => 'ok', 'errorReason' => null,
                    'discovered' => $discovered, 'inserted' => $discovered, 'duplicates' => 0, 'rejected' => 0,
                    'stderrTail' => '', 'strategyApplied' => null, 'strategyStatus' => null, 'retryAfter' => null,
                ];
            }

            public function density(string $niche, string $country): array
            {
                $this->test->probes[] = ['niche' => $niche, 'country' => $country];

                return $this->test->probeAnswers[$niche] ?? ['status' => 'failed', 'points' => []];
            }
        });
    }

    private function start(array $niches = ['dentist']): DiscoveryRun
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/crm/discovery/runs', ['scope' => 'nationwide', 'niches' => $niches])
            ->assertStatus(202);

        return DiscoveryRun::query()->latest('id')->firstOrFail();
    }

    private function execute(DiscoveryRun $run): DiscoveryRun
    {
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    /** @return list<string> the searched boxes, depth-0 and deeper, in search order */
    private function searchedBoxes(): array
    {
        return array_map(fn (array $c): string => implode(',', $c['bbox']), $this->calls);
    }

    private function box(array $cell): string
    {
        return implode(',', $cell['bbox']);
    }

    /** @return list<string> today's order: the planner with no density at all */
    private function todaysOrder(): array
    {
        return array_map(fn (array $t): string => $this->box($t), $this->planner()->plan(['dentist'], self::DEG, PHP_INT_MAX));
    }

    private function denseLuzonVisayasMindanao(): void
    {
        $this->probeAnswers['dentist'] = ['status' => 'ok', 'points' => [
            ...$this->pointsIn($this->lastCellOf('luzon'), 50),
            ...$this->pointsIn($this->lastCellOf('luzon', 1), 10),
            ...$this->pointsIn($this->lastCellOf('visayas'), 30),
            ...$this->pointsIn($this->lastCellOf('mindanao'), 40),
        ]];
    }

    // ── planner ─────────────────────────────────────────────────────────────────────────────

    public function test_with_no_density_the_plan_is_the_one_that_predates_it(): void
    {
        $planner = $this->planner();

        $this->assertSame(
            $planner->plan(['dentist'], self::DEG, PHP_INT_MAX),
            $planner->plan(['dentist'], self::DEG, PHP_INT_MAX, null),
        );
    }

    public function test_an_all_empty_density_map_changes_nothing_because_the_sort_is_stable(): void
    {
        $planner = $this->planner();

        $this->assertSame(
            $planner->plan(['dentist'], self::DEG, PHP_INT_MAX),
            $planner->plan(['dentist'], self::DEG, PHP_INT_MAX, []),
        );
    }

    public function test_density_brings_each_groups_densest_cell_first_and_keeps_the_round_robin(): void
    {
        $planner = $this->planner();
        $density = $planner->densityByCell([
            ...$this->pointsIn($this->lastCellOf('luzon'), 50),
            ...$this->pointsIn($this->lastCellOf('visayas'), 30),
            ...$this->pointsIn($this->lastCellOf('mindanao'), 40),
        ], self::DEG);

        $plan = $planner->plan(['dentist'], self::DEG, PHP_INT_MAX, $density);

        $this->assertSame($this->box($this->lastCellOf('luzon')), $this->box($plan[0]));
        $this->assertSame($this->box($this->lastCellOf('visayas')), $this->box($plan[1]));
        $this->assertSame($this->box($this->lastCellOf('mindanao')), $this->box($plan[2]));

        // The interleave is untouched: the group sequence is exactly today's.
        $groups = fn (array $p): array => array_column($p, 'group');
        $this->assertSame($groups($planner->plan(['dentist'], self::DEG, PHP_INT_MAX)), $groups($plan));
    }

    public function test_density_reorders_only_same_cells_same_count_empties_kept_in_emission_order(): void
    {
        $planner = $this->planner();
        $dense = $this->lastCellOf('luzon');
        $plan = $planner->plan(['dentist'], self::DEG, PHP_INT_MAX, $planner->densityByCell($this->pointsIn($dense, 5), self::DEG));
        $today = $planner->plan(['dentist'], self::DEG, PHP_INT_MAX);

        $boxes = fn (array $p): array => array_map(fn (array $t): string => $this->box($t), $p);
        $sorted = function (array $b): array {
            sort($b);

            return $b;
        };
        $this->assertSame($sorted($boxes($today)), $sorted($boxes($plan)), 'same cells, none dropped, none added');

        // Every EMPTY Luzon cell keeps its relative emission order, after the dense one.
        $luzon = fn (array $p): array => array_values(array_filter($boxes(array_filter($p, fn ($t) => $t['group'] === 'luzon'))));
        $this->assertSame($this->box($dense), $luzon($plan)[0]);
        $this->assertSame(
            array_values(array_diff($luzon($today), [$this->box($dense)])),
            array_slice($luzon($plan), 1),
        );
    }

    public function test_positions_are_counted_into_the_planners_own_cells_and_outside_ones_are_ignored(): void
    {
        $planner = $this->planner();
        $cell = $this->lastCellOf('visayas');

        $counts = $planner->densityByCell([
            ...$this->pointsIn($cell, 3),
            [0.0, 0.0],          // open ocean, far outside the national extent
            [35.68, 139.69],     // Tokyo
        ], self::DEG);

        $this->assertSame([3], array_values($counts));
    }

    // ── evidence: cache and all-or-nothing ──────────────────────────────────────────────────

    public function test_the_density_map_is_cached_so_a_second_run_does_not_probe_again(): void
    {
        $this->engine();
        $this->denseLuzonVisayasMindanao();
        $evidence = app(DensityEvidence::class);

        $first = $evidence->forRun(['dentist'], 'PH', self::DEG);
        $second = $evidence->forRun(['Dentist '], 'PH', self::DEG);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->probes, 'the niche is normalised and the answer reused');
    }

    public function test_a_failed_probe_is_not_cached_and_yields_no_ordering(): void
    {
        $this->engine();
        $evidence = app(DensityEvidence::class);

        $this->assertNull($evidence->forRun(['dentist'], 'PH', self::DEG));
        $this->assertNull($evidence->forRun(['dentist'], 'PH', self::DEG));
        $this->assertCount(2, $this->probes, 'a failure is asked again, never remembered');
    }

    public function test_one_unanswerable_niche_means_no_ordering_for_the_whole_run(): void
    {
        $this->engine();
        $this->denseLuzonVisayasMindanao();
        $this->probeAnswers['kombucha'] = ['status' => 'unsupported', 'points' => []];

        $this->assertNull(app(DensityEvidence::class)->forRun(['dentist', 'kombucha'], 'PH', self::DEG));
    }

    public function test_several_niches_sum_into_one_map(): void
    {
        $this->engine();
        $cell = $this->lastCellOf('mindanao');
        $this->probeAnswers['dentist'] = ['status' => 'ok', 'points' => $this->pointsIn($cell, 2)];
        $this->probeAnswers['bakery'] = ['status' => 'ok', 'points' => $this->pointsIn($cell, 5)];

        $this->assertSame([7], array_values(app(DensityEvidence::class)->forRun(['dentist', 'bakery'], 'PH', self::DEG)));
    }

    // ── the orchestrator ────────────────────────────────────────────────────────────────────

    public function test_flag_off_never_probes_and_searches_in_todays_order(): void
    {
        config()->set('discovery.nationwide.density_ordering', false);
        $this->engine();
        $this->denseLuzonVisayasMindanao();

        $this->execute($this->start());

        $this->assertSame([], $this->probes);
        $this->assertSame($this->todaysOrder(), $this->searchedBoxes());
    }

    public function test_flag_on_probes_with_the_runs_country_and_searches_the_densest_cells_first(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        $this->engine();
        $this->denseLuzonVisayasMindanao();

        $run = $this->execute($this->start());

        $this->assertSame([['niche' => 'dentist', 'country' => 'PH']], $this->probes);
        $boxes = $this->searchedBoxes();
        $this->assertSame($this->box($this->lastCellOf('luzon')), $boxes[0]);
        $this->assertSame($this->box($this->lastCellOf('visayas')), $boxes[1]);
        $this->assertSame($this->box($this->lastCellOf('mindanao')), $boxes[2]);
        $this->assertSame($this->box($this->lastCellOf('luzon', 1)), $boxes[3]);

        // Nationwide coverage is unchanged: every planned cell is still searched, empty ones included.
        $sorted = $boxes;
        sort($sorted);
        $today = $this->todaysOrder();
        sort($today);
        $this->assertSame($today, $sorted);
        $this->assertSame((int) $run->cells_planned, count($boxes));
    }

    public function test_the_first_pass_is_searched_before_any_of_its_cells_is_subdivided(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        $dense = $this->box($this->lastCellOf('luzon'));
        // Only the densest Luzon cell comes back FULL, so it is the one that must subdivide.
        $this->engine(fn (int $n, array $call): int => implode(',', $call['bbox']) === $dense ? 20 : 1);
        $this->denseLuzonVisayasMindanao();

        $run = $this->execute($this->start());

        $depth0 = $this->planner()->plan(['dentist'], self::DEG, PHP_INT_MAX, $this->planner()->densityByCell(
            $this->probeAnswers['dentist']['points'], self::DEG,
        ));
        $firstSix = array_map(fn (array $t): string => $this->box($t), array_slice($depth0, 0, 6));
        $boxes = $this->searchedBoxes();

        // Searches 1–6 are the first pass, exactly the density order; none is a child.
        $this->assertSame($firstSix, array_slice($boxes, 0, 6));

        // Then the held children of the full cell, in the order today's front-insertion gives them.
        $children = array_map(fn (array $b): string => implode(',', $b), array_reverse($this->planner()->subdivide($this->lastCellOf('luzon')['bbox'])));
        $this->assertSame($children, array_slice($boxes, 6, count($children)));
        $this->assertSame(1, (int) $run->cells_subdivided);
    }

    public function test_after_the_first_pass_a_full_cell_subdivides_exactly_as_today(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        config()->set('discovery.nationwide.quick_pass_tasks', 3);
        $afterPass = $this->box($this->lastCellOf('luzon', 1)); // 4th in density order: not in the pass
        $this->engine(fn (int $n, array $call): int => implode(',', $call['bbox']) === $afterPass ? 20 : 1);
        $this->denseLuzonVisayasMindanao();

        $this->execute($this->start());

        $boxes = $this->searchedBoxes();
        $this->assertSame($afterPass, $boxes[3]);
        $children = array_map(fn (array $b): string => implode(',', $b), array_reverse($this->planner()->subdivide($this->lastCellOf('luzon', 1)['bbox'])));
        $this->assertSame($children, array_slice($boxes, 4, count($children)), 'children go straight to the front');
    }

    public function test_the_held_first_pass_survives_a_slice_boundary(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        $dense = $this->box($this->lastCellOf('luzon'));
        $respond = fn (int $n, array $call): int => implode(',', $call['bbox']) === $dense ? 20 : 1;

        // Unsliced reference.
        $this->engine($respond);
        $this->denseLuzonVisayasMindanao();
        $this->execute($this->start());
        $reference = $this->searchedBoxes();
        DiscoveryRun::query()->update(['status' => DiscoveryRun::STATUS_COMPLETED]);

        // The same run, one search per slice.
        $this->calls = [];
        config()->set('discovery.nationwide.slice_seconds', 1);
        $test = $this;
        $this->engine(function (int $n, array $call) use ($respond, $test): int {
            $test->travel(5)->seconds();

            return $respond($n, $call);
        });
        $run = $this->start();
        for ($i = 0; $i < 500 && ! $run->isTerminal(); $i++) {
            $run = $this->execute($run);
        }

        $this->assertTrue($run->isTerminal());
        $this->assertGreaterThan(5, (int) $run->continuations);
        $this->assertSame($reference, $this->searchedBoxes());
    }

    public function test_a_busy_overpass_keeps_todays_order(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        $this->engine();
        $this->probeAnswers['dentist'] = ['status' => 'failed', 'points' => []];

        $this->execute($this->start());

        $this->assertCount(1, $this->probes);
        $this->assertSame($this->todaysOrder(), $this->searchedBoxes());
    }

    public function test_an_unsupported_niche_keeps_todays_order(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        $this->engine();
        $this->probeAnswers['dentist'] = ['status' => 'unsupported', 'points' => []];

        $this->execute($this->start());

        $this->assertSame($this->todaysOrder(), $this->searchedBoxes());
    }

    public function test_a_probe_that_raises_keeps_todays_order_and_the_run(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        $this->engine();
        $this->app->instance(DensityEvidence::class, new class extends DensityEvidence
        {
            public function __construct() {}

            public function forRun(array $niches, string $country, float $cellDegrees): ?array
            {
                throw new \RuntimeException('boom');
            }
        });

        $run = $this->execute($this->start());

        $this->assertNotSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertSame($this->todaysOrder(), $this->searchedBoxes());
    }

    public function test_google_places_is_never_density_ordered(): void
    {
        config()->set('discovery.nationwide.density_ordering', true);
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz', 'key_last4' => 'wxyz',
        ]);
        $this->engine();
        $this->denseLuzonVisayasMindanao();

        $this->execute($this->start());

        $this->assertSame([], $this->probes, 'the OSM probe says nothing about what Google searches');
        $this->assertSame($this->todaysOrder(), $this->searchedBoxes());
        $this->assertSame(['google_places'], array_values(array_unique(array_column($this->calls, 'provider'))));
    }
}
