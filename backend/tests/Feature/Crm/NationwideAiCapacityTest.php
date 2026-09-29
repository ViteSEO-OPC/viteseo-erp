<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI-ASSISTED CAPACITY MODE (`discovery.nationwide.ai_total_limit`), end to end through a nationwide
 * run with the engine replaced by a recorder.
 *
 *  - OFF unless configured, and only ever for the combined `openai` provider: OpenStreetMap, Google
 *    Places and an unconfigured `openai` run make exactly the calls they always made.
 *  - The per-cell limit stays 20, and a cell subdivides only when the OpenStreetMap SWEEP fills it.
 *  - The AI lanes run once per (area, niche) per run; a failed attempt is retried by the next cell.
 */
class NationwideAiCapacityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 12);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);
        config()->set('discovery.nationwide.ai_total_limit', null);
    }

    protected function tearDown(): void
    {
        // Each run's metrics file lands in the real storage directory; leave nothing behind.
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

    /**
     * Replace the engine. Each call is recorded with WHICH entry point it used and its flags, and — like
     * the real engine — appends a metrics record, so the run's own "did the AI lanes run here" check
     * reads what the searches actually reported.
     *
     * @param  callable(int, array<string, mixed>): array{discovered?: int, baseline?: int|null, ai?: string|null}  $respond
     */
    private function fakeEngine(callable $respond): void
    {
        $test = $this;
        $this->app->instance(ScraperProcessRunner::class, new class($test, $respond) extends ScraperProcessRunner
        {
            public function __construct(private $test, private $respond) {}

            public function run(string $area, string $category, int $limit, string $provider, string $metricsPath,
                ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                return $this->test->engine('run', [], compact('area', 'category', 'limit', 'provider', 'metricsPath'), $this->respond);
            }

            public function runWithEngineFlags(array $engineFlags, string $area, string $category, int $limit, string $provider,
                string $metricsPath, ?array $bbox = null, ?string $country = null, bool $aiStrategy = false, ?string $crmToken = null): array
            {
                return $this->test->engine('runWithEngineFlags', $engineFlags, compact('area', 'category', 'limit', 'provider', 'metricsPath'), $this->respond);
            }
        });
    }

    /** @internal Used by the engine fake. */
    public function engine(string $via, array $flags, array $call, callable $respond): array
    {
        $this->calls[] = [...$call, 'via' => $via, 'flags' => $flags];
        $out = $respond(count($this->calls), $call + ['flags' => $flags]);
        $discovered = $out['discovered'] ?? 5;
        $record = ['area' => $call['area'], 'category' => $call['category'], 'discovered' => $discovered];
        if (array_key_exists('ai', $out)) {
            $record['ai_execution_status'] = $out['ai'];
            $record['baseline_discovered'] = $out['baseline'] ?? $discovered;
        }
        @mkdir(dirname($call['metricsPath']), 0775, true);
        file_put_contents($call['metricsPath'], json_encode($record)."\n", FILE_APPEND);

        return [
            'exitCode' => 0, 'executionStatus' => 'ok', 'errorReason' => null,
            'discovered' => $discovered, 'inserted' => 0, 'duplicates' => $discovered, 'rejected' => 0,
            'stderrTail' => '', 'strategyApplied' => null, 'strategyStatus' => null, 'retryAfter' => null,
            'baselineDiscovered' => array_key_exists('ai', $out) ? ($out['baseline'] ?? $discovered) : null,
        ];
    }

    private function runNationwide(): DiscoveryRun
    {
        Queue::fake();
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/crm/discovery/runs', ['scope' => 'nationwide', 'niches' => ['bakery']])->assertAccepted();

        $run = DiscoveryRun::query()->where('scope_class', DiscoveryRun::SCOPE_NATIONWIDE)->firstOrFail();
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    // ── off unless configured, and only for the combined openai provider ──────────────────────

    public function test_unconfigured_an_ai_assisted_run_makes_exactly_its_ordinary_calls(): void
    {
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 5]);

        $this->runNationwide();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame('run', $call['via']);
            $this->assertSame('openai', $call['provider']);
            $this->assertSame(20, $call['limit']);
        }
    }

    public function test_an_openstreetmap_run_ignores_the_setting(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->fakeEngine(fn () => ['discovered' => 5]);

        $this->runNationwide();

        $this->assertNotEmpty($this->calls);
        $this->assertSame(['run'], array_values(array_unique(array_column($this->calls, 'via'))));
        $this->assertSame(['openstreetmap'], array_values(array_unique(array_column($this->calls, 'provider'))));
    }

    public function test_a_google_places_run_ignores_the_setting(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->googleLive();
        $this->fakeEngine(fn () => ['discovered' => 5]);

        $this->runNationwide();

        $this->assertNotEmpty($this->calls);
        $this->assertSame(['run'], array_values(array_unique(array_column($this->calls, 'via'))));
        $this->assertSame(['google_places'], array_values(array_unique(array_column($this->calls, 'provider'))));
        $this->assertSame([20], array_values(array_unique(array_column($this->calls, 'limit'))));
    }

    public function test_a_capacity_no_larger_than_the_per_cell_limit_changes_nothing(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 20);
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 5]);

        $this->runNationwide();

        $this->assertSame(['run'], array_values(array_unique(array_column($this->calls, 'via'))));
    }

    // ── capacity mode ────────────────────────────────────────────────────────────────────────

    public function test_configured_every_ai_assisted_search_carries_the_capacity_and_keeps_the_per_cell_limit(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 5, 'ai' => 'ok']);

        $this->runNationwide();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertSame('runWithEngineFlags', $call['via']);
            $this->assertSame(['--ai-total-limit', '100'], array_slice($call['flags'], 0, 2));
            $this->assertSame(20, $call['limit'], 'the per-cell limit (and so the sweep) stays 20');
        }
    }

    public function test_the_ai_lanes_run_once_per_area_and_niche(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 5, 'ai' => 'ok']);

        $this->runNationwide();

        $seen = [];
        foreach ($this->calls as $call) {
            $key = $call['area'].'|'.$call['category'];
            $skipped = in_array('--skip-ai-lanes', $call['flags'], true);
            $this->assertSame(isset($seen[$key]), $skipped, "{$key}: only a repeat of a completed AI search is sweep-only");
            $seen[$key] = true;
        }
        $this->assertGreaterThan(count($seen), count($this->calls), 'the tiling repeats area names, so something was skipped');
    }

    public function test_a_failed_ai_attempt_is_retried_by_the_next_cell_with_the_same_area(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->openaiLive();
        $attempts = [];
        $this->fakeEngine(function (int $n, array $call) use (&$attempts): array {
            $key = $call['area'].'|'.$call['category'];
            $attempts[$key] = ($attempts[$key] ?? 0) + 1;

            // Every area's FIRST AI attempt times out; the second succeeds.
            return ['discovered' => 5, 'ai' => $attempts[$key] === 1 ? 'provider_errored' : 'ok'];
        });

        $this->runNationwide();

        $byKey = [];
        foreach ($this->calls as $call) {
            $byKey[$call['area'].'|'.$call['category']][] = in_array('--skip-ai-lanes', $call['flags'], true);
        }
        $repeated = array_filter($byKey, fn ($skips) => count($skips) >= 3);
        $this->assertNotEmpty($repeated, 'the tiling gives some area three or more cells');
        foreach ($repeated as $key => $skips) {
            $this->assertFalse($skips[0], "{$key}: first attempt runs the AI lanes");
            $this->assertFalse($skips[1], "{$key}: the failed attempt is retried");
            $this->assertTrue($skips[2], "{$key}: after a completed attempt, repeats are sweep-only");
        }
    }

    // ── the descent is the sweep's ───────────────────────────────────────────────────────────

    public function test_ai_capacity_above_the_limit_does_not_subdivide_a_cell_the_sweep_did_not_fill(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->openaiLive();
        $this->fakeEngine(fn () => ['discovered' => 80, 'baseline' => 12, 'ai' => 'ok']);

        $run = $this->runNationwide();

        $this->assertSame(0, (int) $run->cells_subdivided);
        $this->assertSame(0, $run->targets()->where('depth', '>', 0)->count());
    }

    public function test_a_cell_the_sweep_fills_is_subdivided_exactly_as_before(): void
    {
        config()->set('discovery.nationwide.ai_total_limit', 100);
        $this->openaiLive();
        $this->fakeEngine(fn (int $n, array $call) => ['discovered' => 20, 'baseline' => 20, 'ai' => 'ok']);
        $withCapacity = $this->runNationwide();
        $capacityChildren = $withCapacity->targets()->where('depth', '>', 0)->count();

        $this->assertGreaterThan(0, (int) $withCapacity->cells_subdivided);
        $this->assertGreaterThan(0, $capacityChildren);
    }
}
