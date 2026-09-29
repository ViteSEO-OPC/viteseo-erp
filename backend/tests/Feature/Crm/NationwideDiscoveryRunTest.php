<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * NATIONWIDE DISCOVERY — the country as the scope, at about 40 valid unique businesses per area.
 *
 * NO PROVIDER IS EVER CONTACTED. The Python engine is replaced by a recording fake, so nothing
 * spawns a subprocess and nothing spends money; and `Http::preventStrayRequests()` turns any
 * outbound call — to a model vendor above all — into a test failure rather than a silent charge.
 *
 * WHAT THIS PINS, AND WHY EACH ONE WOULD BE A REAL REGRESSION
 *
 *  - **Nationwide is the polygon, not a list.** Areas are derived from the vendored national
 *    boundary, they span Luzon / Visayas / Mindanao, and every search is handed a bounding box that
 *    lies inside the country. The moment a hardcoded city list could satisfy these assertions, the
 *    feature has become the thing it exists to replace.
 *  - **The 40 is counted in NEW businesses.** Duplicates are real work but not new coverage, so an
 *    area fed nothing but duplicates must never report its target as reached.
 *  - **There is no quota.** A cell is searched until the PROVIDER stops filling the page, and the
 *    country is covered until every cell has been asked or the budget ends the run. Nothing counts
 *    toward a per-city target, because there is no per-city target.
 *  - **A cell's ending is never disguised.** `exhausted` (the provider had less than it was allowed
 *    to give) and `capped` (it was still full when the descent limit stopped the split) are opposite
 *    claims and must stay distinguishable.
 *  - **The budget is a hard ceiling** on provider calls, and the areas it prevents are recorded as
 *    never-searched rather than as empty.
 *  - **Advanced Lead Discovery is untouched.** A scoped run still takes locations, still defaults to
 *    20 per search, and still sends no bounding box.
 *  - **No AI, at all.** This phase is Google-only by decision, and a nationwide run is exactly where
 *    a stray model call would be most expensive.
 */
class NationwideDiscoveryRunTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/crm/discovery/runs';

    /** Calls the fake engine recorded, in order. */
    private array $calls = [];

    /** The canned outcome the fake engine returns for every call. */
    private array $outcome = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A stray outbound request is a failed test, not a surprise invoice.
        Http::preventStrayRequests();

        // Coarse tiling and a small budget, so a test covers the country in a handful of searches
        // rather than the hundreds a real run makes. Neither number narrows WHERE it may look.
        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 12);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);

        $this->outcome = $this->engineResult(discovered: 5, inserted: 5);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    private function googleLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz',
            'key_last4' => 'wxyz',
        ]);
    }

    /** @return array<string, mixed> */
    private function engineResult(int $discovered, int $inserted, int $duplicates = 0, int $exitCode = 0): array
    {
        return [
            'exitCode' => $exitCode,
            'executionStatus' => 'ok',
            'errorReason' => null,
            'discovered' => $discovered,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'rejected' => 0,
            'stderrTail' => '',
        ];
    }

    /**
     * Replace the engine with a recorder.
     *
     * Deliberately a real subclass rather than a mock expectation: the point is to capture EVERY
     * argument of every call so the bbox, the limit and the provider can be asserted as facts, and
     * so an assertion about "no second call" is about what actually happened.
     *
     * @param  null|callable(int, array<string, mixed>): array<string, mixed>  $respond
     */
    private function fakeEngine(?callable $respond = null): void
    {
        $test = $this;
        $this->app->instance(ScraperProcessRunner::class, new class($test, $respond) extends ScraperProcessRunner
        {
            public function __construct(private $test, private $respond) {}

            public function run(
                string $area,
                string $category,
                int $limit,
                string $provider,
                string $metricsPath,
                ?array $bbox = null,
                ?string $country = null,
                bool $aiStrategy = false,
                ?string $crmToken = null,
            ): array {
                return $this->test->recordCall(
                    compact('area', 'category', 'limit', 'provider', 'bbox', 'country'),
                    $this->respond,
                );
            }
        });
    }

    /** @internal Used by the anonymous engine fake. */
    public function recordCall(array $call, ?callable $respond): array
    {
        $this->calls[] = $call;

        return $respond === null ? $this->outcome : $respond(count($this->calls), $call);
    }

    private function executeNationwide(): DiscoveryRun
    {
        $run = DiscoveryRun::query()->where('scope_class', DiscoveryRun::SCOPE_NATIONWIDE)->firstOrFail();
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    private function startNationwide(array $overrides = []): TestResponse
    {
        return $this->postJson(self::ENDPOINT, ['scope' => 'nationwide', 'niches' => ['bakery'], ...$overrides]);
    }

    // ── the HTTP surface ─────────────────────────────────────────────────────────────────────

    public function test_a_nationwide_run_needs_no_locations(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        $this->startNationwide()->assertAccepted()->assertJsonPath('data.scopeClass', 'nationwide');

        $run = DiscoveryRun::query()->firstOrFail();
        $this->assertSame([], $run->scope['locations'], 'a nationwide run names no places');
        $this->assertSame(['bakery'], $run->scope['niches']);
        $this->assertSame('PH', $run->scope['country']);
        $this->assertSame(12, (int) $run->budget_total);

        Queue::assertPushedOn('discovery', RunDiscoveryJob::class);
    }

    public function test_a_nationwide_run_refuses_locations_rather_than_ignoring_them(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        // Silently dropping them would let a user believe they had narrowed a search that in fact
        // covers the whole country.
        $this->startNationwide(['locations' => ['Cebu City']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locations');
    }

    public function test_the_country_is_not_a_free_text_location(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        foreach (['Philippines', 'the philippines', 'PH', 'Pilipinas'] as $name) {
            $this->postJson(self::ENDPOINT, ['locations' => [$name], 'niches' => ['bakery']])
                ->assertStatus(422)
                ->assertJsonValidationErrors('locations.0');
        }

        // ...and every other place name on earth still is. This is one reserved word, not a list.
        $this->postJson(self::ENDPOINT, ['locations' => ['Roxas City'], 'niches' => ['vape shop']])
            ->assertAccepted();
    }

    public function test_only_one_nationwide_run_may_be_in_flight(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        $first = $this->startNationwide()->assertAccepted()->json('data.runId');
        $this->startNationwide()
            ->assertStatus(409)
            ->assertJsonPath('data.runId', $first);
    }

    public function test_a_business_developer_still_cannot_start_a_nationwide_run(): void
    {
        Queue::fake();
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        Sanctum::actingAs($bd);

        // The original RBAC is untouched: the paid-capability gate is the route's, not this feature's.
        $this->startNationwide()->assertForbidden();
    }

    public function test_advanced_lead_discovery_is_unchanged_by_the_new_mode(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        $this->postJson(self::ENDPOINT, ['locations' => ['Cebu City'], 'niches' => ['dentist']])
            ->assertAccepted();

        $run = DiscoveryRun::query()->firstOrFail();
        $this->assertSame(DiscoveryRun::SCOPE_SCOPED, $run->scope_class);
        // Still 20, and still its own number — the nationwide per-cell limit must not leak into it.
        $this->assertSame(20, (int) $run->scope['limitPerTask']);
        $this->assertSame(0, (int) $run->cells_planned);
        $this->assertSame(0, (int) $run->budget_total);
    }

    // ── execution: coverage, boxes and the boundary ──────────────────────────────────────────

    public function test_every_search_is_handed_a_box_inside_the_philippines(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        $this->fakeEngine();
        $this->startNationwide();
        $this->executeNationwide();

        $boundary = new PhilippinesBoundary;
        [$south, $west, $north, $east] = $boundary->bbox();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertNotNull($call['bbox'], 'a nationwide search must be bounded, never a bare place name');
            [$s, $w, $n, $e] = $call['bbox'];

            $this->assertGreaterThanOrEqual($south, $s);
            $this->assertLessThanOrEqual($north, $n);
            $this->assertGreaterThanOrEqual($west, $w);
            $this->assertLessThanOrEqual($east, $e);
            $this->assertLessThan($n, $s);
            $this->assertLessThan($e, $w);
        }
    }

    public function test_google_places_is_the_provider_for_every_search(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        $this->fakeEngine();
        $this->startNationwide();
        $this->executeNationwide();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame('google_places', $call['provider']);
        }
    }

    public function test_a_full_size_nationwide_run_refuses_to_sweep_the_country_on_the_openstreetmap_baseline(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        // No Google credential: the run resolves to the free, shared, community-funded baseline.
        $this->fakeEngine();
        // Stated outright rather than inherited: setUp() pins this class's default budget to 12, so
        // relying on the default would have tested the bounded case under the full-size name.
        $this->startNationwide(['max_tasks' => 400]);

        $run = $this->executeNationwide();

        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Google Places', (string) $run->error_message);
        $this->assertSame([], $this->calls, 'nothing may be searched once the provider is refused');
    }

    /**
     * The fence is the SIZE, not the provider's name.
     *
     * Refusing the baseline at every size is what left the AI-assisted lane with no way to reach the
     * only planner that covers the country: it could search cities it was handed and nothing else.
     * A budget at or under the fence is a few hundred Overpass queries from a client that already
     * backs off on rate limits, so it is allowed to plan and to search.
     */
    public function test_a_bounded_nationwide_run_may_sweep_on_the_openstreetmap_baseline(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->fakeEngine();
        $this->startNationwide(['max_tasks' => config('discovery.nationwide.max_tasks_without_google')]);

        $run = $this->executeNationwide();

        $this->assertNotSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertNotSame([], $this->calls, 'a budget inside the fence must actually be searched');
        $this->assertGreaterThan(0, $run->cells_planned, 'the national polygon must still be tiled');
        foreach ($this->calls as $call) {
            $this->assertNotSame('google_places', $call['provider']);
            $this->assertNotNull($call['bbox'], 'every search still comes from a planned cell');
        }
    }

    public function test_the_overpass_fence_names_the_budget_that_would_be_accepted(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->fakeEngine();
        $ceiling = (int) config('discovery.nationwide.max_tasks_without_google');
        $this->startNationwide(['max_tasks' => $ceiling + 1]);

        $run = $this->executeNationwide();

        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        // A refusal a person cannot act on is a dead end: it must say the number that would work.
        $this->assertStringContainsString((string) $ceiling, (string) $run->error_message);
    }

    public function test_the_stored_target_records_the_box_that_was_searched(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        $this->fakeEngine();
        $this->startNationwide();
        $this->executeNationwide();

        $target = DiscoveryRunTarget::query()->firstOrFail();
        $this->assertNotNull($target->bbox());
        $this->assertContains($target->island_group, ['luzon', 'visayas', 'mindanao']);
        $this->assertSame('bakery', $target->category);
    }

    // ── the 40-per-area contract ─────────────────────────────────────────────────────────────

    public function test_a_subdivided_cell_stays_inside_its_parent_and_inside_the_country(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        $this->outcome = $this->engineResult(discovered: 20, inserted: 0, duplicates: 20);
        $this->fakeEngine();
        $this->startNationwide();
        $this->executeNationwide();

        $boundary = new PhilippinesBoundary;
        foreach (DiscoveryRunTarget::query()->where('depth', '>', 0)->get() as $child) {
            [$s, $w, $n, $e] = $child->bbox();
            $parent = DiscoveryRunTarget::query()->findOrFail($child->parent_id);
            [$ps, $pw, $pn, $pe] = $parent->bbox();

            $this->assertGreaterThanOrEqual($ps, $s);
            $this->assertGreaterThanOrEqual($pw, $w);
            $this->assertLessThanOrEqual($pn, $n);
            $this->assertLessThanOrEqual($pe, $e);
            // And it still touches Philippine land — a quadrant is re-tested, never inherited.
            $this->assertTrue($boundary->intersects([$s, $w, $n, $e]));
        }
    }

    // ── budget, cancellation and honesty about both ──────────────────────────────────────────

    public function test_the_budget_is_a_hard_ceiling_on_provider_calls(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 4);
        // Every cell comes back FULL, so without the budget the descent would never stop.
        $this->outcome = $this->engineResult(discovered: 20, inserted: 1);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        $this->assertCount(4, $this->calls, 'the budget must stop the run buying a fifth search');
        $this->assertSame(4, (int) $run->budget_used);
    }

    public function test_cancelling_stops_the_next_search(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        $this->startNationwide();

        // Cancel from inside the second call, exactly as a user clicking mid-run would.
        $this->fakeEngine(function (int $n): array {
            if ($n === 2) {
                DiscoveryRun::query()->where('scope_class', DiscoveryRun::SCOPE_NATIONWIDE)
                    ->update(['status' => DiscoveryRun::STATUS_CANCELLED]);
            }

            return $this->engineResult(discovered: 2, inserted: 2);
        });

        $run = $this->executeNationwide();

        $this->assertSame(DiscoveryRun::STATUS_CANCELLED, $run->status);
        $this->assertLessThanOrEqual(3, count($this->calls), 'cancellation must stop the next call promptly');
        $this->assertSame(
            0,
            $run->targets()->whereIn('status', [
                DiscoveryRunTarget::STATUS_PENDING,
                DiscoveryRunTarget::STATUS_RUNNING,
            ])->count(),
            'no search may be left pending on a cancelled run',
        );
    }

    // ── WHAT MAKES IT STOP ───────────────────────────────────────────────────────────────────
    //
    // There is no quota. These pin the only three things that can end a nationwide run, and the
    // difference between the two ways a single cell can finish.

    public function test_coverage_reaches_all_three_island_groups_and_comes_from_the_polygon(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 400);
        // Short pages everywhere: every cell is exhausted on its first ask, so the run walks the
        // whole first-pass tiling and stops on its own.
        $this->outcome = $this->engineResult(discovered: 2, inserted: 2);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        $groups = DiscoveryRunTarget::query()->distinct()->pluck('island_group')->sort()->values()->all();
        $this->assertSame(['luzon', 'mindanao', 'visayas'], $groups);

        // COVERAGE IS THE POLYGON, NOT A CITY LIST. Thirty-eight anchors exist; the tiling at this
        // resolution must produce far more cells than that, or "nationwide" has quietly become a
        // list again.
        $this->assertGreaterThan(38, (int) $run->cells_planned);
    }

    public function test_a_run_ends_when_every_cell_is_exhausted_by_the_provider(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 400);
        // Fewer results than the limit allows: the provider is telling the truth that there is
        // nothing more in this box.
        $this->outcome = $this->engineResult(discovered: 3, inserted: 3);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        // It stopped because the PROVIDER ran out, not because the budget did.
        $this->assertLessThan((int) $run->budget_total, (int) $run->budget_used);
        $this->assertSame((int) $run->cells_planned, (int) $run->cells_first_pass_done);
        $this->assertSame((int) $run->cells_searched, (int) $run->cells_exhausted);
        $this->assertSame(0, (int) $run->cells_subdivided, 'a short cell must never be split');
        $this->assertSame(0, (int) $run->cells_capped);
    }

    public function test_a_full_cell_is_subdivided_and_the_descent_is_auditable(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 30);
        // A FULL page every time: there is always more, so the run digs until the depth limit.
        $this->outcome = $this->engineResult(discovered: 20, inserted: 20);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        $this->assertGreaterThan(0, (int) $run->cells_subdivided);

        $deep = DiscoveryRunTarget::query()->where('depth', '>', 0)->get();
        $this->assertNotEmpty($deep, 'a full cell must be subdivided');
        foreach ($deep as $child) {
            // Every deep search traces back to the full cell that justified creating it.
            $this->assertNotNull($child->parent_id);
            $this->assertLessThanOrEqual(1, (int) $child->depth, 'max_depth is a hard ceiling');
        }
    }

    public function test_a_cell_still_full_at_the_depth_limit_is_capped_not_exhausted(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_depth', 0);
        config()->set('discovery.nationwide.max_tasks', 5);
        $this->outcome = $this->engineResult(discovered: 20, inserted: 20);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        // CAPPED, never EXHAUSTED. Exhausted would assert the provider had nothing more, which is
        // the opposite of what a full page means.
        $this->assertGreaterThan(0, (int) $run->cells_capped);
        $this->assertSame(0, (int) $run->cells_exhausted);
    }

    public function test_duplicates_never_stop_a_cell_from_being_searched_deeper(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 20);
        // A full page of businesses the CRM already has. Coverage is about ground searched, not rows
        // inserted, so a cell full of duplicates is still a full cell and must still subdivide.
        $this->outcome = $this->engineResult(discovered: 20, inserted: 0, duplicates: 20);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        $this->assertGreaterThan(0, (int) $run->cells_subdivided);
        $this->assertSame(0, (int) $run->inserted);
    }

    public function test_nothing_anywhere_imposes_a_per_city_quota(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 60);
        // Far more than any old 40-per-city ceiling would have allowed through for one label.
        $this->outcome = $this->engineResult(discovered: 20, inserted: 20);
        $this->fakeEngine();
        $this->startNationwide();

        $run = $this->executeNationwide();

        $mostInsertedForOneLabel = (int) DiscoveryRunTarget::query()
            ->selectRaw('sum(inserted) as total')
            ->groupBy('area')
            ->orderByDesc('total')
            ->value('total');

        // The run keeps going past any per-label number; only the budget stops it.
        $this->assertGreaterThan(40, $mostInsertedForOneLabel);
        $this->assertSame(60, (int) $run->budget_used);
    }

    public function test_a_budget_limited_run_says_so_instead_of_reading_as_finished(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        // Far too small to walk the country: the run must end with ground left and say which.
        config()->set('discovery.nationwide.max_tasks', 3);
        $this->outcome = $this->engineResult(discovered: 2, inserted: 2);
        $this->fakeEngine();
        $runId = $this->startNationwide()->json('data.runId');
        $run = $this->executeNationwide();

        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        // `completed` alone would read as "the country has been covered". It has not.
        $this->assertSame('budget_limited', $run->stopReason());

        $body = $this->getJson("/api/v1/crm/discovery/runs/{$runId}")->json('data');
        $this->assertSame('budget_limited', $body['stopReason']);
        $this->assertLessThan(
            $body['progress']['cellsPlanned'],
            $body['progress']['cellsFirstPassDone'],
            'a budget-limited run must leave first-pass ground unsearched',
        );
    }

    public function test_a_run_that_walks_the_whole_plan_reports_exhausted(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.cell_degrees', 5.0); // a handful of very coarse cells
        config()->set('discovery.nationwide.max_tasks', 500);
        $this->outcome = $this->engineResult(discovered: 1, inserted: 1);
        $this->fakeEngine();
        $run = $this->startNationwide(['cell_degrees' => 5.0]);
        $run = $this->executeNationwide();

        $this->assertSame('exhausted', $run->stopReason());
        $this->assertSame((int) $run->cells_planned, (int) $run->cells_first_pass_done);
        $this->assertLessThan((int) $run->budget_total, (int) $run->budget_used);
    }

    public function test_the_budget_ceiling_is_high_enough_to_request_a_real_nationwide_sweep(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        // THE FENCE MUST NOT BE A WALL. A first pass at the default resolution costs one call per
        // cell per niche — measured at 736 for one niche — so a ceiling at the old 400 made the
        // feature's headline capability literally unrequestable.
        $this->startNationwide(['max_tasks' => 5000])->assertAccepted();
        $this->assertSame(5000, (int) DiscoveryRun::query()->firstOrFail()->budget_total);
    }

    // ── the country fence ────────────────────────────────────────────────────────────────────

    public function test_the_country_defaults_to_the_philippines_on_both_modes(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        $this->startNationwide()->assertAccepted();
        $this->assertSame('PH', DiscoveryRun::query()->firstOrFail()->scope['country']);

        DiscoveryRun::query()->delete();
        $this->postJson(self::ENDPOINT, ['locations' => ['Cebu City'], 'niches' => ['bakery']])
            ->assertAccepted();
        $this->assertSame('PH', DiscoveryRun::query()->firstOrFail()->scope['country']);
    }

    public function test_an_unsupported_country_is_refused_rather_than_silently_replaced(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        foreach ([
            ['scope' => 'nationwide', 'niches' => ['bakery'], 'country' => 'MY'],
            ['locations' => ['Kota Kinabalu'], 'niches' => ['bakery'], 'country' => 'MY'],
        ] as $payload) {
            // Substituting the default would hand a user Philippine results for a Malaysian request
            // and leave them to notice. Refusing says so.
            $this->postJson(self::ENDPOINT, $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('country');
        }

        $this->assertSame(0, DiscoveryRun::query()->count(), 'no run may be created for an unsupported country');
    }

    public function test_the_country_travels_to_the_engine_on_every_search(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        config()->set('discovery.nationwide.max_tasks', 3);
        $this->fakeEngine();
        $this->startNationwide();
        $this->executeNationwide();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            // The engine confines its geocoder to this, which is what stops a foreign location
            // becoming a provider request at all.
            $this->assertSame('PH', $call['country']);
        }
    }

    // ── no AI, at all ────────────────────────────────────────────────────────────────────────

    public function test_a_nationwide_run_makes_no_model_call(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $this->googleLive();
        $this->fakeEngine();
        $this->startNationwide();

        // `Http::preventStrayRequests()` in setUp() is the real assertion: any call to OpenAI or
        // OpenRouter would raise here rather than being quietly charged. This states it explicitly.
        $this->executeNationwide();

        Http::assertNothingSent();
    }
}
