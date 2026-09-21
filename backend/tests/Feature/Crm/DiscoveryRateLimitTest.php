<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * H-4 — a search refused for RATE is not an empty area.
 *
 * Measured before the fix: nationwide runs lost 287–314 of 400 searches to HTTP 429 from the CRM's own
 * callback throttle (one counter shared by every run, because every run calls back as the one service
 * account), the refusals were labelled `crm_unreachable`, every refused cell was counted as EXHAUSTED,
 * and the run reported "the provider had nothing new left" for ground it never searched.
 *
 * Pinned here: bounded, cancellable backoff that honours Retry-After; no provider degradation and no
 * extra budget for a refusal; a cell with a failed search is never exhausted; a run with failed
 * searches never claims `exhausted`; the service identity is throttled per run token while humans keep
 * their exact per-user throttle. No Google request is made anywhere in this file.
 */
class DiscoveryRateLimitTest extends TestCase
{
    use ActsAsNerbyService;
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

        config()->set('discovery.rate_limit.max_retries', 3);
        config()->set('discovery.rate_limit.base_seconds', 10);
        config()->set('discovery.rate_limit.max_seconds', 60);
        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 12);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);

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
    private function outcome(string $status = 'ok', int $discovered = 3, ?int $retryAfter = null): array
    {
        $ok = $status === 'ok';

        return [
            'exitCode' => $ok ? 0 : 3,
            'executionStatus' => $status,
            'errorReason' => $ok ? null : 'http_429',
            'discovered' => $ok ? $discovered : 0,
            'inserted' => $ok ? $discovered : 0,
            'duplicates' => 0,
            'rejected' => 0,
            'stderrTail' => '',
            'strategyApplied' => null,
            'strategyStatus' => null,
            'retryAfter' => $retryAfter,
        ];
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
                $call = compact('area', 'category', 'provider', 'bbox');
                $this->test->calls[] = $call;

                return ($this->respond)(count($this->test->calls), $call);
            }
        });
    }

    private function startScoped(array $locations = ['Makati']): DiscoveryRun
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/crm/discovery/runs', ['locations' => $locations, 'niches' => ['bakery']])
            ->assertStatus(202);

        return DiscoveryRun::query()->latest('id')->firstOrFail();
    }

    private function execute(DiscoveryRun $run): DiscoveryRun
    {
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    // ── retry and backoff ────────────────────────────────────────────────────────────────────

    public function test_a_rate_refusal_is_retried_after_a_pause_and_then_succeeds(): void
    {
        $run = $this->startScoped();
        $this->fakeEngine(fn (int $n) => $n === 1 ? $this->outcome('rate_limited') : $this->outcome('ok', 4));

        $run = $this->execute($run);

        $this->assertCount(2, $this->calls, 'one refusal, one successful retry');
        Sleep::assertSequence([Sleep::for(10)->seconds()]);
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, (int) $run->targets_completed);
        $this->assertSame(0, (int) $run->targets_failed);
        $this->assertSame(4, (int) $run->inserted);
    }

    public function test_the_servers_retry_after_is_honoured_and_the_wait_is_capped(): void
    {
        $run = $this->startScoped();
        $this->fakeEngine(fn (int $n) => match ($n) {
            1 => $this->outcome('rate_limited', retryAfter: 45),
            2 => $this->outcome('rate_limited', retryAfter: 900),
            default => $this->outcome('ok'),
        });

        $this->execute($run);

        Sleep::assertSequence([Sleep::for(45)->seconds(), Sleep::for(60)->seconds()]);
    }

    public function test_retries_are_bounded_and_never_degrade_to_openstreetmap(): void
    {
        $run = $this->startScoped();
        $this->fakeEngine(fn () => $this->outcome('rate_limited'));

        $run = $this->execute($run);

        // 1 attempt + 3 retries, with 10s, 20s, 40s between them.
        $this->assertCount(4, $this->calls);
        Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(20)->seconds(), Sleep::for(40)->seconds()]);
        foreach ($this->calls as $call) {
            $this->assertSame('google_places', $call['provider'], 'a quota is not a bad credential');
        }
        $this->assertNull($run->provider_degraded_at);
        $this->assertSame('google_places', $run->provider_effective);

        $target = DiscoveryRunTarget::query()->firstOrFail();
        $this->assertSame(DiscoveryRunTarget::STATUS_FAILED, $target->status);
        $this->assertSame('rate_limited', $target->execution_status);
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
    }

    public function test_cancelling_during_a_backoff_stops_retrying(): void
    {
        $run = $this->startScoped(['Makati', 'Pasig']);
        $this->fakeEngine(function (int $n) use ($run) {
            // The user presses Cancel while the first search is being refused.
            DiscoveryRun::query()->whereKey($run->id)->update(['status' => DiscoveryRun::STATUS_CANCELLED]);

            return $this->outcome('rate_limited');
        });

        $run = $this->execute($run);

        $this->assertCount(1, $this->calls, 'no retry and no next search after a cancel');
        Sleep::assertNeverSlept();
        $this->assertSame(DiscoveryRun::STATUS_CANCELLED, $run->status);
    }

    public function test_other_failures_are_not_retried(): void
    {
        $run = $this->startScoped();
        $this->fakeEngine(fn () => $this->outcome('crm_unreachable'));

        $this->execute($run);

        $this->assertCount(1, $this->calls);
        Sleep::assertNeverSlept();
    }

    // ── nationwide: a refused cell is not an exhausted cell ──────────────────────────────────

    private function startNationwide(): DiscoveryRun
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/crm/discovery/runs', ['scope' => 'nationwide', 'niches' => ['bakery']])
            ->assertStatus(202);

        return DiscoveryRun::query()->latest('id')->firstOrFail();
    }

    public function test_a_throttled_nationwide_sweep_reports_no_cell_as_exhausted(): void
    {
        config()->set('discovery.rate_limit.max_retries', 0);
        $run = $this->startNationwide();
        $this->fakeEngine(fn () => $this->outcome('rate_limited'));

        $run = $this->execute($run);

        $this->assertGreaterThan(0, (int) $run->cells_searched);
        $this->assertSame(0, (int) $run->cells_exhausted, 'a refused cell was never asked, so it is not exhausted');
        $this->assertSame(0, (int) $run->cells_capped);
        $this->assertNotSame('exhausted', $run->stopReason());
        $this->assertSame(0, (int) $run->budget_used - count($this->calls), 'a refusal takes no extra budget');
    }

    public function test_an_honestly_empty_sweep_is_still_exhausted(): void
    {
        // The control: the fix must not stop genuine exhaustion being reported. A budget that covers
        // the whole plan, so the only possible endings are exhausted or not.
        config()->set('discovery.nationwide.max_tasks', 2000);
        $run = $this->startNationwide();
        $this->fakeEngine(fn () => $this->outcome('ok', 2));

        $run = $this->execute($run);

        $this->assertSame((int) $run->cells_searched, (int) $run->cells_exhausted);
        $this->assertSame('exhausted', $run->stopReason());
    }

    public function test_one_refused_cell_among_healthy_ones_is_left_unconcluded(): void
    {
        config()->set('discovery.rate_limit.max_retries', 0);
        config()->set('discovery.nationwide.max_tasks', 2000);
        $run = $this->startNationwide();
        $this->fakeEngine(fn (int $n) => $n === 1 ? $this->outcome('rate_limited') : $this->outcome('ok', 2));

        $run = $this->execute($run);

        $this->assertSame((int) $run->cells_searched - 1, (int) $run->cells_exhausted);
        $this->assertSame(1, (int) $run->targets_failed);
        $this->assertSame('completed', $run->stopReason(), 'a failed search licenses no claim about the ground');
    }

    public function test_a_full_page_beside_a_refusal_still_subdivides(): void
    {
        config()->set('discovery.rate_limit.max_retries', 0);
        config()->set('discovery.nationwide.max_tasks', 40);
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/crm/discovery/runs', ['scope' => 'nationwide', 'niches' => ['bakery', 'cafe']])
            ->assertStatus(202);
        $run = DiscoveryRun::query()->latest('id')->firstOrFail();

        // First cell: bakery fills the page, cafe is refused. There is demonstrably more here.
        $this->fakeEngine(fn (int $n) => match ($n) {
            1 => $this->outcome('ok', 20),
            2 => $this->outcome('rate_limited'),
            default => $this->outcome('ok', 1),
        });

        $run = $this->execute($run);

        $this->assertGreaterThan(0, (int) $run->cells_subdivided, 'the refused niche gets another chance in the children');
    }

    // ── the callback throttle ────────────────────────────────────────────────────────────────

    public function test_the_service_identity_is_throttled_per_run_token_not_shared(): void
    {
        $this->actingAsNerbyService();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/crm/discovery/config')->assertOk();
        }
        $refused = $this->getJson('/api/v1/crm/discovery/config')->assertStatus(429);
        $this->assertNotNull($refused->headers->get('Retry-After'), 'the scraper paces its retry on this');

        // Another run's token has its own counter: one sweep can no longer starve every other run.
        $this->actingAsNerbyService();
        $this->getJson('/api/v1/crm/discovery/config')->assertOk();
    }

    public function test_human_callers_keep_their_exact_per_user_throttle(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/crm/discovery/config')->assertOk();
        }
        $this->getJson('/api/v1/crm/discovery/config')->assertStatus(429);

        // Still keyed on the user, as `throttle:60,1` always was.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/crm/discovery/config')->assertOk();
    }
}
