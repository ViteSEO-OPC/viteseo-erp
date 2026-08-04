<?php

namespace App\Modules\Crm\Services\Planner;

use App\Modules\Crm\Controllers\PlannerEventController;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Outreach\InboundReplyProcessor;
use Illuminate\Support\Facades\Auth;

/**
 * The single business component that creates Planner follow-up events. EVERY follow-up — whether it
 * originates from Cold Calling → Scheduled, the Lead Details "Schedule follow-up" Quick Action, the
 * generic Planner event endpoint, or a future integration — is born through {@see create()}. This
 * is enforced, not just conventional: {@see PlannerEventController::store()}
 * delegates here whenever `event_type === 'follow-up'`, so there is no second creation path.
 *
 * `create()` is the sole `PlannerEvent::create` for follow-ups; it forces `event_type='follow-up'`
 * and fills the follow-up defaults (todo / outreach / "{business} — follow-up" title / owner) that a
 * caller omits, while honoring anything the caller does pass (date, priority, note, provenance).
 * `scheduleForColdCall()` is the idempotent wrapper the LeadObserver uses.
 */
class FollowUpScheduler
{
    /**
     * Create a follow-up PlannerEvent for a lead. The one authoritative creator — reuses the
     * existing PlannerEvent model + observer (which logs the `planner-event.created` Activity and
     * lets the BD KPI engine react). `event_type` is always forced to 'follow-up'.
     *
     * @param  array<string, mixed>  $attributes  Optional overrides (title, status, starts_at,
     *                                            ends_at, priority, source, reminder_minutes,
     *                                            description, assigned_to, created_by, lead_id).
     */
    public function create(?Lead $lead, array $attributes = []): PlannerEvent
    {
        $leadId = $lead !== null ? $lead->id : ($attributes['lead_id'] ?? null);
        $lead ??= $leadId !== null ? Lead::find($leadId) : null;

        $name = $lead?->business_name ?: ($lead?->contact_name ?: ($leadId !== null ? "Lead #{$leadId}" : 'Follow-up'));

        return PlannerEvent::create([
            ...$attributes,
            // The invariant — every follow-up, whatever the surface, is this type.
            'event_type' => 'follow-up',
            'lead_id' => $leadId,
            'title' => $attributes['title'] ?? "{$name} — follow-up",
            'status' => $attributes['status'] ?? 'todo',
            'source' => $attributes['source'] ?? 'outreach',
            'assigned_to' => $attributes['assigned_to'] ?? $lead?->assigned_to,
            'created_by' => $attributes['created_by'] ?? (Auth::id() ?? $lead?->assigned_to),
        ]);
    }

    /**
     * Ensure a pending follow-up exists for a lead whose Cold Calling was just set to 'scheduled'.
     *
     * Idempotent by design: it does nothing if the lead already has an open (non-completed)
     * follow-up, so repeated saves — or a scheduled → not-called → scheduled toggle — never stack
     * duplicate follow-ups in the inbox. Returns the created event, or null when none was created.
     * (The explicit Quick Action uses {@see create()} directly, which force-creates — an explicit
     * "schedule a follow-up" click should not be silently skipped.)
     */
    public function scheduleForColdCall(Lead $lead): ?PlannerEvent
    {
        if ($this->hasOpenFollowUp($lead)) {
            return null;
        }

        return $this->create($lead, [
            'status' => 'todo',        // undated backlog / To-Do — the lead's status carries no date
            'starts_at' => null,
            'source' => 'outreach',
            'description' => 'Auto-created when Cold Calling was set to Scheduled.',
        ]);
    }

    /**
     * Complete every open (non-completed) follow-up for a lead. The single place follow-up completion
     * is triggered by an automated flow — the Inbound Email reply fan-out calls this when a prospect
     * replies (see {@see InboundReplyProcessor}). Planner remains the OWNER of
     * completion: each transition runs through the normal PlannerEvent save, so PlannerEventObserver
     * logs the `planner-event.completed` Activity — no bypass, no duplicated status logic.
     *
     * Idempotent: a lead with no open follow-up completes nothing and returns 0.
     *
     * @return int number of follow-ups transitioned to completed
     */
    public function completeOpenFor(Lead $lead): int
    {
        $open = PlannerEvent::query()
            ->where('lead_id', $lead->id)
            // Whole follow-up-work category: once the lead has replied / converted, an open Email or
            // Call task is just as stale as a generic follow-up.
            ->followUpWork()
            ->where('status', '!=', 'completed')
            ->get();

        foreach ($open as $event) {
            $event->update(['status' => 'completed']);
        }

        return $open->count();
    }

    /**
     * Whether the lead already has a follow-up that has not been completed (the dedupe guard).
     *
     * DELIBERATELY NARROW — matches `follow-up` exactly and must NOT adopt the shared follow-up-work
     * category. This guard runs from LeadObserver the moment a cold call is logged, and a cold call
     * leaves an OPEN `call` event behind (CallSessionService completes it only on a CONNECTED
     * outcome). Widening the guard would therefore see that event and suppress the very follow-up it
     * exists to create.
     */
    private function hasOpenFollowUp(Lead $lead): bool
    {
        return PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->where('event_type', 'follow-up')
            ->where('status', '!=', 'completed')
            ->exists();
    }
}
