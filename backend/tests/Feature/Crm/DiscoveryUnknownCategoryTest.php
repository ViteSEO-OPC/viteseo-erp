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
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\EnablesDiscoveryRuntime;
use Tests\TestCase;

/**
 * AN UNKNOWN NICHE IS REFUSED ONCE, NOT ONCE PER CELL.
 *
 * Run 119 (nationwide, AI-assisted, "sport") spent its whole budget of 60 on searches the engine
 * refused with exit 2 — "Unknown category 'sport'" — before any AI step, Overpass query or ingest,
 * and showed "Budget 60/60 · 60 searched" with no new leads. The refusal is a fact about the niche,
 * so a nationwide run stops after it; a scoped run, which names its own searches, keeps its
 * partial-failure behaviour. Either way the engine's own message is what the user reads.
 *
 * The engine is a recording fake: nothing spawns, and any outbound request fails the test.
 */
class DiscoveryUnknownCategoryTest extends TestCase
{
    use EnablesDiscoveryRuntime;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/crm/discovery/runs';

    private const ENGINE_REFUSAL = "Traceback lines are not expected here\n"
        ."ERROR: Unknown category 'sport'. Use a known word (bakery, bar, cafe, dentist, pharmacy) "
        ."or a raw OSM selector like 'amenity=cafe'.";

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Queue::fake();

        config()->set('discovery.nationwide.cell_degrees', 1.0);
        config()->set('discovery.nationwide.max_tasks', 12);
        config()->set('discovery.nationwide.max_depth', 1);
        config()->set('discovery.nationwide.limit_per_task', 20);
        // The plain engine command only: neither opt-in nationwide mode is under test here.
        config()->set('discovery.nationwide.volume.enabled', false);
        config()->set('discovery.nationwide.ai_total_limit', null);

        Sanctum::actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        foreach (DiscoveryRun::query()->pluck('uuid') as $uuid) {
            @unlink(storage_path('app/discovery/'.$uuid.'.jsonl'));
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    /** The AI-assisted OpenStreetMap lane — the provider run 119 used. */
    private function aiAssisted(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => true, 'enabled' => true,
            'api_key' => 'sk-test-not-a-real-credential-0000abcd', 'key_last4' => 'abcd',
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    /** "sport" is refused exactly as the engine refuses it; every other niche finds 5 new leads. */
    private function fakeEngine(): void
    {
        $test = $this;
        $this->app->instance(ScraperProcessRunner::class, new class($test) extends ScraperProcessRunner
        {
            public function __construct(private $test) {}

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
                return $this->test->recordCall(compact('area', 'category', 'provider', 'bbox'));
            }
        });
    }

    /** @internal Used by the anonymous engine fake. */
    public function recordCall(array $call): array
    {
        $this->calls[] = $call;
        $refused = $call['category'] === 'sport';

        return [
            'exitCode' => $refused ? 2 : 0,
            'executionStatus' => $refused ? null : 'ok',
            'errorReason' => null,
            'discovered' => $refused ? 0 : 5,
            'inserted' => $refused ? 0 : 5,
            'duplicates' => 0,
            'rejected' => 0,
            'stderrTail' => $refused ? self::ENGINE_REFUSAL : '',
        ];
    }

    private function execute(): DiscoveryRun
    {
        $run = DiscoveryRun::query()->latest('id')->firstOrFail();
        $this->app->make(DiscoveryRunOrchestrator::class)->execute($run->uuid);

        return $run->refresh();
    }

    /** @return list<string> */
    private function categoriesCalled(): array
    {
        return array_column($this->calls, 'category');
    }

    // ── nationwide ───────────────────────────────────────────────────────────────────────────

    public function test_a_nationwide_run_stops_after_its_first_unknown_category_refusal(): void
    {
        $this->aiAssisted();
        $this->fakeEngine();
        $this->postJson(self::ENDPOINT, ['scope' => 'nationwide', 'niches' => ['sport']])->assertStatus(202);

        $run = $this->execute();

        $this->assertCount(1, $this->calls, 'only the first search may be attempted');
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, (int) $run->budget_used, 'the budget counts only the search actually attempted');
        $this->assertSame(1, (int) $run->targets_total);
        $this->assertSame(1, (int) $run->targets_failed);
        $this->assertSame(0, (int) $run->targets_completed);
        $this->assertGreaterThan(1, (int) $run->cells_planned, 'the rest of the plan was left unsearched');
        $this->assertSame(0, DiscoveryRunTarget::query()->whereIn('status', [
            DiscoveryRunTarget::STATUS_PENDING, DiscoveryRunTarget::STATUS_RUNNING,
        ])->count(), 'nothing may be left pending or running');
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString("Unknown category 'sport'", (string) $run->error_message);
        $this->assertStringContainsString('Use a known word', (string) $run->error_message);

        $target = DiscoveryRunTarget::query()->sole();
        $this->assertSame(2, (int) $target->exit_code);
        $this->assertStringStartsWith("Unknown category 'sport'", (string) $target->error_reason);
    }

    public function test_the_run_resource_states_the_failed_attempt_plainly(): void
    {
        $this->aiAssisted();
        $this->fakeEngine();
        $runId = $this->postJson(self::ENDPOINT, ['scope' => 'nationwide', 'niches' => ['sport']])->json('data.runId');
        $this->execute();

        $body = $this->getJson(self::ENDPOINT."/{$runId}")->assertOk()->json('data');

        $this->assertSame('failed', $body['status']);
        $this->assertSame('failed', $body['stopReason']);
        $this->assertSame(1, $body['progress']['targetsTotal']);
        $this->assertSame(1, $body['progress']['targetsFailed']);
        $this->assertSame(0, $body['progress']['targetsCompleted']);
        $this->assertSame(1, $body['progress']['budgetUsed']);
        $this->assertSame(0, $body['progress']['cellsSearched'], 'a refused niche searched no map area');
        $this->assertSame(0, $body['counters']['inserted']);
        $this->assertStringContainsString("Unknown category 'sport'", $body['errorMessage']);
        $this->assertStringContainsString("Unknown category 'sport'", $body['targets'][0]['errorReason']);
    }

    public function test_a_nationwide_run_with_a_supported_niche_left_keeps_searching_it(): void
    {
        $this->aiAssisted();
        $this->fakeEngine();
        $this->postJson(self::ENDPOINT, ['scope' => 'nationwide', 'niches' => ['bakery', 'sport']])->assertStatus(202);

        $run = $this->execute();

        $categories = $this->categoriesCalled();
        $this->assertSame(1, count(array_keys($categories, 'sport', true)), 'the refused niche is attempted once');
        $this->assertGreaterThan(1, count(array_keys($categories, 'bakery', true)));
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, (int) $run->targets_failed);
        $this->assertSame(count($this->calls), (int) $run->budget_used);
    }

    public function test_a_successful_ai_assisted_nationwide_run_is_unchanged(): void
    {
        $this->aiAssisted();
        $this->fakeEngine();
        $this->postJson(self::ENDPOINT, ['scope' => 'nationwide', 'niches' => ['bakery']])->assertStatus(202);

        $run = $this->execute();

        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->error_message);
        $this->assertSame(0, (int) $run->targets_failed);
        $this->assertSame(12, (int) $run->budget_used, 'a healthy run still spends its whole budget');
        $this->assertSame(12, (int) $run->cells_searched);
        $this->assertSame(60, (int) $run->inserted);
        foreach ($this->calls as $call) {
            $this->assertSame('openai', $call['provider']);
        }
    }

    public function test_the_default_openstreetmap_path_is_unchanged(): void
    {
        // No provider setting at all: the run resolves to the default OpenStreetMap baseline.
        $this->fakeEngine();
        $this->postJson(self::ENDPOINT, ['scope' => 'nationwide', 'niches' => ['bakery']])->assertStatus(202);

        $run = $this->execute();

        $this->assertSame('openstreetmap', $run->provider_effective);
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(12, (int) $run->budget_used);
        $this->assertSame(0, (int) $run->targets_failed);
        foreach ($this->calls as $call) {
            $this->assertSame('openstreetmap', $call['provider']);
            $this->assertNotNull($call['bbox']);
        }
    }

    // ── scoped ───────────────────────────────────────────────────────────────────────────────

    public function test_a_scoped_run_keeps_its_partial_failure_behaviour(): void
    {
        $this->aiAssisted();
        $this->fakeEngine();
        $this->postJson(self::ENDPOINT, [
            'locations' => ['Cebu City, Philippines', 'Davao City, Philippines'],
            'niches' => ['dentist', 'sport'],
        ])->assertStatus(202);

        $run = $this->execute();

        // Every named search still runs: a scoped run is never cut short by one niche's refusal.
        $this->assertCount(4, $this->calls);
        $this->assertSame(DiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, (int) $run->targets_completed);
        $this->assertSame(2, (int) $run->targets_failed);
        $this->assertSame(10, (int) $run->inserted);
        $this->assertNull($run->error_message);

        $sport = DiscoveryRunTarget::query()->where('category', 'sport')->get();
        $this->assertCount(2, $sport);
        foreach ($sport as $target) {
            $this->assertSame(DiscoveryRunTarget::STATUS_FAILED, $target->status);
            $this->assertStringStartsWith("Unknown category 'sport'", (string) $target->error_reason);
        }
        $this->assertSame(2, DiscoveryRunTarget::query()
            ->where('category', 'dentist')->where('status', DiscoveryRunTarget::STATUS_COMPLETED)->count());
    }

    public function test_a_scoped_run_where_every_niche_is_refused_still_fails_as_before(): void
    {
        $this->aiAssisted();
        $this->fakeEngine();
        $this->postJson(self::ENDPOINT, [
            'locations' => ['Cebu City, Philippines', 'Davao City, Philippines'],
            'niches' => ['sport'],
        ])->assertStatus(202);

        $run = $this->execute();

        $this->assertCount(2, $this->calls);
        $this->assertSame(DiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertSame('Every search in this run failed. See the individual searches for the reason.', $run->error_message);
    }
}
