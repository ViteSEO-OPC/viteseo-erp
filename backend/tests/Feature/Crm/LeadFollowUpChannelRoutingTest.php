<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Calling\CallSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LEAD DETAILS → SCHEDULE FOLLOW-UP — channel routing (Model B, Phase 2).
 *
 * The modal used to flatten every choice to `event_type = 'follow-up'`, leaving the BD's channel
 * alive only as a title prefix — which is not evidence, because `title` is user-editable. It now
 * persists the channel structurally. These tests pin the contract from the API side, exactly as the
 * modal posts it.
 *
 * Phase 1 is what makes this non-destructive: every consumer that means the CATEGORY now asks
 * {@see PlannerEvent::FOLLOW_UP_WORK_TYPES} instead of matching the bare string, so routing a row to
 * `email` / `call` / `meeting` does not drop it out of reporting. The end-to-end assertions below
 * exist to prove that claim through the real endpoints rather than restating it.
 *
 * `task` is the one channel that DOES leave follow-up-work reporting (PO decision 3 — a `task` is the
 * OUTPUT of completed follow-up work, created by EscalateUnansweredFollowUp). That is a deliberate
 * semantic change, so it gets its own named test rather than living as a silent side effect.
 *
 * The final section pins the cold-call dedupe guard, which the PO ruled on separately: a PLANNED call
 * counts as the follow-up work the auto-trigger would otherwise duplicate. That rule is narrow on
 * purpose and its edges are load-bearing, so each edge is a named test rather than an implied one.
 */
class LeadFollowUpChannelRoutingTest extends TestCase
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

    /**
     * `cold_calling` is pinned because LeadFactory randomises it and LeadObserver auto-creates a
     * cold-call `follow-up` on `scheduled` — which would forge the very row these tests assert absent.
     */
    private function ownedLead(User $bd): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
            'cold_calling' => 'not-called',
            'email_outreach' => 'none',
        ]);
    }

    /**
     * The exact shape schedule-follow-up-modal.tsx posts: no `assigned_to` (a BD creator self-assigns
     * in the controller) and the title prefix retained for readability only.
     *
     * @return array<string, mixed>
     */
    private function payload(Lead $lead, string $label, string $eventType): array
    {
        return [
            'title' => "{$label} — {$lead->business_name}",
            'event_type' => $eventType,
            'status' => 'scheduled',
            'priority' => 'medium',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => null,
            'all_day' => false,
            'reminder_minutes' => 15,
            'lead_id' => $lead->id,
            'source' => 'manual',
            'description' => null,
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function channels(): array
    {
        return [
            'Call' => ['Call', 'call'],
            'Email' => ['Email', 'email'],
            'Meeting' => ['Meeting', 'meeting'],
            'Task' => ['Task', 'task'],
        ];
    }

    #[DataProvider('channels')]
    public function test_each_channel_is_persisted_structurally(string $label, string $eventType): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload($lead, $label, $eventType))
            ->assertCreated()
            ->assertJsonPath('data.eventType', $eventType);

        $this->assertDatabaseHas('crm_planner_events', [
            'lead_id' => $lead->id,
            'event_type' => $eventType,
            'assigned_to' => $bd->id,   // BD creator self-assigns; the modal sends no owner
            'source' => 'manual',
        ]);

        // The regression that matters: the choice is no longer collapsed into the generic bucket.
        $this->assertDatabaseMissing('crm_planner_events', [
            'lead_id' => $lead->id,
            'event_type' => 'follow-up',
        ]);
    }

    /**
     * The three communication channels stay inside the shared category, so nothing a BD schedules
     * from Lead Details vanishes from reporting the day routing flips. Proven through the live
     * dashboard KPI, not by re-asserting the constant.
     */
    public function test_email_call_and_meeting_still_count_as_follow_up_work(): void
    {
        $bd = $this->bd();
        Sanctum::actingAs($bd);

        foreach ([['Email', 'email'], ['Call', 'call'], ['Meeting', 'meeting']] as [$label, $type]) {
            $lead = $this->ownedLead($bd);
            $this->postJson('/api/v1/crm/planner-events', $this->payload($lead, $label, $type))
                ->assertCreated();
        }

        $this->assertSame(
            3,
            (int) $this->getJson('/api/v1/crm/dashboard/kpis')->assertOk()->json('data.followUpsDue'),
        );
    }

    /**
     * DELIBERATE semantic change, not an oversight. A "Task" follow-up scheduled from Lead Details no
     * longer counts as follow-up work — `task` is excluded from FOLLOW_UP_WORK_TYPES because
     * EscalateUnansweredFollowUp creates `task` rows as the RESULT of a completed follow-up, and
     * counting them would let one BD action inflate the metric twice.
     */
    public function test_the_task_channel_leaves_follow_up_work_reporting(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload($lead, 'Task', 'task'))
            ->assertCreated();

        $this->assertSame(
            0,
            (int) $this->getJson('/api/v1/crm/dashboard/kpis')->assertOk()->json('data.followUpsDue'),
        );
    }

    /**
     * The generic type keeps its single birth path. Posting `follow-up` still delegates to
     * FollowUpScheduler, which is observable through the defaults it fills that the generic branch
     * does not — `source` defaults to 'outreach' there, and to 'manual' here.
     */
    public function test_the_generic_follow_up_type_still_routes_through_the_scheduler(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', [
            'title' => 'Follow-up',
            'event_type' => 'follow-up',
            'lead_id' => $lead->id,
            'starts_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.eventType', 'follow-up');

        $this->assertDatabaseHas('crm_planner_events', [
            'lead_id' => $lead->id,
            'event_type' => 'follow-up',
            'source' => 'outreach',     // the scheduler's default — proves the delegation still happens
            'status' => 'todo',
        ]);
    }

    // ── The cold-call dedupe guard ──────────────────────────────────────────────────────────────
    //
    // PO decision: a Call the BD already PLANNED from Lead Details is the same work the cold-call
    // auto-follow-up would create, so it must suppress it. The rule stays narrow — see
    // FollowUpScheduler::coldCallFollowUpIsRedundant(). These tests pin both edges of it.

    /** The decision itself: no duplicate for the call workflow. */
    public function test_a_planned_call_suppresses_the_cold_call_auto_follow_up(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload($lead, 'Call', 'call'))
            ->assertCreated();

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertSame(1, PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'call')->count());
        $this->assertSame(
            0,
            PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->count(),
            'the planned call IS the follow-up work — no duplicate may be minted alongside it',
        );
    }

    /**
     * The other three channels are different work from a call, so they must NOT suppress it. This is
     * the half of the decision that is easy to over-apply: the guard admits `call` only.
     */
    #[DataProvider('nonCallChannels')]
    public function test_other_channels_still_leave_the_cold_call_follow_up_alone(string $label, string $eventType): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload($lead, $label, $eventType))
            ->assertCreated();

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertSame(1, PlannerEvent::where('lead_id', $lead->id)->where('event_type', $eventType)->count());
        $this->assertSame(
            1,
            PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->count(),
            "a planned {$eventType} is not call work — the cold-call follow-up is still owed",
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonCallChannels(): array
    {
        return [
            'Email' => ['Email', 'email'],
            'Meeting' => ['Meeting', 'meeting'],
            'Task' => ['Task', 'task'],
        ];
    }

    /**
     * THE HAZARD Phase 1 documented, restated against the new rule. A cold call leaves its own OPEN
     * `call` event behind (CallSessionService mints it `in_progress` with a NULL `starts_at`, and
     * completes it only on CONNECTED). The guard must stay blind to that shape, or it would suppress
     * the very follow-up it exists to create. Driven through the real service, not a hand-built row,
     * so a change to the Calling lifecycle breaks this test rather than silently breaking the guard.
     */
    public function test_an_in_progress_ad_hoc_call_still_does_not_suppress_the_follow_up(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);

        app(CallSessionService::class)->startAdHoc($lead, $bd);

        $event = PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'call')->sole();
        $this->assertSame('in_progress', $event->status);
        $this->assertNull($event->starts_at, 'an ad-hoc call is undated — the guard depends on this');

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertSame(
            1,
            PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->count(),
            'the guard must not be blinded by the call that triggered it',
        );
    }

    /**
     * The `starts_at` half of the rule is load-bearing, not decoration: an undated `call` sitting in
     * the Board backlog and dragged to `scheduled` is not planned work and must not suppress.
     */
    public function test_an_undated_scheduled_call_does_not_suppress_the_follow_up(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);

        PlannerEvent::create([
            'title' => 'Call — backlog',
            'event_type' => 'call',
            'status' => 'scheduled',
            'starts_at' => null,
            'lead_id' => $lead->id,
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
            'source' => 'manual',
        ]);

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertSame(1, PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->count());
    }

    /** A planned call that is already done is spent work and cannot stand in for the follow-up. */
    public function test_a_completed_planned_call_does_not_suppress_the_follow_up(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', $this->payload($lead, 'Call', 'call'))
            ->assertCreated();

        PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'call')->sole()
            ->update(['status' => 'completed']);

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertSame(1, PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->count());
    }

    /** With no prior work at all, the cold-call workflow is untouched and still mints its follow-up. */
    public function test_the_cold_call_workflow_still_creates_its_follow_up(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);

        $lead->update(['cold_calling' => 'scheduled']);

        $created = PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->sole();
        $this->assertSame('todo', $created->status);
        $this->assertSame('outreach', $created->source);
    }

    /** The original clause of the guard, unchanged — the rule gained a branch, it did not swap one. */
    public function test_an_open_generic_follow_up_still_suppresses_the_cold_call_auto_follow_up(): void
    {
        $bd = $this->bd();
        $lead = $this->ownedLead($bd);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/planner-events', [
            'title' => 'Follow-up',
            'event_type' => 'follow-up',
            'lead_id' => $lead->id,
        ])->assertCreated();

        $lead->update(['cold_calling' => 'scheduled']);

        $this->assertSame(1, PlannerEvent::where('lead_id', $lead->id)->where('event_type', 'follow-up')->count());
    }
}
