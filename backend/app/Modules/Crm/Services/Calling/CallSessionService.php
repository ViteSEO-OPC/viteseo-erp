<?php

namespace App\Modules\Crm\Services\Calling;

use App\Models\User;
use App\Modules\Crm\Enums\CallDurationSource;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Enums\CallStatus;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single orchestration layer for the Calling Workspace. EVERY call attempt — planned or ad-hoc,
 * started, completed, or discarded — is born and transitioned here; controllers never touch the Call
 * model directly (mirrors {@see FollowUpScheduler} being the sole
 * creator of follow-ups). The Call model and its enums stay passive: they hold state, this service
 * moves between states.
 *
 * Invariants this service enforces (they are the point of the class):
 *  - Planner owns the workflow. A Call can never exist without its Planner Event, so {@see startAdHoc}
 *    is Planner-FIRST: it creates the owning `call` event before the Call (like ScheduledEmailService's
 *    send-now vs scheduled split). Completion flows through the normal PlannerEvent save so
 *    PlannerEventObserver logs `planner-event.completed` — never a bypass, never duplicated status logic.
 *  - `crm_calls` is a SUBORDINATE detail record — it carries call metadata only; business state (the
 *    timeline) stays Activity-driven via `call.started` / `call.completed` on the lead.
 *  - At most ONE active (`in_progress`) call per Planner Event (the 1:many retry workflow). Enforced
 *    under a row lock on the owning event so concurrent "Start Call" clicks cannot race past it.
 *
 * Disposition policy (FROZEN): only a `connected` call completes the owning Planner Event; every other
 * outcome — no_answer, busy, voicemail, failed, and a discarded `cancelled` — leaves the event OPEN for
 * another attempt, which is exactly what the PlannerEvent → Calls 1:many model exists for. Discarding an
 * attempt is tracked as `cancelled`, never deleted.
 */
class CallSessionService
{
    /** Planner Event states that already have (or no longer need) a call underway — do not re-open. */
    private const EVENT_NOT_REOPENABLE = ['in_progress', 'completed'];

    /**
     * Start a call attempt against an EXISTING Planner Event — the planned-call happy path (a BD clicks
     * "Start Call" on a scheduled call task). The event must be lead-linked; the single-active-call
     * invariant is enforced. Returns the freshly opened `in_progress` Call.
     */
    public function startForEvent(
        PlannerEvent $event,
        User $agent,
        string $provider = ManualCallProvider::NAME,
        ?string $toNumber = null,
    ): Call {
        return DB::transaction(fn (): Call => $this->open($event, $agent, $provider, $toNumber));
    }

    /**
     * Ad-hoc "Start Call" — no pre-existing event. Planner-FIRST: creates the owning `call` PlannerEvent
     * (so Planner stays the source of truth), then opens the Call against it, atomically. The event is
     * owned by the lead's BD when there is one, else the acting agent.
     */
    public function startAdHoc(
        Lead $lead,
        User $agent,
        string $provider = ManualCallProvider::NAME,
        ?string $toNumber = null,
    ): Call {
        return DB::transaction(function () use ($lead, $agent, $provider, $toNumber): Call {
            $event = PlannerEvent::create([
                'event_type' => 'call',
                'title' => "{$this->leadName($lead)} — call",
                // The event is being worked the moment the widget opens; the Call is created next.
                'status' => 'in_progress',
                // `source` is a closed domain (manual|nearby|outreach|system); `event_type = call`
                // already marks provenance, so an ad-hoc call is a manual, human-initiated event.
                'source' => 'manual',
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to ?? $agent->id,
                'created_by' => $agent->id,
            ]);

            return $this->open($event, $agent, $provider, $toNumber);
        });
    }

    /**
     * Conclude an in-progress call with a disposition. Sets the end time + server-computed duration,
     * records the outcome/notes, and — only when the call `connected` — completes the owning Planner
     * Event (any other outcome keeps it open for a retry). Logs the `call.completed` Activity.
     */
    public function complete(Call $call, CallOutcome $outcome, ?string $notes, User $agent): Call
    {
        return DB::transaction(fn (): Call => $this->finalize($call, $outcome, $notes, $agent));
    }

    /**
     * Abandon an in-progress call without a real disposition (the UI "Discard"). Tracked as `cancelled`
     * (never deleted — the attempt stays on the record), and never completes the Planner Event.
     */
    public function discard(Call $call, User $agent): Call
    {
        return DB::transaction(fn (): Call => $this->finalize($call, CallOutcome::CANCELLED, null, $agent));
    }

    /**
     * Record the provider's own id for a call (Twilio's Call SID), once its webhook first reaches us.
     *
     * Idempotent and write-once: a retried webhook re-sends the same SID and must not churn the row,
     * and a DIFFERENT sid arriving for a call that already has one is ignored rather than allowed to
     * overwrite — that would silently re-point a CRM record at another provider call.
     */
    public function attachProviderCall(Call $call, string $externalCallId): Call
    {
        if ($call->external_call_id === null && $externalCallId !== '') {
            $call->forceFill(['external_call_id' => $externalCallId])->save();
        }

        return $call;
    }

    /**
     * Conclude a call from the PROVIDER's report rather than a BD's selection.
     *
     * Two things separate this from {@see complete}, and both exist because the caller is an
     * unauthenticated webhook that may fire more than once:
     *
     *  - It is IDEMPOTENT. A duplicate callback for an already-concluded call is a no-op that returns
     *    the row untouched, instead of {@see assertActive}'s ValidationException. Twilio retries on any
     *    non-2xx, so throwing here would turn one delivery hiccup into an error loop.
     *  - The duration is the CARRIER's, not the wall-clock between two API calls. `$durationSeconds`
     *    is the dialed leg's real talk time; it is only trusted when the provider actually reported
     *    one, and the CRM's own measurement is used as the fallback.
     *
     * There is no acting user — nobody clicked anything — so the Activity is attributed to the agent
     * who opened the call, which is who placed it.
     */
    public function completeFromProvider(Call $call, CallOutcome $outcome, ?int $durationSeconds): Call
    {
        return DB::transaction(function () use ($call, $outcome, $durationSeconds): Call {
            // Re-read under a lock: two Twilio retries can land concurrently, and without this both
            // would see `in_progress` and both would finalize.
            //
            // Unscoped for the same reason the webhook's lookup is: the caller is an unauthenticated
            // provider callback, so the tenant scope would resolve to the wrong org and lose the row.
            // Identity is not at stake — this re-reads BY PRIMARY KEY a model the caller already
            // holds, whose org was fixed when an authenticated BD opened it.
            $locked = Call::query()
                ->withoutGlobalScope('org')
                ->whereKey($call->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== CallStatus::IN_PROGRESS) {
                return $locked;
            }

            $agent = $locked->agent()->first();
            $endedAt = now();

            $locked->status = CallStatus::COMPLETED;
            $locked->outcome = $outcome;
            $locked->ended_at = $endedAt;
            // The carrier's number when it reported one, the CRM's own clock otherwise — and the row
            // records WHICH, because `provider = twilio` alone would present a fallback measurement as
            // verified talk time.
            $locked->duration_seconds = $durationSeconds ?? $this->durationSeconds($locked->started_at, $endedAt);
            $locked->duration_source = $durationSeconds === null
                ? CallDurationSource::MANUAL
                : CallDurationSource::PROVIDER;
            $locked->save();

            $event = $locked->plannerEvent()->firstOrFail();

            // The SAME frozen disposition policy the manual path uses — only a connected call fulfills
            // the planned activity. Deliberately not re-implemented differently for providers.
            if ($outcome === CallOutcome::CONNECTED && $event->status !== 'completed') {
                $event->update(['status' => 'completed']);
            }

            if ($agent !== null) {
                $this->logActivity(
                    $locked->lead()->firstOrFail(),
                    $agent,
                    'call.completed',
                    "{$agent->name} logged a {$outcome->label()} call with {$this->leadName($locked->lead)}",
                    [
                        'call_id' => $locked->id,
                        'planner_event_id' => $locked->planner_event_id,
                        'outcome' => $outcome->value,
                        'duration_seconds' => $locked->duration_seconds,
                        // Marks the disposition as carrier-reported rather than BD-selected, so the
                        // timeline can never be mistaken for a human judgement.
                        'reported_by' => $locked->provider,
                    ],
                );
            }

            return $locked;
        });
    }

    /**
     * Shared open path (runs inside a transaction opened by the caller). Locks the owning event row so
     * concurrent starts serialize, enforces the lead + single-active-call invariants, creates the Call,
     * reflects the start on the Planner board, and logs `call.started`.
     */
    private function open(
        PlannerEvent $event,
        User $agent,
        string $provider = ManualCallProvider::NAME,
        ?string $toNumber = null,
    ): Call {
        // Row lock on the owning event — the single-active-call guard below is only safe under it.
        $event = PlannerEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

        if ($event->lead_id === null) {
            throw ValidationException::withMessages([
                'planner_event' => 'A call must belong to a lead-linked Planner Event.',
            ]);
        }

        $hasActiveCall = Call::query()
            ->where('planner_event_id', $event->id)
            ->where('status', CallStatus::IN_PROGRESS->value)
            ->exists();

        if ($hasActiveCall) {
            throw ValidationException::withMessages([
                'call' => 'This Planner Event already has a call in progress.',
            ]);
        }

        $lead = $event->lead()->firstOrFail();

        // `provider` names the transport this attempt uses and `to_number` snapshots what was handed
        // to a carrier. Under the manual provider the CRM does not dial, so `to_number` stays null
        // rather than copying the lead's number and implying knowledge the CRM does not have.
        $call = new Call([
            'started_at' => now(),
            'provider' => $provider,
            'to_number' => $toNumber,
            // Every call starts as CRM-timed, including a browser one: until a carrier reports a
            // duration there is nothing but the CRM's own clock, and a Twilio call whose completion
            // webhook never arrives must not end up claiming verified talk time it never received.
            'duration_source' => CallDurationSource::MANUAL,
        ]);
        $call->plannerEvent()->associate($event);
        $call->lead()->associate($lead);
        $call->agent()->associate($agent);
        $call->save();

        // Planner owns the workflow: starting a call moves an idle event into `in_progress`. Already
        // in-progress / completed events are left as-is (no redundant write, no resurrecting a done
        // event). The observer logs the resulting `planner-event.updated` Activity.
        if (! in_array($event->status, self::EVENT_NOT_REOPENABLE, true)) {
            $event->update(['status' => 'in_progress']);
        }

        $this->logActivity($lead, $agent, 'call.started', "{$agent->name} started a call with {$this->leadName($lead)}", [
            'call_id' => $call->id,
            'planner_event_id' => $event->id,
            'direction' => $call->direction->value,
        ]);

        return $call;
    }

    /**
     * Shared completion path (runs inside a transaction opened by the caller). Guards that the call is
     * still active, stamps the disposition + duration, applies the Planner-Event completion policy, and
     * logs `call.completed`.
     */
    private function finalize(Call $call, CallOutcome $outcome, ?string $notes, User $agent): Call
    {
        $this->assertActive($call);

        $endedAt = now();

        $call->status = CallStatus::COMPLETED;
        $call->outcome = $outcome;
        $call->ended_at = $endedAt;
        $call->duration_seconds = $this->durationSeconds($call->started_at, $endedAt);
        if ($notes !== null) {
            $call->notes = $notes;
        }
        $call->save();

        $event = $call->plannerEvent()->firstOrFail();

        // Disposition policy: only a connected call fulfills the planned activity → complete the event
        // (the observer logs `planner-event.completed`). Every other outcome — including a discarded
        // `cancelled` — leaves the event OPEN so the BD can retry (the 1:many workflow). Idempotent guard
        // so we never re-save an already-completed event.
        if ($outcome === CallOutcome::CONNECTED && $event->status !== 'completed') {
            $event->update(['status' => 'completed']);
        }

        // --- Lead Score extension seam (Phase 1: intentionally inert) --------------------------------
        // Phase 3 wires post-call scoring HERE: refresh the lead's score from the concluded call and
        // populate `crm_calls.signals`. Kept as a documented seam so the completion pipeline needs no
        // restructuring when it lands — no scoring is computed in Phase 1 (locked decision #7).
        // --------------------------------------------------------------------------------------------

        $this->logActivity(
            $call->lead()->firstOrFail(),
            $agent,
            'call.completed',
            "{$agent->name} logged a {$outcome->label()} call with {$this->leadName($call->lead)}",
            [
                'call_id' => $call->id,
                'planner_event_id' => $call->planner_event_id,
                'outcome' => $outcome->value,
                'duration_seconds' => $call->duration_seconds,
            ],
        );

        return $call;
    }

    /** Only an in-progress call may be concluded — completing/discarding twice is a domain error. */
    private function assertActive(Call $call): void
    {
        if ($call->status !== CallStatus::IN_PROGRESS) {
            throw ValidationException::withMessages([
                'call' => 'This call session has already been concluded.',
            ]);
        }
    }

    /**
     * Server-computed call length in whole seconds. Derived from timestamps (never client-supplied) and
     * clamped at zero so clock skew can never yield a negative duration.
     */
    private function durationSeconds(?Carbon $startedAt, Carbon $endedAt): int
    {
        if ($startedAt === null) {
            return 0;
        }

        return max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
    }

    /**
     * Append a call Activity to the lead's timeline (subject = lead), mirroring OutreachEmailService.
     *
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Lead $lead, User $agent, string $event, string $description, array $properties): void
    {
        $lead->activities()->create([
            'causer_id' => $agent->id,
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
        ]);
    }

    /** Human label for a lead in activity descriptions / event titles (business → contact → id). */
    private function leadName(Lead $lead): string
    {
        return $lead->business_name ?: ($lead->contact_name ?: "Lead #{$lead->id}");
    }
}
