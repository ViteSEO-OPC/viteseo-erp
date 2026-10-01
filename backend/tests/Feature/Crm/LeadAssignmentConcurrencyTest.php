<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\LeadAssigned;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Notifications\LeadAssignedNotification;
use App\Modules\Crm\Notifications\LeadsReassignedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CEO audit crm-14(c) — a lead changes hands exactly once, from its committed owner.
 *
 * Two defects, both reproduced on the audited baseline:
 *  1. LeadController::assign() ignored LeadAssignmentService::assign()'s `false` ("nothing changed"),
 *     so re-assigning a lead to the BD who already owned it — a repeat submit, or a bulk selection that
 *     includes leads they already hold — still sent an "assigned to you" notification and a realtime
 *     push for a handover that never happened.
 *  2. The service compared and wrote against the owner read BEFORE its transaction (route binding or
 *     the resignation query). An overlapping handover that committed in between was overwritten, logged
 *     a second `lead.assigned`, and moved open Planner work FROM an owner who no longer held it —
 *     stranding that work with the BD who had just lost the lead.
 *
 * Races are proven like {@see LeadQualificationTest}: SQLite has no row locks, so the competing
 * handover is committed at the exact point this request's transaction opens — after it validated its
 * payload and read its (now stale) lead, before its locked re-read. The competitor runs as a real
 * request, so every side effect is counted.
 */
class LeadAssignmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Event::fake([LeadAssigned::class]);
        $this->admin = User::factory()->create(['status' => 'active', 'name' => 'Admin']);
        $this->admin->assignRole('crm_admin');
    }

    private function bd(string $name): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole('crm_bd');

        return $user;
    }

    private function lead(?User $owner, string $coldCalling = 'not-called'): Lead
    {
        return Lead::factory()->create(['assigned_to' => $owner?->id, 'cold_calling' => $coldCalling]);
    }

    private function openCall(Lead $lead, User $owner): PlannerEvent
    {
        return PlannerEvent::create([
            'title' => 'Call '.$lead->business_name, 'event_type' => 'call', 'status' => 'scheduled',
            'lead_id' => $lead->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
        ]);
    }

    private function assign(Lead $lead, ?User $to): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $to?->id]);
    }

    private function handovers(Lead $lead): int
    {
        return Activity::query()->where('subject_id', $lead->id)->where('event', 'lead.assigned')->count();
    }

    private function assignmentNotifications(User $user): int
    {
        return $user->notifications()->where('type', LeadAssignedNotification::class)->count();
    }

    /** Commits a competing handover of `$lead` to `$to` the moment the next request opens its transaction. */
    private function competingHandoverAtTransactionStart(Lead $lead, User $to, bool &$fired, ?int &$status): void
    {
        Event::listen(TransactionBeginning::class, function () use ($lead, $to, &$fired, &$status): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $status = $this->assign($lead, $to)->status();
        });
    }

    // ── 1. Real handovers are unchanged ────────────────────────────────────────────────────────

    public function test_a_real_reassignment_writes_one_activity_one_notification_and_one_push(): void
    {
        [$a, $b] = [$this->bd('BD A'), $this->bd('BD B')];
        $lead = $this->lead($a);
        $work = $this->openCall($lead, $a);

        $this->assign($lead, $b)->assertOk()->assertJsonPath('data.assignedTo.name', 'BD B');

        $this->assertSame($b->id, $lead->fresh()->assigned_to);
        $this->assertSame(1, $this->handovers($lead));
        $this->assertSame(1, $this->assignmentNotifications($b));
        $this->assertSame(0, $this->assignmentNotifications($a));
        Event::assertDispatchedTimes(LeadAssigned::class, 1);
        $this->assertSame($b->id, $work->fresh()->assigned_to, 'open work follows the lead');
    }

    // ── 2. A no-op handover announces nothing ──────────────────────────────────────────────────

    public function test_assigning_a_lead_to_its_current_owner_is_a_quiet_successful_no_op(): void
    {
        $b = $this->bd('BD B');
        $lead = $this->lead($b);

        $this->assign($lead, $b)->assertOk()->assertJsonPath('data.assignedTo.name', 'BD B');

        $this->assertSame(0, $this->handovers($lead));
        $this->assertSame(0, $this->assignmentNotifications($b), 'no handover happened, so none is announced');
        Event::assertNotDispatched(LeadAssigned::class);
    }

    public function test_a_repeat_submit_after_the_handover_committed_is_announced_once(): void
    {
        $b = $this->bd('BD B');
        $lead = $this->lead(null);

        $this->assign($lead, $b)->assertOk();
        $this->assign($lead->fresh(), $b)->assertOk();

        $this->assertSame(1, $this->handovers($lead));
        $this->assertSame(1, $this->assignmentNotifications($b));
        Event::assertDispatchedTimes(LeadAssigned::class, 1);
    }

    /** Bulk Assign fans out one PATCH per selected lead; only the leads that actually move are announced. */
    public function test_bulk_assigning_a_selection_that_includes_the_bds_own_leads_announces_only_real_moves(): void
    {
        $b = $this->bd('BD B');
        $alreadyHers = collect([$this->lead($b), $this->lead($b), $this->lead($b)]);
        $unowned = $this->lead(null);

        $alreadyHers->push($unowned)->each(fn (Lead $lead) => $this->assign($lead, $b)->assertOk());

        $this->assertSame(1, $this->assignmentNotifications($b));
        $this->assertSame(0, $alreadyHers->reject(fn (Lead $l) => $l->is($unowned))->sum(fn (Lead $l) => $this->handovers($l)));
        $this->assertSame(1, $this->handovers($unowned));
        Event::assertDispatchedTimes(LeadAssigned::class, 1);
    }

    public function test_clearing_an_owner_still_logs_the_removal_and_notifies_nobody(): void
    {
        $a = $this->bd('BD A');
        $lead = $this->lead($a);

        $this->assign($lead, null)->assertOk();

        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertSame(1, $this->handovers($lead));
        $this->assertSame(0, $this->assignmentNotifications($a));
        Event::assertNotDispatched(LeadAssigned::class);
    }

    // ── 3. Overlapping handovers use the committed owner ───────────────────────────────────────

    public function test_an_overlapping_handover_to_the_same_owner_happens_once(): void
    {
        [$a, $b] = [$this->bd('BD A'), $this->bd('BD B')];
        $lead = $this->lead($a);
        $work = $this->openCall($lead, $a);

        $fired = false;
        $competitor = null;
        $this->competingHandoverAtTransactionStart($lead, $b, $fired, $competitor);

        $this->assign($lead, $b)->assertOk()->assertJsonPath('data.assignedTo.name', 'BD B');

        $this->assertTrue($fired, 'the race window was never exercised');
        $this->assertSame(200, $competitor);
        $this->assertSame($b->id, $lead->fresh()->assigned_to);
        $this->assertSame(1, $this->handovers($lead), 'one handover, one lead.assigned');
        $this->assertSame(1, $this->assignmentNotifications($b), 'one handover, one notification');
        Event::assertDispatchedTimes(LeadAssigned::class, 1);
        $this->assertSame($b->id, $work->fresh()->assigned_to);
    }

    public function test_an_overlapping_handover_to_a_different_owner_moves_on_from_the_committed_owner(): void
    {
        [$a, $b, $c] = [$this->bd('BD A'), $this->bd('BD B'), $this->bd('BD C')];
        $lead = $this->lead($a);
        $work = $this->openCall($lead, $a);

        // A→C commits first; this request (A→B, read while A still owned it) must hand over FROM C.
        $fired = false;
        $competitor = null;
        $this->competingHandoverAtTransactionStart($lead, $c, $fired, $competitor);

        $this->assign($lead, $b)->assertOk()->assertJsonPath('data.assignedTo.name', 'BD B');

        $this->assertTrue($fired, 'the race window was never exercised');
        $this->assertSame(200, $competitor);
        $this->assertSame($b->id, $lead->fresh()->assigned_to, 'the later handover is the committed owner');
        $this->assertSame($b->id, $work->fresh()->assigned_to, 'open Planner work must follow the final owner, not stay stranded with BD C');
        $this->assertSame(2, $this->handovers($lead), 'two real handovers: A→C, then C→B');
        $this->assertSame(1, $this->assignmentNotifications($b));
        $this->assertSame(1, $this->assignmentNotifications($c));

        // PlannerEventObserver logs a lead-linked event's history on the LEAD, keyed by planner_event_id.
        $reassigned = Activity::query()
            ->where('subject_id', $lead->id)
            ->where('event', 'planner-event.reassigned')->orderBy('id')->get()
            ->filter(fn (Activity $x) => ($x->properties['planner_event_id'] ?? null) === $work->id)
            ->map(fn (Activity $x) => [$x->properties['from'], $x->properties['to']])->values()->all();
        $this->assertSame([[$a->id, $c->id], [$c->id, $b->id]], $reassigned, 'the work moved A→C→B, each hop from its real holder');
    }

    public function test_a_first_acquisition_race_does_not_strand_the_cold_call_follow_up(): void
    {
        [$b, $c] = [$this->bd('BD B'), $this->bd('BD C')];
        $lead = $this->lead(null, 'scheduled');

        $fired = false;
        $competitor = null;
        $this->competingHandoverAtTransactionStart($lead, $c, $fired, $competitor);

        $this->assign($lead, $b)->assertOk();

        $this->assertTrue($fired, 'the race window was never exercised');
        $this->assertSame($b->id, $lead->fresh()->assigned_to);
        $open = PlannerEvent::query()->where('lead_id', $lead->id)->where('status', '!=', 'completed')->get();
        $this->assertCount(1, $open, 'exactly one cold-call follow-up');
        $this->assertSame($b->id, $open->first()->assigned_to, 'the follow-up belongs to the final owner');
    }

    // ── 4. The resignation handover shares the service and stays correct ───────────────────────

    public function test_the_resignation_handover_still_moves_every_lead_and_its_work_once(): void
    {
        [$leaving, $replacement] = [$this->bd('Leaving BD'), $this->bd('Replacement BD')];
        $first = $this->lead($leaving);
        $second = $this->lead($leaving);
        $work = $this->openCall($first, $leaving);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/crm/users/{$leaving->id}/reassign-leads", ['to_user_id' => $replacement->id])
            ->assertOk()
            ->assertJsonPath('data.leads_reassigned', 2);

        $this->assertSame($replacement->id, $first->fresh()->assigned_to);
        $this->assertSame($replacement->id, $second->fresh()->assigned_to);
        $this->assertSame(1, $this->handovers($first));
        $this->assertSame(1, $this->handovers($second));
        $this->assertSame($replacement->id, $work->fresh()->assigned_to);
        $this->assertSame(0, $this->assignmentNotifications($replacement), 'the resignation sends one summary bell, not per-lead bells');
        $this->assertSame(1, $replacement->notifications()->where('type', LeadsReassignedNotification::class)->count());
    }
}
