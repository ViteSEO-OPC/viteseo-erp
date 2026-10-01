<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\AiStrategyCapability;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\EnablesDiscoveryRuntime;
use Tests\TestCase;

/**
 * PER-RUN AI DISCOVERY — `ai_strategy` on `POST /discovery/runs`, the capability status on
 * `GET /discovery/config`, and the recorded `ai.outcome` on every run.
 *
 * NO PROVIDER IS EVER CONTACTED. The engine is replaced by a recording fake (or `Process::fake()`),
 * and `Http::preventStrayRequests()` turns any outbound call into a failure rather than a charge.
 *
 * WHAT THIS PINS
 *
 *  - **Omitted and false are the old behaviour.** No 422 for AI being unavailable, no flag passed to
 *    the engine, `ai.requested` false — for scoped and nationwide, on Google and on the baseline.
 *  - **True is checked BEFORE anything is spent** and each unavailable reason is a distinct 422.
 *  - **`applied` is earned, not requested.** Only a search the engine reports as shaped by a returned
 *    plan makes a run `applied`; a model that answered with nothing does not.
 *  - **A credit refusal never becomes an AI-labelled Google run.** The outcome is
 *    `insufficient_credit`, the run fails, and no further search is made.
 *  - **The flag never reaches the baseline provider**, including a run that degrades to it.
 *  - **The config payload is status words only.**
 */
class DiscoveryRunAiStrategyTest extends TestCase
{
    use EnablesDiscoveryRuntime;
    use RefreshDatabase;

    private const RUNS = '/api/v1/crm/discovery/runs';

    private const CONFIG = '/api/v1/crm/discovery/config';

    private const AI_KEY = 'sk-or-test-not-a-real-credential-0000abcd';

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        // The deployment-wide flag stays OFF, so every AI effect below comes from the per-run switch.
        config()->set('discovery.ai_strategy', false);
    }

    private function actingAsRole(string $role = 'crm_admin'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function googleActive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz',
            'key_last4' => 'wxyz',
        ]);
    }

    private function aiConfigured(bool $enabled = true, ?string $lastTest = 'ok'): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => $enabled,
            'api_key' => self::AI_KEY,
            'key_last4' => 'abcd',
            'options' => ['model' => 'gpt-4o-mini'],
            'last_test_status' => $lastTest,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function start(array $overrides = []): TestResponse
    {
        return $this->postJson(self::RUNS, [
            'locations' => ['Cebu City', 'Davao City'],
            'niches' => ['bakery'],
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function engineResult(?bool $applied = null, ?string $status = null, string $execution = 'ok'): array
    {
        return [
            'exitCode' => $execution === 'ok' ? 0 : 3,
            'executionStatus' => $execution,
            'errorReason' => null,
            'discovered' => 3,
            'inserted' => 2,
            'duplicates' => 1,
            'rejected' => 0,
            'stderrTail' => '',
            'strategyApplied' => $applied,
            'strategyStatus' => $status,
        ];
    }

    /** @param callable(int, array<string, mixed>): array<string, mixed> $respond */
    private function fakeEngine(callable $respond): void
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
                $call = compact('area', 'category', 'provider', 'aiStrategy');
                $this->test->calls[] = $call;

                return ($this->respond)(count($this->test->calls), $call);
            }
        });
    }

    private function execute(): DiscoveryRun
    {
        $run = DiscoveryRun::query()->latest('id')->firstOrFail();
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    // ── backward compatibility ───────────────────────────────────────────────────────────────

    public function test_an_omitted_ai_strategy_starts_exactly_the_run_it_always_did(): void
    {
        Queue::fake();
        $this->googleActive();
        // AI deliberately NOT configured: an old client must not start getting AI refusals.
        $this->actingAsRole();

        $this->start()
            ->assertStatus(202)
            ->assertJsonPath('data.ai.requested', false)
            ->assertJsonPath('data.ai.outcome', 'not_applied');

        $this->assertFalse((bool) DiscoveryRun::query()->firstOrFail()->ai_strategy_requested);
        Queue::assertPushedOn('discovery', RunDiscoveryJob::class);
    }

    public function test_ai_strategy_false_is_the_default_and_is_never_refused_for_ai_state(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured(lastTest: 'insufficient_credit');
        $this->actingAsRole();

        $this->start(['ai_strategy' => false])
            ->assertStatus(202)
            ->assertJsonPath('data.ai.requested', false);
    }

    public function test_a_run_without_ai_never_passes_the_flag_to_the_engine(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start()->assertStatus(202);

        // Even if an engine reported planning (the deployment-wide flag), the per-run switch is off.
        $this->fakeEngine(fn () => $this->engineResult());
        $run = $this->execute();

        $this->assertCount(2, $this->calls);
        foreach ($this->calls as $call) {
            $this->assertFalse($call['aiStrategy']);
            $this->assertSame('google_places', $call['provider']);
        }
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('not_applied', $run->aiOutcome());
    }

    public function test_the_openstreetmap_default_run_is_untouched(): void
    {
        Queue::fake();
        // No provider selected at all: the OSM baseline.
        $this->actingAsRole();
        $this->start()->assertStatus(202);

        $this->fakeEngine(fn () => $this->engineResult());
        $run = $this->execute();

        foreach ($this->calls as $call) {
            $this->assertSame('openstreetmap', $call['provider']);
            $this->assertFalse($call['aiStrategy']);
        }
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
    }

    // ── the explicit request ─────────────────────────────────────────────────────────────────

    public function test_ai_strategy_true_is_accepted_when_ai_is_available_and_reaches_only_that_run(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();

        $this->start(['ai_strategy' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.ai.requested', true)
            ->assertJsonPath('data.ai.outcome', 'not_applied');

        // A second, ordinary run started beside it stays ordinary.
        $this->start(['locations' => ['Iloilo City']])->assertStatus(202)->assertJsonPath('data.ai.requested', false);

        $this->fakeEngine(fn () => $this->engineResult(applied: true, status: 'ok'));
        $aiRun = DiscoveryRun::query()->where('ai_strategy_requested', true)->firstOrFail();
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($aiRun->uuid);

        $this->assertCount(2, $this->calls);
        $this->assertTrue($this->calls[0]['aiStrategy']);
        $this->assertTrue($this->calls[1]['aiStrategy']);

        $plain = DiscoveryRun::query()->where('ai_strategy_requested', false)->firstOrFail();
        $this->calls = [];
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($plain->uuid);
        $this->assertFalse($this->calls[0]['aiStrategy']);
    }

    /** @return array<string, array{0: callable(self): void, 1: string, 2: string}> */
    public static function unavailable(): array
    {
        return [
            'not configured' => [fn (self $t) => $t->googleActive(), AiStrategyCapability::NOT_CONFIGURED, 'not configured'],
            'disabled' => [function (self $t) {
                $t->googleActive();
                $t->aiConfigured(enabled: false);
            }, AiStrategyCapability::DISABLED, 'switched off'],
            'no credit' => [function (self $t) {
                $t->googleActive();
                $t->aiConfigured(lastTest: 'insufficient_credit');
            }, AiStrategyCapability::INSUFFICIENT_CREDIT, 'no credit remaining'],
            'baseline provider' => [fn (self $t) => $t->aiConfigured(), AiStrategyCapability::UNSUPPORTED_PROVIDER, 'needs Google Places'],
        ];
    }

    #[DataProvider('unavailable')]
    public function test_an_unavailable_ai_request_is_a_distinct_422_before_anything_is_spent(
        callable $arrange,
        string $status,
        string $phrase,
    ): void {
        Queue::fake();
        $arrange($this);
        $this->actingAsRole();

        $this->getJson(self::CONFIG)->assertOk()->assertJsonPath('data.aiStrategy.status', $status);

        $response = $this->start(['ai_strategy' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ai_strategy');
        $this->assertStringContainsString($phrase, $response->json('errors.ai_strategy.0'));

        $this->assertSame(0, DiscoveryRun::query()->count());
        Queue::assertNotPushed(RunDiscoveryJob::class);
    }

    public function test_a_business_developer_is_still_refused_whatever_they_ask_for(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole('crm_bd');

        $this->start(['ai_strategy' => true])->assertForbidden();
        $this->assertSame(0, DiscoveryRun::query()->count());
    }

    // ── the recorded outcome ─────────────────────────────────────────────────────────────────

    public function test_applied_is_recorded_only_when_the_engine_reports_a_shaped_search(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start(['ai_strategy' => true])->assertStatus(202);

        // The model answered but contributed nothing: requested, consulted, NOT applied.
        $this->fakeEngine(fn () => $this->engineResult(applied: false, status: 'ok'));
        $run = $this->execute();
        $this->assertSame('not_applied', $run->aiOutcome());

        $this->getJson(self::RUNS.'/'.$run->uuid)
            ->assertOk()
            ->assertJsonPath('data.ai.requested', true)
            ->assertJsonPath('data.ai.outcome', 'not_applied');
    }

    public function test_one_shaped_search_makes_the_run_applied(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start(['ai_strategy' => true])->assertStatus(202);

        $this->fakeEngine(fn (int $n) => $this->engineResult(applied: $n === 2, status: 'ok'));
        $run = $this->execute();

        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->getJson(self::RUNS.'/'.$run->uuid)->assertJsonPath('data.ai.outcome', 'applied');
    }

    public function test_an_engine_that_reports_nothing_is_never_read_as_applied(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start(['ai_strategy' => true])->assertStatus(202);

        // An engine older than the field: no strategy keys at all.
        $this->fakeEngine(fn () => $this->engineResult(applied: null, status: null));

        $this->assertSame('not_applied', $this->execute()->aiOutcome());
    }

    public function test_a_credit_refusal_stops_the_run_instead_of_continuing_as_plain_google(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start(['ai_strategy' => true, 'locations' => ['Cebu City', 'Davao City', 'Iloilo City']])
            ->assertStatus(202);

        $this->fakeEngine(fn () => $this->engineResult(applied: false, status: 'insufficient_credit'));
        $run = $this->execute();

        // ONE search was made — the one that learned the account cannot pay — and no more.
        $this->assertCount(1, $this->calls);
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertSame('insufficient_credit', $run->aiOutcome());
        $this->assertStringContainsString('no credit', (string) $run->error_message);
        $this->assertSame(2, DiscoveryRunTarget::query()->where('status', DiscoveryRunTarget::STATUS_SKIPPED)->count());

        $this->getJson(self::RUNS.'/'.$run->uuid)
            ->assertJsonPath('data.ai.outcome', 'insufficient_credit')
            ->assertJsonPath('data.status', 'failed');
    }

    public function test_insufficient_credit_outranks_an_earlier_applied_search(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start(['ai_strategy' => true, 'locations' => ['Cebu City', 'Davao City', 'Iloilo City']])
            ->assertStatus(202);

        $this->fakeEngine(fn (int $n) => $n === 1
            ? $this->engineResult(applied: true, status: 'ok')
            : $this->engineResult(applied: false, status: 'insufficient_credit'));
        $run = $this->execute();

        $this->assertCount(2, $this->calls);
        $this->assertSame('insufficient_credit', $run->aiOutcome());
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
    }

    public function test_a_run_degraded_to_the_baseline_never_sends_the_flag_to_openstreetmap(): void
    {
        Queue::fake();
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        $this->start(['ai_strategy' => true, 'locations' => ['Cebu City']])->assertStatus(202);

        // Google rejects its credential; the task is retried on the baseline.
        $this->fakeEngine(fn (int $n) => $n === 1
            ? $this->engineResult(execution: 'auth_failed')
            : $this->engineResult());
        $this->execute();

        $this->assertSame('google_places', $this->calls[0]['provider']);
        $this->assertTrue($this->calls[0]['aiStrategy']);
        $this->assertSame('openstreetmap', $this->calls[1]['provider']);
        $this->assertFalse($this->calls[1]['aiStrategy']);
    }

    public function test_a_nationwide_ai_run_passes_the_flag_and_stops_on_a_credit_refusal(): void
    {
        Queue::fake();
        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 12);
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();

        $this->postJson(self::RUNS, ['scope' => 'nationwide', 'niches' => ['bakery'], 'ai_strategy' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.ai.requested', true);

        $this->fakeEngine(fn (int $n) => $n === 1
            ? $this->engineResult(applied: true, status: 'ok')
            : $this->engineResult(applied: false, status: 'insufficient_credit'));
        $run = $this->execute();

        $this->assertCount(2, $this->calls);
        $this->assertTrue($this->calls[0]['aiStrategy']);
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertSame('insufficient_credit', $run->aiOutcome());
    }

    // ── the engine channel ───────────────────────────────────────────────────────────────────

    public function test_the_runner_opens_the_ai_channel_only_when_asked(): void
    {
        Process::fake();

        $runner = $this->app->make(ScraperProcessRunner::class);
        $runner->run('Cebu', 'bakery', 20, 'google_places', storage_path('app/discovery/ai-a.jsonl'));
        $runner->run('Cebu', 'bakery', 20, 'google_places', storage_path('app/discovery/ai-b.jsonl'), null, 'PH', true);

        $envs = [];
        Process::assertRan(function (PendingProcess $process) use (&$envs): bool {
            $envs[] = $process->environment;

            return true;
        });

        $this->assertCount(2, $envs);
        $this->assertArrayNotHasKey('DISCOVERY_AI_STRATEGY', $envs[0]);
        $this->assertSame('1', $envs[1]['DISCOVERY_AI_STRATEGY']);
    }

    public function test_the_runner_reads_the_engines_planning_report(): void
    {
        $path = storage_path('app/discovery/ai-report-'.uniqid().'.jsonl');
        @mkdir(dirname($path), 0775, true);

        Process::fake(function () use ($path) {
            file_put_contents($path, json_encode([
                'provider_execution_status' => 'ok',
                'discovered' => 1,
                'strategy_applied' => false,
                'strategy_status' => 'insufficient_credit',
            ])."\n", FILE_APPEND);

            return Process::result();
        });

        $result = $this->app->make(ScraperProcessRunner::class)
            ->run('Cebu', 'bakery', 20, 'google_places', $path, null, 'PH', true);
        @unlink($path);

        $this->assertFalse($result['strategyApplied']);
        $this->assertSame('insufficient_credit', $result['strategyStatus']);
    }

    // ── the config surface ───────────────────────────────────────────────────────────────────

    public function test_config_reports_available_as_a_status_word_and_nothing_else(): void
    {
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();

        $response = $this->getJson(self::CONFIG)->assertOk()
            ->assertJsonPath('data.aiStrategy', ['status' => 'available'])
            ->assertJsonPath('data.effectiveProvider', 'google_places');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString(self::AI_KEY, $body);
        $this->assertStringNotContainsString('abcd', $body);
        $this->assertStringNotContainsString('gpt-4o-mini', $body);
        $this->assertStringNotContainsString('AIzaSy', $body);
    }

    public function test_a_real_credit_refusal_turns_the_capability_off_in_config(): void
    {
        $this->googleActive();
        $this->aiConfigured();
        $this->actingAsRole();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient credits']], 402)]);

        $this->postJson('/api/v1/crm/discovery/strategy', ['category' => 'bakery', 'scope' => 'Cebu'])
            ->assertStatus(502)
            ->assertJsonPath('data.executionStatus', 'insufficient_credit');

        $this->getJson(self::CONFIG)->assertJsonPath('data.aiStrategy.status', 'insufficient_credit');
        $this->start(['ai_strategy' => true])->assertStatus(422)->assertJsonValidationErrors('ai_strategy');
    }
}
