<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Console\Commands\EnrichDiscoveredWebsitesCommand;
use App\Modules\Crm\Jobs\EnrichRunLeadsJob;
use App\Modules\Crm\Jobs\ProbeQueryYieldJob;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THE TWO RUNTIME SWITCHES THAT KEEP A DEPLOY FROM STARTING WORK BY ITSELF.
 *
 *  - DISCOVERY_ENRICHMENT_ENABLED: the half-hourly `crm:enrich-websites` tick (real outbound traffic,
 *    and on first run it provisions the NERBY service account and mints tokens).
 *  - DISCOVERY_WORKER_ENABLED: whether a dedicated `discovery` worker exists. Without one a run would
 *    be enqueued where nothing listens and sit "queued" forever.
 *
 * Both are OFF by default and strict. Every other discovery suite opts in explicitly
 * (Tests\Concerns\EnablesDiscoveryRuntime); this one pins the defaults and the off paths.
 */
class DiscoveryRuntimeSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private const SWITCHES = ['DISCOVERY_ENRICHMENT_ENABLED', 'DISCOVERY_WORKER_ENABLED'];

    /** Evaluate config/discovery.php under the given environment, then restore it. */
    private function discoveryConfigWith(array $env): array
    {
        $saved = [];
        foreach (self::SWITCHES as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            foreach ($env as $key => $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }

            return require config_path('discovery.php');
        } finally {
            foreach ($saved as $key => [$e, $s, $p]) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                if ($e !== null) {
                    $_ENV[$key] = $e;
                }
                if ($s !== null) {
                    $_SERVER[$key] = $s;
                }
                if ($p !== false) {
                    putenv($key.'='.$p);
                }
            }
        }
    }

    private function enrichmentEvent(): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains($event->command ?? '', 'crm:enrich-websites')) {
                return $event;
            }
        }

        $this->fail('crm:enrich-websites is not on the schedule');
    }

    // ── defaults and strictness ──────────────────────────────────────────────────────────────

    public function test_both_switches_are_off_when_unset(): void
    {
        $config = $this->discoveryConfigWith([]);

        $this->assertFalse($config['enrichment']['enabled']);
        $this->assertFalse($config['worker_enabled']);
        // And the running application agrees (nothing in phpunit.xml or .env turns them on).
        $this->assertFalse(config('discovery.enrichment.enabled'));
        $this->assertFalse(config('discovery.worker_enabled'));
    }

    public function test_only_explicit_true_values_turn_a_switch_on(): void
    {
        // A plain (bool) cast would read every one of these as true and fail open.
        foreach (['false', '0', 'off', 'no', '', 'disabled', 'nope', 'TRUE-ish'] as $value) {
            $config = $this->discoveryConfigWith(array_fill_keys(self::SWITCHES, $value));
            $this->assertFalse($config['enrichment']['enabled'], "enrichment enabled by '{$value}'");
            $this->assertFalse($config['worker_enabled'], "worker enabled by '{$value}'");
        }

        foreach (['true', '1', 'on', 'yes', 'TRUE'] as $value) {
            $config = $this->discoveryConfigWith(array_fill_keys(self::SWITCHES, $value));
            $this->assertTrue($config['enrichment']['enabled'], "enrichment not enabled by '{$value}'");
            $this->assertTrue($config['worker_enabled'], "worker not enabled by '{$value}'");
        }
    }

    // ── A: the enrichment tick ───────────────────────────────────────────────────────────────

    public function test_the_schedule_entry_is_skipped_while_enrichment_is_disabled(): void
    {
        $event = $this->enrichmentEvent();

        $this->assertFalse($event->filtersPass($this->app), 'a disabled tick must not be run by schedule:run');

        // Read when due, not when built: switching it on takes effect without rebuilding anything.
        config()->set('discovery.enrichment.enabled', true);
        $this->assertTrue($event->filtersPass($this->app));
        $this->assertSame('*/30 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_disabled_tick_run_by_name_does_nothing_at_all(): void
    {
        Process::fake();
        $email = (string) config('discovery.service_account.email');

        $this->artisan(EnrichDiscoveredWebsitesCommand::class)
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        // No subprocess, no service account, no role, no token, and no lock left behind.
        Process::assertNothingRan();
        $this->assertFalse(User::query()->where('email', $email)->exists());
        $this->assertFalse(Role::query()->where('name', NerbyServiceIdentity::ROLE)->exists());
        $this->assertSame(0, PersonalAccessToken::query()->count());
        // Checked before the lock: a held lock (another tick running) is irrelevant to a disabled switch.
        $held = Cache::lock('crm:enrich-websites', 60);
        $this->assertTrue($held->get());
        $this->artisan(EnrichDiscoveredWebsitesCommand::class)
            ->expectsOutputToContain('disabled')
            ->doesntExpectOutputToContain('already running')
            ->assertSuccessful();
        $held->release();
    }

    // ── B: the discovery queue ───────────────────────────────────────────────────────────────

    public function test_a_run_is_refused_before_anything_is_written_without_a_discovery_worker(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/crm/discovery/runs', [
            'locations' => ['Cebu City, Philippines'],
            'niches' => ['dentist'],
        ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Discovery runs are not available on this server yet: no discovery worker is configured.');

        $this->assertSame(0, DiscoveryRun::query()->count());
        Queue::assertNotPushed(RunDiscoveryJob::class);
        Queue::assertNotPushed(EnrichRunLeadsJob::class);
    }

    public function test_with_a_worker_one_click_queues_exactly_one_job_on_the_discovery_queue_only(): void
    {
        config()->set('discovery.worker_enabled', true);
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        Sanctum::actingAs($admin);

        $payload = ['locations' => ['Cebu City, Philippines'], 'niches' => ['dentist'], 'idempotency_key' => 'one-click'];
        $this->postJson('/api/v1/crm/discovery/runs', $payload)->assertStatus(202);
        $this->postJson('/api/v1/crm/discovery/runs', $payload)->assertStatus(200);

        Queue::assertPushedTimes(RunDiscoveryJob::class, 1);
        Queue::assertPushedOn('discovery', RunDiscoveryJob::class);
        // The general worker's queue never sees a paid sweep.
        Queue::assertNotPushed(RunDiscoveryJob::class, fn ($job, ?string $queue): bool => $queue !== 'discovery');
    }

    public function test_the_discovery_connection_outlives_its_longest_job_and_leaves_the_default_alone(): void
    {
        $discovery = config('queue.connections.discovery');
        $default = config('queue.connections.database');

        // Same table as the default database connection, read only for the `discovery` queue.
        $this->assertSame('database', $discovery['driver']);
        $this->assertSame($default['table'], $discovery['table']);
        $this->assertSame($default['connection'], $discovery['connection']);
        $this->assertSame('discovery', $discovery['queue']);

        // Every job on that queue runs once and is bounded, and the reservation outlives the longest,
        // so a still-running paid run is never offered to a second worker.
        $longest = 0;
        foreach ([RunDiscoveryJob::class, EnrichRunLeadsJob::class, ProbeQueryYieldJob::class] as $class) {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
            $this->assertSame(1, $defaults['tries'], "{$class} must not retry a paid call");
            $this->assertGreaterThan(0, $defaults['timeout'], "{$class} must be bounded");
            $longest = max($longest, $defaults['timeout']);
        }
        $this->assertGreaterThan($longest, $discovery['retry_after']);

        // The general worker keeps its short reservation for quick recovery of its own jobs.
        $this->assertSame(90, $default['retry_after']);
    }
}
