<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Planner-backed Performance metrics: `completed_at` stamping and the follow-up discipline /
 * completed-follow-ups figures on performance-summary. Business rules (PO-frozen): "missed" = an event
 * `starts_at < now` and `status != completed`, grouped by follow-up/meeting/call; "Completed Follow-ups"
 * is the current-month count timestamped by the real `completed_at` (never `updated_at`).
 */
class FollowUpMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    private function event(User $bd, Lead $lead, string $type, string $status, ?Carbon $startsAt): PlannerEvent
    {
        return PlannerEvent::create([
            'event_type' => $type,
            'title' => "{$type} {$status}",
            'status' => $status,
            'source' => 'manual',
            'lead_id' => $lead->id,
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
            'starts_at' => $startsAt,
        ]);
    }

    public function test_completed_at_is_stamped_on_completion_and_cleared_on_reopen(): void
    {
        $bd = $this->bd();
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);

        $event = $this->event($bd, $lead, 'follow-up', 'scheduled', now()->subDay());
        $this->assertNull($event->completed_at);

        Carbon::setTestNow('2026-07-23 10:00:00');
        $event->update(['status' => 'completed']);
        $this->assertSame('2026-07-23 10:00:00', $event->fresh()->completed_at?->toDateTimeString());

        // A later edit that keeps it completed must NOT move the timestamp (never updated_at).
        Carbon::setTestNow('2026-07-25 09:00:00');
        $event->update(['title' => 'renamed']);
        $this->assertSame('2026-07-23 10:00:00', $event->fresh()->completed_at?->toDateTimeString());

        // Reopening clears it.
        $event->update(['status' => 'todo']);
        $this->assertNull($event->fresh()->completed_at);
        Carbon::setTestNow();
    }

    public function test_performance_summary_reports_scoped_discipline_and_completed_counts(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        $bd = $this->bd();
        $other = $this->bd();
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'created_by' => $bd->id]);
        $otherLead = Lead::factory()->create(['assigned_to' => $other->id, 'created_by' => $other->id]);

        // $bd: 2 overdue follow-ups, 1 overdue meeting, 1 overdue call → missed 2/1/1.
        $this->event($bd, $lead, 'follow-up', 'scheduled', now()->subDays(2));
        $this->event($bd, $lead, 'follow-up', 'todo', now()->subDays(3));
        $this->event($bd, $lead, 'meeting', 'scheduled', now()->subDay());
        $this->event($bd, $lead, 'call', 'scheduled', now()->subDay());
        // Not missed: a future one, and an already-completed overdue one.
        $this->event($bd, $lead, 'follow-up', 'scheduled', now()->addDay());
        $this->event($bd, $lead, 'follow-up', 'completed', now()->subDays(4)); // completed this month → counts
        // Noise on another BD must not leak.
        $this->event($other, $otherLead, 'follow-up', 'scheduled', now()->subDays(5));

        // The BD's own view.
        Sanctum::actingAs($bd);
        $self = $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk()->json('data');
        $this->assertSame(['missedFollowUps' => 2, 'missedMeetings' => 1, 'missedCalls' => 1], $self['followUpDiscipline']);
        $this->assertSame(1, $self['completedFollowUps']);

        // Admin drilling into the BD gets the identical figures; the aggregate is larger.
        Sanctum::actingAs($admin);
        $drill = $this->getJson("/api/v1/crm/dashboard/performance-summary?user_id={$bd->id}")->assertOk()->json('data');
        $this->assertSame($self['followUpDiscipline'], $drill['followUpDiscipline']);

        $all = $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk()->json('data');
        $this->assertSame(3, $all['followUpDiscipline']['missedFollowUps']); // 2 ($bd) + 1 ($other)
    }
}
