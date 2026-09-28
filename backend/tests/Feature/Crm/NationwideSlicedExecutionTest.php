<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * H-5 — a nationwide run outlives any single queued job.
 *
 * A paced national sweep is hundreds of searches; one RunDiscoveryJob lives 900 seconds. The run now
 * executes in SLICES: each works the queue until a deadline, checkpoints exactly what is left (down
 * to the niche inside an interrupted cell), and queues its successor. Time is simulated: the fake
 * engine advances the clock, and each "slice" below is one execute() call, exactly what the worker
 * does with each queued continuation.
 *
 * Pinned: complete coverage with no search repeated or skipped; results identical to an unsliced
 * run; mid-cell resume; descent state surviving a slice boundary; cancellation between and within
 * slices; budget across slices; one slice at a time per run; scoped runs never sliced; the
 * configuration fits inside the job timeout. No provider is contacted.
 */
class NationwideSlicedExecutionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Sleep::fake();
        Queue::fake();

        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 2000);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);
        config()->set('discovery.nationwide.slice_seconds', 60);

        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz', 'key_last4' => 'wxyz',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    /** @return array<string, mixed> */
    private function outcome(int $discovered = 2, string $status = 'ok'): array
    {
        $ok = $status === 'ok';

        return [
            'exitCode' => $ok ? 0 : 3, 'executionStatus' => $status, 'errorReason' => null,
            'discovered' => $ok ? $discovered : 0, 'inserted' => $ok ? $discovered : 0, 'duplicates' => 0,
            'rejected' => 0, 'stderrTail' => '', 'strategyApplied' => null, 'strategyStatus' => null,
            'retryAfter' => null,
        ];
    }

    /**
     * An engine whose every search takes `$seconds` of (simulated) wall time.
     *
     * @param  null|callable(int, array<string, mixed>): array<string, mixed>  $respond
     */
    private function slowEngine(int $seconds, ?callable $respond = null): void
    {
        $test = $this;
        $this->app->instance(ScraperProcessRunner::class, new class($test, $seconds, $respond) extends ScraperProcessRunner
        {
            public function __construct(private $test, private int $seconds, private $respond) {}

            public function run(string $area, string $category, int $limit, string $provider, string $metricsPath,
                ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                $call = ['category' => $category, 'bbox' => $bbox, 'area' => $area];
                $this->test->calls[] = $call;
                $this->test->travel($this->seconds)->seconds();

                return $this->respond === null
                    ? $this->test->defaultOutcome()
                    : ($this->respond)(count($this->test->calls), $call);
            }
        });
    }

    /** @internal for the engine double */
    public function defaultOutcome(): array
    {
        return $this->outcome();
    }

    private function startNationwide(array $niches = ['bakery']): DiscoveryRun
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/crm/discovery/runs', ['scope' => 'nationwide', 'niches' => $niches])
            ->assertStatus(202);

        return DiscoveryRun::query()->latest('id')->firstOrFail();
    }

    private function slice(DiscoveryRun $run): DiscoveryRun
    {
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    /** Run slices until the run ends, as the worker would. Returns the number of slices executed. */
    private function drain(DiscoveryRun $run, int $max = 200): int
    {
        $slices = 0;
        do {
            $this->slice($run);
            $slices++;
        } while (! $run->isTerminal() && $slices < $max);

        $this->assertTrue($run->isTerminal(), 'the run never finished');

        return $slices;
    }

    // ── a long run continues, covers everything, and repeats nothing ────────────────────────

    public function test_a_long_nationwide_run_continues_across_slices_and_covers_every_cell_once(): void
    {
        $run = $this->startNationwide();
        $this->slowEngine(25); // ~3 searches per 60s slice

        $slices = $this->drain($run);

        $this->assertGreaterThan(2, $slices, 'the sweep must span several jobs');
        $this->assertSame($slices - 1, (int) $run->continuations);
        // The start request queued one job; every continuation queued one more, on `discovery`.
        Queue::assertPushedOn('discovery', RunDiscoveryJob::class);
        Queue::assertPushed(RunDiscoveryJob::class, 1 + (int) $run->continuations);

        $boxes = array_map(fn (array $c): string => implode(',', $c['bbox']), $this->calls);
        $this->assertSame(count($boxes), count(array_unique($boxes)), 'no cell was searched twice');
        $this->assertSame((int) $run->cells_planned, count($boxes), 'no cell was skipped');
        $this->assertSame((int) $run->cells_planned, (int) $run->cells_searched);
        $this->assertSame((int) $run->cells_planned, (int) $run->cells_exhausted);
        $this->assertSame(count($this->calls), (int) $run->budget_used);
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('exhausted', $run->stopReason());
        $this->assertNull($run->work_queue, 'no checkpoint survives a finished run');
        $this->assertSame(0, PersonalAccessToken::query()->count(), 'every slice revoked its token');
    }

    public function test_a_sliced_run_reaches_exactly_the_result_of_an_unsliced_one(): void
    {
        $counters = fn (DiscoveryRun $r): array => [
            'cells_planned' => (int) $r->cells_planned, 'cells_searched' => (int) $r->cells_searched,
            'cells_exhausted' => (int) $r->cells_exhausted, 'cells_capped' => (int) $r->cells_capped,
            'cells_subdivided' => (int) $r->cells_subdivided, 'targets_total' => (int) $r->targets_total,
            'budget_used' => (int) $r->budget_used, 'inserted' => (int) $r->inserted,
        ];
        // Every 3rd search fills its page, so the descent (subdivision) is exercised too.
        $respond = fn (int $n) => $this->outcome($n % 3 === 0 ? 20 : 2);

        config()->set('discovery.nationwide.slice_seconds', 100000);
        $single = $this->startNationwide();
        $this->slowEngine(25, $respond);
        $this->assertSame(1, $this->drain($single));
        $unsliced = $counters($single);

        $this->calls = [];
        config()->set('discovery.nationwide.slice_seconds', 60);
        $sliced = $this->startNationwide();
        $this->slowEngine(25, $respond);
        $this->assertGreaterThan(1, $this->drain($sliced));

        $this->assertSame($unsliced, $counters($sliced));
    }

    // ── a cell interrupted between its niches resumes exactly there ─────────────────────────

    public function test_a_cell_interrupted_between_niches_resumes_at_the_next_niche(): void
    {
        $niches = ['bakery', 'cafe', 'dental clinic'];
        $run = $this->startNationwide($niches);
        $this->slowEngine(40); // 2 searches fit a 60s slice, the 3rd niche does not

        $run = $this->slice($run);

        $this->assertCount(2, $this->calls);
        $this->assertSame(2, (int) $run->work_queue[0]['nichesDone'], 'the checkpoint records how far into the cell it got');
        $this->assertSame(0, (int) $run->cells_searched, 'the interrupted cell is not concluded yet');

        $this->drain($run);

        $pairs = array_map(fn (array $c): string => implode(',', $c['bbox']).'|'.$c['category'], $this->calls);
        $this->assertSame(count($pairs), count(array_unique($pairs)), 'no (cell, niche) was searched twice');
        $this->assertSame((int) $run->cells_planned * count($niches), count($pairs), 'none was skipped');
        $this->assertSame((int) $run->cells_planned, (int) $run->cells_searched);
    }

    public function test_the_descent_survives_a_slice_boundary(): void
    {
        // First cell: bakery fills its page, then the slice ends before cafe. The "there is more
        // here" observation must survive the checkpoint, and the children must hang off the right
        // parent search.
        $run = $this->startNationwide(['bakery', 'cafe']);
        $this->slowEngine(70, fn (int $n) => $this->outcome($n === 1 ? 20 : 2));

        $run = $this->slice($run);
        $this->assertCount(1, $this->calls);
        $this->assertTrue((bool) $run->work_queue[0]['truncated']);

        $this->drain($run);

        $this->assertGreaterThan(0, (int) $run->cells_subdivided);
        $parent = DiscoveryRunTarget::query()->orderBy('id')->firstOrFail();
        $children = DiscoveryRunTarget::query()->where('depth', 1)->get();
        $this->assertNotEmpty($children);
        $this->assertTrue($children->every(fn ($t) => (int) $t->parent_id === (int) $parent->id));
    }

    // ── cancellation ─────────────────────────────────────────────────────────────────────────

    public function test_cancelling_between_slices_stops_the_run_and_its_successor_does_nothing(): void
    {
        $run = $this->startNationwide();
        $this->slowEngine(25);
        $run = $this->slice($run);
        $this->assertNotNull($run->work_queue);
        $searched = count($this->calls);
        $pushed = Queue::pushed(RunDiscoveryJob::class)->count();

        $this->postJson("/api/v1/crm/discovery/runs/{$run->uuid}/cancel")->assertOk();

        // The already-queued continuation runs, and stands down.
        $run = $this->slice($run);

        $this->assertSame(DiscoveryRun::STATUS_CANCELLED, $run->status);
        $this->assertCount($searched, $this->calls, 'no search after the cancel');
        $this->assertNull($run->work_queue, 'the checkpoint is discarded');
        $this->assertSame($pushed, Queue::pushed(RunDiscoveryJob::class)->count(), 'no further continuation');
    }

    public function test_cancelling_within_a_slice_stops_before_the_next_search_and_queues_nothing(): void
    {
        $run = $this->startNationwide();
        $this->slowEngine(5, function (int $n) use ($run) {
            if ($n === 2) {
                DiscoveryRun::query()->whereKey($run->id)->update(['status' => DiscoveryRun::STATUS_CANCELLED]);
            }

            return $this->outcome();
        });

        $run = $this->slice($run);

        $this->assertCount(2, $this->calls);
        $this->assertSame(DiscoveryRun::STATUS_CANCELLED, $run->status);
        $this->assertNull($run->work_queue);
        Queue::assertPushed(RunDiscoveryJob::class, 1); // only the original start
    }

    // ── budget, exclusivity, and what stays unsliced ─────────────────────────────────────────

    public function test_the_budget_holds_across_slices(): void
    {
        config()->set('discovery.nationwide.max_tasks', 5);
        $run = $this->startNationwide();
        $this->slowEngine(25);

        $this->drain($run);

        $this->assertCount(5, $this->calls, 'the budget is a hard ceiling whatever the slicing');
        $this->assertSame(5, (int) $run->budget_used);
        $this->assertSame('budget_limited', $run->stopReason());
        $this->assertNull($run->work_queue);
    }

    public function test_only_one_slice_of_a_run_executes_at_a_time(): void
    {
        $run = $this->startNationwide();
        $this->slowEngine(25);

        $held = Cache::lock('crm:discovery-run:'.$run->uuid, 60);
        $this->assertTrue($held->get());
        try {
            $this->slice($run);
            $this->assertSame([], $this->calls, 'a second job must stand down, not search concurrently');
        } finally {
            $held->release();
        }

        $this->slice($run);
        $this->assertNotSame([], $this->calls);
    }

    public function test_a_crashed_slice_leaves_no_checkpoint(): void
    {
        $run = $this->startNationwide();
        $this->slowEngine(25);
        $run = $this->slice($run);
        $this->assertNotNull($run->work_queue);

        (new RunDiscoveryJob($run->uuid, (int) $run->org_id))->failed(new \RuntimeException('killed'));

        $run->refresh();
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->work_queue);
    }

    public function test_a_scoped_run_is_never_sliced(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/crm/discovery/runs', [
            'locations' => ['Makati', 'Pasig', 'Taguig'], 'niches' => ['bakery'],
        ])->assertStatus(202);
        $run = DiscoveryRun::query()->latest('id')->firstOrFail();
        $this->slowEngine(100); // far past the slice deadline

        $run = $this->slice($run);

        $this->assertCount(3, $this->calls, 'a scoped run finishes in the job that started it');
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(0, (int) $run->continuations);
        $this->assertNull($run->work_queue);
        Queue::assertPushed(RunDiscoveryJob::class, 1);
    }

    public function test_the_shipped_slice_settings_fit_inside_the_job_timeout(): void
    {
        // The SHIPPED defaults (this test's setUp shortens the slice), read from the config file.
        $defaults = require config_path('discovery.php');
        $timeout = (new RunDiscoveryJob('x', 1))->timeout;

        $rate = $defaults['rate_limit'];
        $rateWaits = 0;
        for ($i = 0; $i < $rate['max_retries']; $i++) {
            $rateWaits += min($rate['base_seconds'] * (2 ** $i), $rate['max_seconds']);
        }

        // The deadline is only checked BETWEEN searches, so the one in flight when it passes — a
        // full-length search plus its rate-limit waits (refusals themselves return immediately) —
        // must still finish before the worker kills the job.
        $this->assertLessThan(
            $timeout,
            $defaults['nationwide']['slice_seconds'] + $defaults['task_timeout'] + $rateWaits,
        );
        $this->assertGreaterThan($timeout, $defaults['nationwide']['lock_seconds'], 'the lock must outlive a job');
    }
}
