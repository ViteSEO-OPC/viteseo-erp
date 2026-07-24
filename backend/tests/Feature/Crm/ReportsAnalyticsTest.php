<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reports & Performance management analytics (`/dashboard/reports-analytics`). Rides the same scope seam
 * as the other Performance endpoints, so it inherits the same security contract; this locks the payload
 * SHAPE, the ownership scoping (a drill returns exactly that BD's own aggregate), the SURVIVAL-funnel
 * nesting invariant, and the honest "no fabricated targets" flag.
 */
class ReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, string $status = 'active'): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole($role);

        return $user;
    }

    public function test_returns_every_analytics_block_with_a_survival_funnel(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $data = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'funnel' => ['stages', 'biggestDropOff'],
                'statusMix',
                'outreachByChannel' => ['email' => ['completed', 'inProgress', 'notStarted', 'total'], 'call'],
                'niche',
                'followThrough' => ['leadsContacted', 'consultations', 'confirmedClients', 'missedFollowUps', 'outreachCompletionPct', 'targetsPending'],
                'pendingFollowUps' => ['counts' => ['overdue', 'today', 'upcoming'], 'rows'],
            ]])
            ->json('data');

        // The qualification funnel narrows monotonically (each stage is a subset of the one before it).
        $stages = $data['funnel']['stages'];
        $this->assertSame(['Added', 'Contacted', 'Interested', 'Consultation', 'Confirmed'], array_column($stages, 'label'));
        $this->assertGreaterThanOrEqual($stages[1]['value'], $stages[0]['value']); // Added ≥ Contacted
        $this->assertGreaterThanOrEqual($stages[2]['value'], $stages[1]['value']); // Contacted ≥ Interested
        $this->assertGreaterThanOrEqual($stages[3]['value'], $stages[2]['value']); // Interested ≥ Consultation

        // No goals are ever invented — the honest "targets pending" contract.
        $this->assertTrue($data['followThrough']['targetsPending']);
    }

    public function test_admin_drill_matches_the_bds_own_scope(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');

        Lead::factory()->count(3)->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
        Lead::factory()->count(5)->create(['assigned_to' => $other->id, 'created_by' => $other->id]);

        Sanctum::actingAs($bd);
        $self = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data.funnel.stages');

        Sanctum::actingAs($admin);
        $drill = $this->getJson("/api/v1/crm/dashboard/reports-analytics?user_id={$bd->id}")->assertOk()->json('data.funnel.stages');
        $this->assertSame($self, $drill);
        $this->assertSame(3, $drill[0]['value']); // Added = only the BD's own leads, not the tenant total

        $all = $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk()->json('data.funnel.stages');
        $this->assertSame(8, $all[0]['value']); // 3 + 5 tenant-wide
    }

    /**
     * The overdue/today/upcoming buckets are half-open [start, end) windows computed on the Asia/Manila
     * day boundary, so they PARTITION the pending set: an event landing exactly on midnight belongs to
     * `upcoming` only, and the three counts always sum to the total (never double-counting a boundary row).
     */
    public function test_pending_follow_up_buckets_partition_on_the_manila_day_boundary(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $midnight = now('Asia/Manila')->startOfDay()->addDay(); // exactly the today→upcoming boundary

        foreach ([$midnight->copy()->subDays(2), $midnight->copy()->subHours(3), $midnight, $midnight->copy()->addDay()] as $startsAt) {
            PlannerEvent::factory()->assignedTo($bd)->create([
                'event_type' => 'follow-up',
                'status' => 'scheduled',
                'starts_at' => $startsAt->copy()->utc(),
            ]);
        }

        Sanctum::actingAs($admin);
        $counts = $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->json('data.pendingFollowUps.counts');

        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(1, $counts['today']);
        $this->assertSame(2, $counts['upcoming']); // the midnight row counts here exactly once
        $this->assertSame(4, $counts['overdue'] + $counts['today'] + $counts['upcoming']);
    }

    public function test_bd_cannot_drill_into_another_bd(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        Sanctum::actingAs($bd);

        $this->getJson("/api/v1/crm/dashboard/reports-analytics?user_id={$other->id}")->assertForbidden();
        $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk();
    }
}
