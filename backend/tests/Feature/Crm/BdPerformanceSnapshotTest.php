<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\BdPerformanceSnapshot;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Services\Performance\BdPerformanceSnapshotService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Slice 2B — historical performance attribution.
 *
 * Two independent guarantees:
 *
 *  1. **M-06 follows provenance, not custody.** Activity volume is credited to whoever performed the
 *     work (`causer_id`), so reassigning a lead never hands the previous owner's effort to the new
 *     one, and never counts it twice.
 *  2. **A performance snapshot is frozen before ownership can move.** Every other metric is derived
 *     live from `crm_leads.assigned_to`, so a resigned BD's record is unrecoverable the moment their
 *     portfolio is handed on — unless it was written down first.
 */
class BdPerformanceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(string $name = 'Jane Aranda'): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole('crm_bd');

        return $user->fresh();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    private function logActivity(Lead $lead, User $causer): void
    {
        Activity::query()->create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'causer_id' => $causer->id,
            'event' => 'lead.updated',
            'description' => 'worked the lead',
            'properties' => [],
        ]);
    }

    // --- M-06 attribution ----------------------------------------------------

    public function test_activity_volume_follows_the_performer_not_the_owner(): void
    {
        $jane = $this->bd();
        $newOwner = $this->bd('BD One');
        $lead = Lead::factory()->create(['assigned_to' => $jane->id]);
        $this->logActivity($lead, $jane);

        $kpi = app(BdKpiService::class);
        $this->assertSame(1, $kpi->forUser($jane)->metricValue('M-06'));
        $this->assertSame(0, $kpi->forUser($newOwner)->metricValue('M-06'));

        // The handover.
        $lead->forceFill(['assigned_to' => $newOwner->id])->save();

        // Jane keeps the credit; the new owner inherits none of it. Before `performedBy`, the
        // visibility scope credited this activity to BOTH of them.
        $this->assertSame(1, $kpi->forUser($jane->fresh())->metricValue('M-06'));
        $this->assertSame(0, $kpi->forUser($newOwner->fresh())->metricValue('M-06'));
    }

    public function test_the_new_owner_is_credited_for_their_own_work_only(): void
    {
        $jane = $this->bd();
        $newOwner = $this->bd('BD One');
        $lead = Lead::factory()->create(['assigned_to' => $jane->id]);
        $this->logActivity($lead, $jane);
        $lead->forceFill(['assigned_to' => $newOwner->id])->save();

        $this->logActivity($lead->fresh(), $newOwner);

        $kpi = app(BdKpiService::class);
        $this->assertSame(1, $kpi->forUser($newOwner->fresh())->metricValue('M-06'));
        $this->assertSame(1, $kpi->forUser($jane->fresh())->metricValue('M-06'));
    }

    // --- The ordering rule ---------------------------------------------------

    public function test_the_snapshot_is_captured_before_the_suspension_lands(): void
    {
        $jane = $this->bd();
        $this->bd('Peer One');
        $this->bd('Peer Two');
        Lead::factory()->count(3)->create(['assigned_to' => $jane->id]);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/users/{$jane->id}", ['status' => 'inactive'])
            ->assertOk();

        $snapshot = BdPerformanceSnapshot::query()->where('user_id', $jane->id)->firstOrFail();

        // If the suspension were written first, BdRankingService would already have excluded Jane
        // from her own ranking run and this would be null.
        $this->assertNotNull($snapshot->metrics['ranking']);
        $this->assertSame(3, $snapshot->metrics['kpi'][0]['value'], 'M-01 must record the portfolio she still held');
        $this->assertSame(BdPerformanceSnapshot::REASON_SUSPENSION, $snapshot->reason);
    }

    public function test_the_snapshot_records_the_peer_cohort(): void
    {
        $jane = $this->bd();
        $peer = $this->bd('Peer One');
        Lead::factory()->count(2)->create(['assigned_to' => $jane->id]);
        Lead::factory()->create(['assigned_to' => $peer->id]);

        app(BdPerformanceSnapshotService::class)->capture($jane, BdPerformanceSnapshot::REASON_SUSPENSION);

        $snapshot = BdPerformanceSnapshot::query()->where('user_id', $jane->id)->firstOrFail();
        $peerIds = array_column($snapshot->cohort['peers'], 'bd_id');

        // The BDR index is peer-relative min-max, so it is meaningless without the set below.
        $this->assertContains((string) $peer->id, $peerIds);
        $this->assertNotContains((string) $jane->id, $peerIds, 'the subject is not their own peer');
        $this->assertArrayHasKey('status', $snapshot->cohort);
    }

    public function test_the_snapshot_survives_the_portfolio_being_handed_on(): void
    {
        $jane = $this->bd();
        $this->bd('Peer One');
        $newOwner = $this->bd('BD One');
        $leads = Lead::factory()->count(4)->create(['assigned_to' => $jane->id]);

        app(BdPerformanceSnapshotService::class)->capture($jane, BdPerformanceSnapshot::REASON_SUSPENSION);
        foreach ($leads as $lead) {
            $lead->forceFill(['assigned_to' => $newOwner->id])->save();
        }

        // Live metrics correctly go empty — she holds nothing. The record does not.
        $this->assertSame(0, app(BdKpiService::class)->forUser($jane->fresh())->metricValue('M-01'));
        $snapshot = BdPerformanceSnapshot::query()->where('user_id', $jane->id)->firstOrFail();
        $this->assertSame(4, $snapshot->metrics['kpi'][0]['value']);
    }

    // --- Scope and safety ----------------------------------------------------

    public function test_no_snapshot_is_taken_for_a_non_bd(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($other)
            ->patchJson("/api/v1/crm/users/{$admin->id}", ['status' => 'inactive']);

        $this->assertSame(0, BdPerformanceSnapshot::query()->where('user_id', $admin->id)->count());
    }

    public function test_reactivating_takes_no_snapshot(): void
    {
        $jane = $this->bd();
        app(CrmAccessService::class)->suspend($jane);

        $this->actingAs($this->admin())
            ->patchJson("/api/v1/crm/users/{$jane->id}", ['status' => 'active'])
            ->assertOk();

        $this->assertSame(0, BdPerformanceSnapshot::query()->where('user_id', $jane->id)->count());
    }

    public function test_a_second_suspension_adds_a_second_snapshot(): void
    {
        $jane = $this->bd();
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/v1/crm/users/{$jane->id}", ['status' => 'inactive'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/crm/users/{$jane->id}", ['status' => 'active'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/crm/users/{$jane->id}", ['status' => 'inactive'])->assertOk();

        $this->assertSame(2, BdPerformanceSnapshot::query()->where('user_id', $jane->id)->count());
    }

    public function test_capturing_writes_nothing_to_the_shared_user_row(): void
    {
        $jane = $this->bd();
        $before = DB::table('users')->where('id', $jane->id)->first();

        app(BdPerformanceSnapshotService::class)->capture($jane, BdPerformanceSnapshot::REASON_SUSPENSION);

        $this->assertEquals($before, DB::table('users')->where('id', $jane->id)->first());
    }

    public function test_capturing_moves_no_leads(): void
    {
        $jane = $this->bd();
        Lead::factory()->count(3)->create(['assigned_to' => $jane->id]);
        $before = Lead::query()->where('assigned_to', $jane->id)->orderBy('id')->pluck('assigned_to', 'id')->all();

        app(BdPerformanceSnapshotService::class)->capture($jane, BdPerformanceSnapshot::REASON_SUSPENSION);

        $this->assertSame($before, Lead::query()->where('assigned_to', $jane->id)->orderBy('id')->pluck('assigned_to', 'id')->all());
    }

    // --- Read endpoint -------------------------------------------------------

    public function test_an_admin_can_read_the_snapshot_history_newest_first(): void
    {
        $jane = $this->bd();
        $admin = $this->admin();
        $this->actingAs($admin)->patchJson("/api/v1/crm/users/{$jane->id}", ['status' => 'inactive'])->assertOk();

        $this->actingAs($admin)
            ->getJson("/api/v1/crm/users/{$jane->id}/performance-snapshots")
            ->assertOk()
            ->assertJsonPath('data.0.userId', (string) $jane->id)
            ->assertJsonPath('data.0.reason', BdPerformanceSnapshot::REASON_SUSPENSION)
            ->assertJsonStructure(['data' => [['metrics', 'cohort', 'capturedAt']]]);
    }

    public function test_a_bd_cannot_read_snapshot_history(): void
    {
        $jane = $this->bd();

        $this->actingAs($this->bd('Nosy BD'))
            ->getJson("/api/v1/crm/users/{$jane->id}/performance-snapshots")
            ->assertForbidden();
    }
}
