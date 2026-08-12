<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Planner ownership follows lead ownership — for OPEN work only.
 *
 * The rule (PO-approved): reassigning a lead hands the new owner everything still owed on it, and
 * hands them nothing already done. Completed events keep the BD who performed them, because
 * `completed_at` plus the Activity trail are the record of who did the work.
 *
 * These tests also pin the two properties that make the transfer safe to run in bulk: it must not
 * touch events deliberately assigned to a third BD, and it must not disturb the completion pipeline
 * (no `completed_at` restamp, no FollowUpCompleted, therefore no no-response escalation).
 */
class PlannerOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    /**
     * `cold_calling` is pinned to 'not-called' on purpose. LeadFactory randomises it, and a lead born
     * 'scheduled' makes LeadObserver auto-mint a follow-up through FollowUpScheduler — a second,
     * randomly-present event that also transfers. That behaviour is correct (and is covered by
     * test_qualification_self_assign_transfers_open_work), but leaving it random here would make every
     * count assertion below flaky.
     */
    private function lead(?User $owner): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'cold_calling' => 'not-called',
        ]);
    }

    private function event(
        ?User $owner,
        Lead $lead,
        string $type = 'call',
        string $status = 'scheduled',
        ?Carbon $startsAt = null,
    ): PlannerEvent {
        return PlannerEvent::create([
            'title' => 'Work on '.$lead->business_name,
            'event_type' => $type,
            'status' => $status,
            'starts_at' => $startsAt,
            'lead_id' => $lead->id,
            'assigned_to' => $owner?->id,
            'created_by' => $owner?->id,
        ]);
    }

    /** Admin reassigns the lead via the real endpoint. */
    private function reassign(User $admin, Lead $lead, User $to): void
    {
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $to->id])
            ->assertOk();
    }

    // ---------------------------------------------------------------- forward transfer

    public function test_open_work_follows_the_lead_on_reassignment(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $event = $this->event($from, $lead);

        $this->reassign($admin, $lead, $to);

        $this->assertSame($to->id, $event->fresh()->assigned_to);
    }

    public function test_completed_work_stays_with_the_bd_who_performed_it(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $done = $this->event($from, $lead, 'follow-up', 'completed');
        $completedAt = $done->fresh()->completed_at;

        $this->reassign($admin, $lead, $to);

        $done->refresh();
        $this->assertSame($from->id, $done->assigned_to, 'completed work must keep its performer');
        // The observer stamps completed_at on the transition into `completed` and clears it on
        // reopen — a transfer must do neither, or the "Completed Follow-ups" month window shifts.
        $this->assertEquals($completedAt, $done->completed_at);
    }

    public function test_a_third_bds_event_is_not_swept_up(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $specialist = $this->user('crm_bd');
        $lead = $this->lead($from);
        // Deliberately assigned to someone who is not the lead owner — a booked commitment, not work
        // that was following ownership. Handing over the lead does not hand over this.
        $theirs = $this->event($specialist, $lead, 'meeting');

        $this->reassign($admin, $lead, $to);

        $this->assertSame($specialist->id, $theirs->fresh()->assigned_to);
    }

    public function test_every_open_type_transfers_including_types_no_kpi_reports(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        // `task` is outside FOLLOW_UP_WORK_TYPES — no KPI bucket reports it, but it is still work the
        // new owner must do. Ownership is not a KPI question.
        $task = $this->event($from, $lead, 'task', 'todo');
        // Undated Board backlog — no starts_at, still owed.
        $backlog = $this->event($from, $lead, 'follow-up', 'todo');

        $this->reassign($admin, $lead, $to);

        $this->assertSame($to->id, $task->fresh()->assigned_to);
        $this->assertSame($to->id, $backlog->fresh()->assigned_to);
    }

    public function test_soft_deleted_events_are_left_alone(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $archived = $this->event($from, $lead);
        $archived->delete();

        $this->reassign($admin, $lead, $to);

        $this->assertSame($from->id, $archived->fresh()->assigned_to);
    }

    // ---------------------------------------------------------------- no-op guards

    public function test_reassigning_to_the_same_bd_changes_nothing(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $event = $this->event($bd, $lead);

        $this->reassign($admin, $lead, $bd);

        $this->assertSame($bd->id, $event->fresh()->assigned_to);
        $this->assertDatabaseMissing('crm_activities', ['event' => 'planner-event.reassigned']);
    }

    public function test_an_unassigned_leads_events_are_not_claimed(): void
    {
        $admin = $this->user('crm_admin');
        $holder = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        // Lead has no owner, but an event on it does — that event was never following ownership.
        $lead = $this->lead(null);
        $event = $this->event($holder, $lead);

        $this->reassign($admin, $lead, $to);

        $this->assertSame($holder->id, $event->fresh()->assigned_to);
    }

    // ---------------------------------------------------------------- qualification path

    public function test_qualification_self_assign_transfers_open_work(): void
    {
        $previous = $this->user('crm_bd');
        $claimer = $this->user('crm_bd');
        // A discovered prospect (source set) that was already assigned and already carries work.
        $lead = Lead::factory()->create([
            'assigned_to' => $previous->id,
            'source' => 'nearby',
            'qualified_at' => null,
        ]);
        $event = $this->event($previous, $lead, 'follow-up', 'todo');

        Sanctum::actingAs($claimer);
        $this->postJson("/api/v1/crm/leads/{$lead->id}/qualify")->assertCreated();

        $this->assertSame($claimer->id, $event->fresh()->assigned_to);
    }

    // ---------------------------------------------------------------- audit + regression guards

    public function test_a_transfer_logs_a_reassignment_activity_with_from_and_to(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $event = $this->event($from, $lead);

        $this->reassign($admin, $lead, $to);

        $activity = $lead->activities()
            ->where('event', 'planner-event.reassigned')
            ->get()
            ->sole(fn ($a): bool => $a->properties['planner_event_id'] === $event->id);
        $this->assertSame($from->id, $activity->properties['from']);
        $this->assertSame($to->id, $activity->properties['to']);
        $this->assertSame($event->id, $activity->properties['planner_event_id']);
        // A handover is not a generic edit — it must not also log one.
        $this->assertDatabaseMissing('crm_activities', ['event' => 'planner-event.updated']);
    }

    public function test_a_transfer_never_dispatches_follow_up_completed(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $this->event($from, $lead, 'follow-up', 'todo');

        Event::fake([FollowUpCompleted::class]);
        $this->reassign($admin, $lead, $to);

        // Guards the whole downstream chain: FollowUpCompleted drives EscalateUnansweredFollowUp and
        // the `follow-up.completed` webhook. A handover is not performed work and must trigger neither.
        Event::assertNotDispatched(FollowUpCompleted::class);
    }

    public function test_the_lead_assigned_activity_is_still_the_only_lead_level_log(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);

        $this->reassign($admin, $lead, $to);

        // assign() keeps saveQuietly() — the generic "Lead updated" Activity must stay suppressed.
        $this->assertSame(1, $lead->activities()->where('event', 'lead.assigned')->count());
        $this->assertDatabaseMissing('crm_activities', [
            'subject_id' => $lead->id,
            'event' => 'updated',
        ]);
    }

    // ---------------------------------------------------------------- KPI attribution

    public function test_a_missed_bucket_moves_with_the_lead_and_is_never_double_counted(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        // Overdue and open => a "missed call" for whoever it is attributed to.
        $this->event($from, $lead, 'call', 'scheduled', now()->subDays(3));

        $this->assertSame(1, $this->missedCalls($from), 'before: the owner carries it');
        $this->assertSame(0, $this->missedCalls($to), 'before: nobody else does');

        $this->reassign($admin, $lead, $to);

        $this->assertSame(0, $this->missedCalls($from), 'after: the old owner stops being penalised');
        $this->assertSame(1, $this->missedCalls($to), 'after: the new owner carries it');

        // THE REGRESSION THIS PINS. The defect being fixed is a row counted against TWO BDs at once —
        // the event stranded on the old owner while PlannerEvent::visibleTo also credited it to the new
        // one through the lead-ownership branch. Exactly one BD must carry it at any moment, before and
        // after. (Distinct from the BACKFILL case, where the lead has ALREADY moved and only the event
        // is catching up; there the gaining BD's figures do not change at all, because visibleTo was
        // already charging them. That property belongs to the backfill slice's tests.)
        $this->assertSame(1, $this->missedCalls($from) + $this->missedCalls($to));
    }

    public function test_completed_follow_ups_credit_the_performer_after_reassignment(): void
    {
        $admin = $this->user('crm_admin');
        $from = $this->user('crm_bd');
        $to = $this->user('crm_bd');
        $lead = $this->lead($from);
        $this->event($from, $lead, 'follow-up', 'completed');

        $this->reassign($admin, $lead, $to);

        Sanctum::actingAs($from);
        $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->assertJsonPath('data.completedFollowUps', 1);
    }

    private function missedCalls(User $bd): int
    {
        Sanctum::actingAs($bd);

        return (int) $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->json('data.followUpDiscipline.missedCalls');
    }
}
