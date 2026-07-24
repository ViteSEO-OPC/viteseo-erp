<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Calling\CallSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CallSessionService is the sole orchestration layer for the Calling Workspace, so these tests pin the
 * business workflow itself — independent of the HTTP surface. The frozen rules under test: Planner owns
 * the workflow (a Call is always subordinate to a PlannerEvent; ad-hoc is Planner-first), the disposition
 * policy (only `connected` completes the event; everything else keeps it open for a retry), the
 * single-active-call invariant, and the Activity-driven timeline.
 */
class CallSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CallSessionService
    {
        return app(CallSessionService::class);
    }

    /** An agent + a lead they own + a scheduled `call` event for that lead — the planned-call happy path. */
    private function plannedCall(): array
    {
        $agent = User::factory()->create();
        $this->actingAs($agent); // so the PlannerEventObserver stamps causer on planner-event.* activities
        $lead = Lead::factory()->create(['assigned_to' => $agent->id, 'created_by' => $agent->id]);
        $event = PlannerEvent::factory()->create([
            'event_type' => 'call',
            'status' => 'scheduled',
            'lead_id' => $lead->id,
            'assigned_to' => $agent->id,
            'created_by' => $agent->id,
        ]);

        return [$agent, $lead, $event];
    }

    public function test_start_for_event_opens_an_in_progress_call_and_logs_activity(): void
    {
        [$agent, $lead, $event] = $this->plannedCall();

        $call = $this->service()->startForEvent($event, $agent);

        $this->assertSame(CallStatus::IN_PROGRESS, $call->status);
        $this->assertSame($event->id, $call->planner_event_id);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame($agent->id, $call->user_id);
        $this->assertNotNull($call->started_at);

        // Planner reflects that work has begun.
        $this->assertSame('in_progress', $event->fresh()->status);

        // Timeline is Activity-driven: call.started on the lead, carrying the call id.
        $started = $lead->activities()->where('event', 'call.started')->first();
        $this->assertNotNull($started);
        $this->assertSame($call->id, $started->properties['call_id']);
        $this->assertSame($agent->id, $started->causer_id);
    }

    public function test_start_ad_hoc_is_planner_first_creating_the_owning_call_event(): void
    {
        $agent = User::factory()->create();
        $this->actingAs($agent);
        $lead = Lead::factory()->create(['assigned_to' => $agent->id, 'created_by' => $agent->id]);

        $call = $this->service()->startAdHoc($lead, $agent);

        // The owning event was created FIRST (Planner is the source of truth) and the call hangs off it.
        $event = $call->plannerEvent;
        $this->assertNotNull($event);
        $this->assertSame('call', $event->event_type);
        $this->assertSame('manual', $event->source);
        $this->assertSame('in_progress', $event->status);
        $this->assertSame($lead->id, $event->lead_id);
        $this->assertSame($agent->id, $event->assigned_to); // owner = lead's BD
        $this->assertSame(CallStatus::IN_PROGRESS, $call->status);
        $this->assertTrue($lead->activities()->where('event', 'call.started')->exists());
    }

    public function test_ad_hoc_event_owner_falls_back_to_the_agent_when_the_lead_is_unowned(): void
    {
        $agent = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => null, 'created_by' => $agent->id]);

        $call = $this->service()->startAdHoc($lead, $agent);

        $this->assertSame($agent->id, $call->plannerEvent->assigned_to);
    }

    public function test_connected_completes_the_owning_planner_event(): void
    {
        [$agent, $lead, $event] = $this->plannedCall();
        $call = $this->service()->startForEvent($event, $agent);

        $done = $this->service()->complete($call, CallOutcome::CONNECTED, 'Reached the decision maker', $agent);

        $this->assertSame(CallStatus::COMPLETED, $done->status);
        $this->assertSame(CallOutcome::CONNECTED, $done->outcome);
        $this->assertNotNull($done->ended_at);
        $this->assertSame('Reached the decision maker', $done->notes);

        // Only a connected call fulfils the planned activity → the event is completed (via the observer).
        $this->assertSame('completed', $event->fresh()->status);
        $this->assertTrue($lead->activities()->where('event', 'planner-event.completed')->exists());
        $this->assertTrue($lead->activities()->where('event', 'call.completed')->exists());
    }

    public function test_non_connected_outcomes_keep_the_event_open_for_a_retry(): void
    {
        foreach ([CallOutcome::NO_ANSWER, CallOutcome::BUSY, CallOutcome::VOICEMAIL, CallOutcome::FAILED] as $outcome) {
            [$agent, $lead, $event] = $this->plannedCall();
            $call = $this->service()->startForEvent($event, $agent);

            $this->service()->complete($call, $outcome, null, $agent);

            // The event stays OPEN (in_progress) — the whole reason for the 1:many model.
            $this->assertSame('in_progress', $event->fresh()->status, "outcome {$outcome->value} must keep the event open");
            $this->assertFalse(
                $lead->activities()->where('event', 'planner-event.completed')->exists(),
                "outcome {$outcome->value} must not complete the planner event",
            );
        }
    }

    public function test_discard_records_a_cancelled_attempt_and_keeps_the_event_open(): void
    {
        [$agent, $lead, $event] = $this->plannedCall();
        $call = $this->service()->startForEvent($event, $agent);

        $discarded = $this->service()->discard($call, $agent);

        $this->assertSame(CallStatus::COMPLETED, $discarded->status);
        $this->assertSame(CallOutcome::CANCELLED, $discarded->outcome); // tracked, never deleted
        $this->assertNull($discarded->deleted_at);
        $this->assertSame('in_progress', $event->fresh()->status);
    }

    public function test_only_one_active_call_per_event_is_allowed(): void
    {
        [$agent, , $event] = $this->plannedCall();
        $this->service()->startForEvent($event, $agent);

        $this->expectException(ValidationException::class);
        $this->service()->startForEvent($event->fresh(), $agent);
    }

    public function test_a_retry_is_allowed_once_the_active_call_has_concluded(): void
    {
        [$agent, , $event] = $this->plannedCall();
        $first = $this->service()->startForEvent($event, $agent);
        $this->service()->complete($first, CallOutcome::NO_ANSWER, null, $agent);

        // No active call remains, so the open event accepts another attempt.
        $retry = $this->service()->startForEvent($event->fresh(), $agent);

        $this->assertSame(CallStatus::IN_PROGRESS, $retry->status);
        $this->assertSame(2, Call::where('planner_event_id', $event->id)->count());
    }

    public function test_completing_an_already_concluded_call_is_rejected(): void
    {
        [$agent, , $event] = $this->plannedCall();
        $call = $this->service()->startForEvent($event, $agent);
        $this->service()->complete($call, CallOutcome::CONNECTED, null, $agent);

        $this->expectException(ValidationException::class);
        $this->service()->complete($call->fresh(), CallOutcome::BUSY, null, $agent);
    }

    public function test_starting_a_call_requires_a_lead_linked_event(): void
    {
        $agent = User::factory()->create();
        $event = PlannerEvent::factory()->create(['event_type' => 'call', 'lead_id' => null]);

        $this->expectException(ValidationException::class);
        $this->service()->startForEvent($event, $agent);
    }

    public function test_duration_is_server_computed_from_timestamps_and_never_negative(): void
    {
        [$agent, , $event] = $this->plannedCall();

        // Freeze the clock: start at T, conclude 90s later → duration = 90.
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00'));
        $call = $this->service()->startForEvent($event, $agent);
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:01:30'));
        $done = $this->service()->complete($call, CallOutcome::CONNECTED, null, $agent);
        $this->assertSame(90, $done->duration_seconds);

        // Clock skew (started_at in the future relative to ended_at) is clamped to zero, never negative.
        [$agent2, , $event2] = $this->plannedCall();
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));
        $skewed = $this->service()->startForEvent($event2, $agent2);
        Carbon::setTestNow(Carbon::parse('2026-07-23 11:59:00')); // clock moved backwards
        $skewedDone = $this->service()->complete($skewed, CallOutcome::CONNECTED, null, $agent2);
        $this->assertSame(0, $skewedDone->duration_seconds);

        Carbon::setTestNow();
    }
}
