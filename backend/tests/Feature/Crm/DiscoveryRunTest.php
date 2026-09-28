<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * On-demand discovery runs — the Stage 2 execution surface.
 *
 * NO PROVIDER IS EVER CONTACTED BY THIS TEST. `Queue::fake()` stops the job before it reaches the
 * orchestrator, so nothing spawns the Python engine, nothing calls Google Places or Overpass, and
 * nothing spends money. Everything asserted here is about what the HTTP surface promises.
 *
 * The properties that matter, and why each is load-bearing:
 *
 *  - **The queue name is not decoration.** Every other job in this codebase runs on the default
 *    queue; this one must land on `discovery`, because the dedicated worker consumes that queue and
 *    no other. A dispatch that forgets it is enqueued forever with no consumer.
 *  - **RBAC is unchanged.** A BD holds `crm.leads.import` and must still be refused, exactly as they
 *    are on every existing paid `/discovery/*` endpoint. If this test ever starts passing for a BD,
 *    someone has widened a gate that was closed deliberately.
 *  - **There is no niche whitelist.** A word nobody wrote into this codebase must be accepted
 *    verbatim, or the product has quietly acquired the hardcoded vocabulary it exists to avoid.
 *  - **Starting a run reads nothing from the prospect pool and writes no lead.** The pool is the
 *    destination, never the source.
 */
class DiscoveryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // The job is never executed: no subprocess, no provider call, no spend.
        Queue::fake();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function start(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/crm/discovery/runs', [
            'locations' => ['Cebu City, Philippines'],
            'niches' => ['dentist'],
            ...$overrides,
        ]);
    }

    public function test_a_run_is_queued_on_the_discovery_queue(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->start([
            'locations' => ['Cebu City, Philippines', 'Davao City, Philippines'],
            'niches' => ['dentist', 'cafe'],
        ])->assertStatus(202);

        $runId = $response->json('data.runId');
        $this->assertNotNull($runId);
        $response->assertJsonPath('data.status', DiscoveryRun::STATUS_PENDING);

        // Two locations × two niches is four searches — the cross product, deliberately, so "all
        // niches" means all of them rather than whichever subset a curated file pairs with a city.
        $response->assertJsonPath('data.progress.targetsTotal', 4);
        $this->assertSame(4, DiscoveryRunTarget::query()->count());

        // The whole point: the dedicated worker consumes ONLY this queue.
        Queue::assertPushedOn('discovery', RunDiscoveryJob::class);
    }

    public function test_a_business_developer_cannot_start_a_paid_discovery_run(): void
    {
        Sanctum::actingAs($this->bd());

        // A BD holds `crm.leads.import`, so the permission alone would let them through. The role
        // gate is what stops them, exactly as on every existing paid /discovery/* endpoint.
        $this->start()->assertForbidden();

        $this->assertSame(0, DiscoveryRun::query()->count());
        // Specifically THIS job, not "nothing at all": creating a user and assigning a role emits
        // its own broadcast onto the queue, which has nothing to do with discovery.
        Queue::assertNotPushed(RunDiscoveryJob::class);
    }

    public function test_an_arbitrary_niche_is_accepted_verbatim(): void
    {
        Sanctum::actingAs($this->admin());

        // Neither word appears anywhere in this codebase. Both must survive untouched: no
        // whitelist, no canonicalization, no mapping onto a known vocabulary.
        $this->start([
            'locations' => ['Roxas City, Philippines'],
            'niches' => ['vape shop'],
        ])->assertStatus(202);

        $target = DiscoveryRunTarget::query()->firstOrFail();
        $this->assertSame('vape shop', $target->category);
        $this->assertSame('Roxas City, Philippines', $target->area);
    }

    public function test_the_same_idempotency_key_returns_the_same_run(): void
    {
        Sanctum::actingAs($this->admin());

        $first = $this->start(['idempotency_key' => 'click-abc'])->assertStatus(202);
        // A double-click, a refresh and a network retry are one intent, not two paid sweeps.
        $second = $this->start(['idempotency_key' => 'click-abc'])->assertStatus(200);

        $this->assertSame($first->json('data.runId'), $second->json('data.runId'));
        $this->assertSame(1, DiscoveryRun::query()->count());
        Queue::assertPushed(RunDiscoveryJob::class, 1);
    }

    public function test_an_overlapping_active_scope_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->start()->assertStatus(202);

        // Same area, same niche, still in flight: a second run would pay to rediscover what the
        // first is already finding.
        $this->start()->assertStatus(409);

        $this->assertSame(1, DiscoveryRun::query()->count());
    }

    public function test_a_disjoint_scope_may_run_alongside_another(): void
    {
        Sanctum::actingAs($this->admin());

        $this->start()->assertStatus(202);

        // Deliberately NOT a global lock: unrelated scopes are independent work.
        $this->start([
            'locations' => ['Iloilo City, Philippines'],
            'niches' => ['hardware'],
        ])->assertStatus(202);

        $this->assertSame(2, DiscoveryRun::query()->count());
    }

    public function test_starting_a_run_creates_no_leads_and_reads_nothing_from_the_pool(): void
    {
        Sanctum::actingAs($this->admin());

        $this->start()->assertStatus(202);

        // The pool is the destination, never the source. Nothing is written until the engine
        // discovers something and the EXISTING ingest accepts it.
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_the_volume_fence_rejects_an_unaffordable_run(): void
    {
        Sanctum::actingAs($this->admin());

        // A billing fence, not a discovery boundary: it caps what one click may spend, never which
        // businesses are reachable.
        $this->start([
            'locations' => array_map(fn (int $i) => "Area {$i}", range(1, 11)),
            'niches' => array_map(fn (int $i) => "niche {$i}", range(1, 10)),
        ])->assertStatus(422);

        $this->assertSame(0, DiscoveryRun::query()->count());
        // Specifically THIS job, not "nothing at all": creating a user and assigning a role emits
        // its own broadcast onto the queue, which has nothing to do with discovery.
        Queue::assertNotPushed(RunDiscoveryJob::class);
    }

    public function test_a_limit_above_the_provider_ceiling_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        // 60 is the structured provider's own ceiling (PAGE_SIZE 20 × MAX_PAGES 3); asking for more
        // spends requests that cannot return anything new.
        $this->start(['limit_per_task' => 999])->assertStatus(422);
    }

    public function test_a_run_can_be_polled_and_cancelled(): void
    {
        Sanctum::actingAs($this->admin());

        $runId = $this->start()->assertStatus(202)->json('data.runId');

        $this->getJson("/api/v1/crm/discovery/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.runId', $runId)
            // Provider honesty is part of the contract, not an afterthought: requested and
            // effective are always reported separately so OSM results can never read as Google's.
            ->assertJsonStructure(['data' => ['provider' => ['requested', 'effective', 'reason', 'isFallback']]]);

        $this->postJson("/api/v1/crm/discovery/runs/{$runId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', DiscoveryRun::STATUS_CANCELLED);
    }
}
