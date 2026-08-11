<?php

namespace App\Modules\Crm\Services\Planner;

use App\Modules\Crm\Controllers\PlannerEventController;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Outreach\InboundReplyProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The single business component that creates events of the GENERIC `follow-up` type. Every such row
 * — from Cold Calling → Scheduled, the generic Planner event endpoint, or a future integration — is
 * born through {@see create()}. This is enforced, not just conventional:
 * {@see PlannerEventController::store()} delegates here whenever `event_type === 'follow-up'`, so
 * there is no second creation path for that type.
 *
 * SCOPE NOTE (Model B, Phase 2). This class is no longer the creator of ALL follow-up work. The Lead
 * Details "Schedule follow-up" Quick Action now persists the BD's chosen channel structurally
 * (`email` / `call` / `meeting` / `task`) and is therefore created by the controller's generic
 * branch, not here — it passes every field this class would default. `follow-up` remains legal and
 * unmigrated for legacy rows and for the auto-trigger below, which has no channel to record.
 *
 * The business CATEGORY "follow-up work" is {@see PlannerEvent::FOLLOW_UP_WORK_TYPES} and is wider
 * than this class's output; consumers must ask that question via `followUpWork()`, never by matching
 * the bare string here.
 *
 * `create()` is the sole `PlannerEvent::create` for `follow-up`; it forces `event_type='follow-up'`
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
     * Idempotent by design: it does nothing when the lead already has open work that would make this
     * a duplicate, so repeated saves — or a scheduled → not-called → scheduled toggle — never stack
     * duplicate follow-ups in the inbox. Since the BD's channel is stored structurally, a call the BD
     * has already PLANNED counts as that work; see {@see coldCallFollowUpIsRedundant()} for the exact
     * (deliberately narrow) rule. Returns the created event, or null when none was created.
     * (The explicit Quick Action posts its own event and never reaches this method — an explicit
     * "schedule a follow-up" click is never silently skipped.)
     */
    public function scheduleForColdCall(Lead $lead): ?PlannerEvent
    {
        if ($this->coldCallFollowUpIsRedundant($lead)) {
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
     * Whether the lead already has open work that makes the auto cold-call follow-up a duplicate
     * (the dedupe guard). Two shapes qualify, and NOTHING else:
     *
     *   1. an open generic `follow-up` — the original rule, unchanged;
     *   2. a PLANNED call: `call` + status `scheduled` + a non-null `starts_at`.
     *
     * DELIBERATELY NARROW — it must NOT adopt the shared follow-up-work category. This guard runs
     * from LeadObserver the moment a cold call is logged, and a cold call leaves an OPEN `call` event
     * behind (CallSessionService completes it only on a CONNECTED outcome). Matching `call` broadly
     * would therefore see that event and suppress the very follow-up this exists to create.
     *
     * Shape 2 is safe precisely because it is disjoint from every state the Calling lifecycle can
     * produce, which is a property of that code and not a convention:
     *   - `startAdHoc()` mints its event at `in_progress` with a NULL `starts_at`;
     *   - `open()` moves any idle event it touches to `in_progress`;
     *   - `finalize()` either completes the event (CONNECTED) or leaves it `in_progress` for a retry.
     * No path produces a `scheduled` call, and no path back-fills `starts_at`. A call that is both
     * `scheduled` and dated can therefore only have been PLANNED by a BD.
     *
     * THE BUSINESS RULE (PO-approved): any BD-planned scheduled Call with a non-null `starts_at`
     * counts as existing follow-up work, REGARDLESS of whether it was created from Lead Details or
     * the Planner event dialog. The two surfaces express the same intent, so the guard deliberately
     * does not tell them apart — and a `source`-style discriminator was considered and rejected.
     *
     * Both conditions are load-bearing. `scheduled` alone would match a Board drag of an undated
     * backlog call; requiring the date keeps this to genuinely planned work.
     *
     * Email / Meeting / Task are intentionally absent: they are different work from a call, so they
     * do not make a cold-call follow-up redundant.
     */
    private function coldCallFollowUpIsRedundant(Lead $lead): bool
    {
        return PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->where('status', '!=', 'completed')
            ->where(function (Builder $query): void {
                $query
                    ->where('event_type', 'follow-up')
                    ->orWhere(fn (Builder $planned): Builder => $planned
                        ->where('event_type', 'call')
                        ->where('status', 'scheduled')
                        ->whereNotNull('starts_at'));
            })
            ->exists();
    }
}
