<?php

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * VOLUME MODE (`discovery.nationwide.volume`) end to end, with the engine replaced by a recorder.
 *
 *  - OFF unless enabled, and only for the combined `openai` provider: every other provider, and an
 *    `openai` run with it off, make exactly the calls they always made.
 *  - The descent subdivides on the RAW read (cap reached) or on more new businesses than the task may
 *    return — never on the named count, which is what used to end dense cells as "exhausted".
 *  - Cells are ordered by what the org does not hold yet.
 *  - The known-places pre-filter is machine-only, org-scoped and answers only what was asked.
 */
class NationwideVolumeModeTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var array{status: string, points: list<array{0: float, 1: float}>, ids?: list<string>} */
    public array $probe = ['status' => 'failed', 'points' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 6);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);
        config()->set('discovery.nationwide.ai_total_limit', null);
        config()->set('discovery.nationwide.density_ordering', false);
        config()->set('discovery.nationwide.volume.enabled', false);
    }

    protected function tearDown(): void
    {
        foreach (DiscoveryRun::query()->pluck('uuid') as $uuid) {
            @unlink(storage_path('app/discovery/'.$uuid.'.jsonl'));
        }
        parent::tearDown();
    }

    private function openaiLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => true, 'enabled' => true,
            'api_key' => 'sk-test-not-a-real-credential-0000abcd', 'key_last4' => 'abcd',
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    private function googleLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz', 'key_last4' => 'wxyz',
        ]);
    }

    /** @param callable(int, array<string, mixed>): array<string, mixed> $respond */
    private function fakeEngine(callable $respond): void
    {
        $test = $this;
        $this->app->instance(ScraperProcessRunner::class, new class($test, $respond) extends ScraperProcessRunner
        {
            public function __construct(private $test, private $respond) {}

            public function run(string $area, string $category, int $limit, string $provider, string $metricsPath,
                ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                return $this->test->engine('run', [], compact('area', 'category', 'limit', 'provider', 'bbox'), $this->respond);
            }

            public function runWithEngineFlags(array $engineFlags, string $area, string $category, int $limit, string $provider,
                string $metricsPath, ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                return $this->test->engine('runWithEngineFlags', $engineFlags, compact('area', 'category', 'limit', 'provider', 'bbox'), $this->respond);
            }

            public function density(string $niche, string $country): array
            {
                return $this->test->probe;
            }
        });
    }

    /** @internal Used by the engine fake. */
    public function engine(string $via, array $flags, array $call, callable $respond): array
    {
        $this->calls[] = [...$call, 'via' => $via, 'flags' => $flags];
        $out = $respond(count($this->calls), $call + ['flags' => $flags]);
        $discovered = $out['discovered'] ?? 5;

        return [
            'exitCode' => 0, 'executionStatus' => 'ok', 'errorReason' => null,
            'discovered' => $discovered, 'inserted' => $out['inserted'] ?? 0,
            'duplicates' => $discovered - ($out['inserted'] ?? 0), 'rejected' => 0,
            'stderrTail' => '', 'strategyApplied' => null, 'strategyStatus' => null, 'retryAfter' => null,
            'baselineDiscovered' => $out['baseline'] ?? null,
            'volume' => $out['volume'] ?? null,
        ];
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    private function runNationwide(?callable $before = null, int $budget = 6): DiscoveryRun
    {
        Queue::fake();
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        if ($before !== null) {
            $before($admin);
        }
        $this->postJson('/api/v1/crm/discovery/runs', [
            'scope' => 'nationwide', 'niches' => ['dentist'], 'max_tasks' => $budget,
        ])->assertAccepted();

        $run = DiscoveryRun::query()->where('scope_class', DiscoveryRun::SCOPE_NATIONWIDE)->latest('id')->firstOrFail();
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    private function volumeRead(bool $rawTruncated = false, bool $newCapped = false, int $new = 5): array
    {
        return ['rawElements' => $rawTruncated ? 400 : 30, 'rawTruncated' => $rawTruncated, 'newCandidates' => $new,
            'newReturned' => min($new, 100), 'newCapped' => $newCapped, 'known' => 3];
    }

    // ── off unless enabled, and only for the combined openai provider ─────────────────────────

    public function test_off_an_ai_assisted_run_makes_exactly_its_ordinary_calls(): void
    {
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 5]);

        $this->runNationwide();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame('run', $call['via']);
            $this->assertSame([], $call['flags']);
        }
    }

    public function test_on_an_ai_assisted_search_carries_the_volume_flags_and_not_capacity_mode(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 5, 'volume' => $this->volumeRead()]);

        $this->runNationwide();

        foreach ($this->calls as $call) {
            $this->assertSame('runWithEngineFlags', $call['via']);
            $this->assertSame(['--volume', '--raw-cap', '400', '--task-new-cap', '100'], $call['flags']);
            $this->assertSame(20, $call['limit'], 'the per-search limit passed to the engine is unchanged');
        }
    }

    public function test_google_places_never_sees_volume_mode(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        $this->googleLive();
        $this->fakeEngine(fn () => ['discovered' => 5]);

        $this->runNationwide();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame('google_places', $call['provider']);
            $this->assertSame('run', $call['via']);
            $this->assertSame([], $call['flags']);
        }
    }

    // ── the descent: raw read, not named count ────────────────────────────────────────────────

    public function test_a_raw_truncated_cell_subdivides_even_though_fewer_than_twenty_came_back(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        $this->openaiLive();
        // The first cell read the whole cap but only 17 named rows came back — exactly the QC case.
        $this->fakeEngine(fn (int $n) => $n === 1
            ? ['discovered' => 17, 'volume' => $this->volumeRead(rawTruncated: true, new: 17)]
            : ['discovered' => 3, 'volume' => $this->volumeRead(new: 3)]);

        $run = $this->runNationwide();

        $this->assertSame(1, (int) $run->cells_subdivided);
        // The first cell's children come next, each inside it.
        [$ps, $pw, $pn, $pe] = $this->calls[0]['bbox'];
        [$s, $w, $n, $e] = $this->calls[1]['bbox'];
        $this->assertTrue($s >= $ps && $w >= $pw && $n <= $pn && $e <= $pe, 'a child of the first cell was searched next');
        $first = $run->targets()->orderBy('id')->first();
        $this->assertGreaterThan(0, $run->targets()->where('parent_id', $first->id)->count());
    }

    public function test_a_cell_with_more_new_businesses_than_the_cap_subdivides(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        $this->openaiLive();
        $this->fakeEngine(fn (int $n) => $n === 1
            ? ['discovered' => 120, 'volume' => $this->volumeRead(newCapped: true, new: 157)]
            : ['discovered' => 3, 'volume' => $this->volumeRead(new: 3)]);

        $run = $this->runNationwide();

        $this->assertSame(1, (int) $run->cells_subdivided);
    }

    public function test_a_fully_read_cell_is_exhausted_even_when_it_returned_twenty_or_more(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        $this->openaiLive();
        // 60 rows returned (all known) from a cell read to the end: nothing more is there.
        $this->fakeEngine(fn () => ['discovered' => 60, 'volume' => $this->volumeRead(new: 0)]);

        $run = $this->runNationwide();

        $this->assertSame(0, (int) $run->cells_subdivided);
        $this->assertSame((int) $run->cells_searched, (int) $run->cells_exhausted);
    }

    public function test_outside_volume_mode_the_named_count_still_decides_exactly_as_before(): void
    {
        $this->openaiLive();
        $this->fakeEngine(fn (int $n) => ['discovered' => $n === 1 ? 20 : 3]);

        $run = $this->runNationwide();

        $this->assertSame(1, (int) $run->cells_subdivided);
    }

    // ── ordering by what the org does not hold yet ─────────────────────────────────────────────

    public function test_cells_are_ordered_by_businesses_the_org_does_not_hold_yet(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        $this->openaiLive();
        // Manila holds 3 dentists, all already leads; Baguio holds 2 the org has never seen. Plain
        // density would search Manila first; volume mode searches Baguio first.
        $this->probe = [
            'status' => 'ok',
            'points' => [[14.60, 121.00], [14.61, 121.01], [14.62, 121.02], [16.41, 120.59], [16.42, 120.60]],
            'ids' => ['node/1', 'node/2', 'node/3', 'node/4', 'node/5'],
        ];
        $this->fakeEngine(fn () => ['discovered' => 3, 'volume' => $this->volumeRead(new: 0)]);

        $this->runNationwide(function (User $admin): void {
            foreach (['node/1', 'node/2', 'node/3'] as $placeId) {
                Lead::factory()->create(['created_by' => $admin->id, 'source' => 'openstreetmap', 'place_id' => $placeId]);
            }
        });

        [$south, $west, $north, $east] = $this->calls[0]['bbox'];
        $this->assertTrue($south <= 16.41 && $north >= 16.41 && $west <= 120.59 && $east >= 120.59,
            'the first search is the cell with the most unknown businesses (Baguio)');
    }

    public function test_an_engine_that_reports_no_ids_falls_back_without_failing_the_run(): void
    {
        config()->set('discovery.nationwide.volume.enabled', true);
        $this->openaiLive();
        $this->probe = ['status' => 'ok', 'points' => [[14.6, 121.0]]]; // an older engine: no ids
        $this->fakeEngine(fn () => ['discovered' => 3, 'volume' => $this->volumeRead(new: 0)]);

        $run = $this->runNationwide();

        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertNotEmpty($this->calls);
    }

    // ── the known-places pre-filter ───────────────────────────────────────────────────────────

    public function test_known_places_answers_only_the_asked_keys_this_org_holds(): void
    {
        $other = Org::create(['name' => 'Other Clinic Co', 'is_active' => true]);
        Entitlements::enable($other->id, ['crm']);
        $admin = $this->admin();
        CurrentOrg::runAs($this->tenant()->id, function () use ($admin): void {
            Lead::factory()->create(['created_by' => $admin->id, 'source' => 'openstreetmap', 'place_id' => 'node/1']);
            Lead::factory()->create(['created_by' => $admin->id, 'source' => 'openstreetmap', 'place_id' => 'node/9']);
        });
        CurrentOrg::runAs($other->id, fn () => Lead::factory()->create(['created_by' => $admin->id, 'source' => 'openstreetmap', 'place_id' => 'node/2']));

        $this->actingAsNerbyService($this->tenant()->id);
        $known = $this->postJson('/api/v1/crm/discovery/known-places', ['place_ids' => ['node/1', 'node/2', 'node/3']])
            ->assertOk()->json('data.known');

        // node/9 is held but was not asked about; node/2 is another org's.
        $this->assertSame(['node/1'], $known);
    }

    public function test_known_places_is_machine_only_and_bounded(): void
    {
        foreach (['crm_admin', 'super_admin', 'crm_bd'] as $role) {
            $this->app['auth']->forgetGuards();
            $user = User::factory()->create(['status' => 'active']);
            $user->assignRole($role);
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/crm/discovery/known-places', ['place_ids' => ['node/1']])->assertForbidden();
        }

        $this->actingAsNerbyService();
        $this->postJson('/api/v1/crm/discovery/known-places', ['place_ids' => []])->assertUnprocessable();
        $this->postJson('/api/v1/crm/discovery/known-places', [
            'place_ids' => array_map(fn ($i) => "node/{$i}", range(1, 501)),
        ])->assertUnprocessable();
    }

    // ── safety arithmetic and the engine's report ─────────────────────────────────────────────

    public function test_a_full_task_fits_inside_the_ai_assisted_task_timeout(): void
    {
        $geocoding = (int) config('discovery.nationwide.volume.task_cap') * 1.1;
        $sweep = 75;
        $this->assertLessThanOrEqual((int) config('discovery.task_timeout_by_provider.openai'), $geocoding + $sweep);
    }

    public function test_the_runner_reads_the_engines_volume_report(): void
    {
        Process::fake(function (PendingProcess $process) {
            $env = $process->environment;
            file_put_contents($env['DISCOVERY_METRICS_FILE'], json_encode([
                'provider_execution_status' => 'ok', 'discovered' => 117, 'imported' => 100, 'duplicates' => 17,
                'volume' => true, 'volume_raw_elements' => 189, 'volume_raw_truncated' => false,
                'volume_new_candidates' => 157, 'volume_new_returned' => 100, 'volume_new_capped' => true, 'volume_known' => 17,
            ])."\n", FILE_APPEND);

            return Process::result('', '', 0);
        });

        $path = storage_path('app/discovery/volume-runner-test.jsonl');
        @unlink($path);
        $result = app(ScraperProcessRunner::class)->runWithEngineFlags(['--volume'], 'QC', 'dentist', 20, 'openai', $path, [14.6, 121.0, 14.9, 121.2]);
        @unlink($path);

        $this->assertSame(100, $result['inserted']);
        $this->assertSame(['rawElements' => 189, 'rawTruncated' => false, 'newCandidates' => 157,
            'newReturned' => 100, 'newCapped' => true, 'known' => 17], $result['volume']);
        Process::assertRan(fn (PendingProcess $p) => in_array('--volume', (array) $p->command, true));
    }
}
