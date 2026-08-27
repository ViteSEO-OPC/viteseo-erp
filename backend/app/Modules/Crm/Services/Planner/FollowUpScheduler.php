<?php

namespace App\Modules\Crm\Services\Planner;

use App\Models\User;
use App\Modules\Crm\Controllers\EmailController;
use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Controllers\LeadQualificationController;
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
     * The CALLER decides that the lead is scheduled — this method does not re-test `cold_calling`, and
     * must not start to: it is called directly with leads in other states to exercise the dedupe rule
     * in isolation. Ownership-acquisition callers go through
     * {@see materializeOnOwnershipAcquired()} instead, which owns that condition.
     *
     * Idempotent by design: it does nothing when the lead already has open work that would make this
     * a duplicate, so repeated saves — or a scheduled → not-called → scheduled toggle — never stack
     * duplicate follow-ups in the inbox. Since the BD's channel is stored structurally, a call the BD
     * has already PLANNED counts as that work; see {@see coldCallFollowUpIsRedundant()} for the exact
     * (deliberately narrow) rule. Returns the created event, or null when none was created.
     * (The explicit Quick Action posts its own event and never reaches this method — an explicit
     * "schedule a follow-up" click is never silently skipped.)
     *
     * AN UNOWNED LEAD PRODUCES NO FOLLOW-UP. {@see create()} takes the event's owner from the lead, so
     * an unassigned lead would mint an ownerless event — work nobody is accountable for, and which no
     * BD can even see (PlannerEvent::scopeVisibleTo matches on `assigned_to`). Worse, it would be
     * permanent: PlannerOwnershipTransfer declines to move an event whose lead had no previous owner,
     * and the backfill command only considers events that already have one. Three mechanisms each
     * correctly refuse to touch such a row, so the only safe place to act is here — by never creating
     * it. Nothing is skipped for a lead that HAS an owner.
     */
    public function scheduleForColdCall(Lead $lead): ?PlannerEvent
    {
        if ($lead->assigned_to === null) {
            return null;
        }

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
     * Reconcile a lead's cold-call state at the moment it ACQUIRES its first owner.
     *
     * `cold_calling = scheduled` is a STATE on the lead, but LeadObserver only reacts to the EDGE
     * (`created`, or `wasChanged('cold_calling')`). A lead that was unowned at that moment produces no
     * follow-up — {@see scheduleForColdCall()} refuses to mint ownerless work — and nothing looks at
     * that state again: both acquisition paths save in ways that never re-run the observer's check.
     * Gaining an owner is when the work becomes real, so it is reconciled here.
     *
     * THE PREVIOUS-OWNER GATE IS THE POINT, and it is why this is a separate method rather than a
     * condition inside `scheduleForColdCall()`. Only a FIRST acquisition may create; a reassignment
     * transfers work and must never invent it. Leaving that to the dedupe would not be equivalent —
     * dedupe only sees OPEN work, so a lead whose cold-call follow-up had already been COMPLETED would
     * be handed a fresh one on every hop between BDs.
     *
     * Shared verbatim by {@see LeadController::assign()} (Admin assigns) and
     * {@see LeadQualificationController} (a BD claims a prospect), so
     * the same lead cannot end up with different Planner work depending on which path gave it an owner.
     *
     * @param  int|null  $previousOwnerId  The lead's owner BEFORE the write, captured by the caller.
     */
    public function materializeOnOwnershipAcquired(Lead $lead, ?int $previousOwnerId): ?PlannerEvent
    {
        if ($previousOwnerId !== null) {
            return null;
        }

        if ($lead->cold_calling !== 'scheduled') {
            return null;
        }

        return $this->scheduleForColdCall($lead);
    }

    /**
     * BR-NQ-01 — the first-touch Todo a BD owes a prospect they just qualified out of Nearby Clients.
     *
     * THIS IS THE ONE PLACE THE SYSTEM ORIGINATES WORK. Every other creator in this class reconciles
     * intent the BD already expressed (`cold_calling = 'scheduled'`); this one is triggered by the
     * qualification itself. That is a deliberate, PO-approved departure from the rule stated in
     * {@see materializeOnOwnershipAcquired()} — "a reassignment transfers work and must never invent
     * it" — and it is why the two live in separate methods rather than one. Qualification is a
     * lifecycle CROSSING: it is irreversible, it removes the business from a shared pool, and it
     * stamps ownership, so the obligation to make first contact exists from that instant whether or
     * not a row records it. Assignment and reassignment carry no such meaning and keep the old rule.
     *
     * Deliberately NOT reached from `LeadController::assign()`. The assign/qualify parity that
     * {@see materializeOnOwnershipAcquired()} enforces is broken here ON PURPOSE: the trigger is the
     * `qualified_at` transition, not the acquisition of an owner.
     *
     * BD-ONLY, and this changes no authorization. `LeadPolicy::qualify()` is permission-only, so a
     * `crm_admin` (or `super_admin` via Gate::before) can technically qualify through the API — that
     * existing behaviour is preserved untouched. But the Planner is a BD workspace (`/zerem/planner`
     * is `allowedRoles: ["crm_bd"]`, excluding even Super Admin), so minting for a non-BD would create
     * work nobody can open. The guard lives here, in new code, rather than in the policy.
     *
     * CREATES NO EMAIL, and cannot. This mints a Planner row and nothing else: `SendScheduledEmailsJob`
     * only ever selects EXISTING `crm_emails` rows (`direction=outbound`, `status=scheduled`), and the
     * sole creator of those is a BD posting `scheduled_at` in the composer
     * ({@see EmailController}). `planner_event_id` filters emails that already exist; it never
     * creates one.
     *
     * Idempotent twice over: qualification itself is 409-guarded under `lockForUpdate`, so the
     * transition cannot repeat; and the existing cold-call dedupe is reused unchanged below, so a
     * prospect that already carries open follow-up work gets nothing new.
     *
     * @param  User  $actor  The user performing the qualification — the Todo's owner.
     */
    public function scheduleFirstTouch(Lead $lead, User $actor): ?PlannerEvent
    {
        if (! $actor->hasRole('crm_bd')) {
            return null;
        }

        if ($this->coldCallFollowUpIsRedundant($lead)) {
            return null;
        }

        return $this->create($lead, [
            // Undated backlog, exactly like scheduleForColdCall(): qualification carries no deadline,
            // and inventing one would both fabricate urgency and pull the row into the "Follow-ups
            // Due" card, whose query requires `starts_at IS NOT NULL`.
            'status' => 'todo',
            'starts_at' => null,
            // Already in the accepted vocabulary (StorePlannerEventRequest) and already used by the
            // Nearby "Plan Visit" action, so the origin is legible without a new enum value.
            'source' => 'nearby',
            // The qualifier owns the work. Qualification has already set the lead's `assigned_to` to
            // the same id; passing it explicitly keeps this independent of that ordering.
            'assigned_to' => $actor->id,
            'created_by' => $actor->id,
            'description' => 'First touch — qualified from Nearby Clients.',
        ]);
    }

    /**
     * BR-LC-01 (§4b) — the first-touch Todo a BD owes a lead they just created by hand.
     *
     * THE SECOND PLACE THE SYSTEM ORIGINATES WORK, after {@see scheduleFirstTouch()}. Every other
     * creator in this class reconciles an intent the BD already recorded as `cold_calling =
     * 'scheduled'`. Manual creation records no such intent and structurally cannot: the Create Lead
     * Wizard has never carried a Cold Calling field, so a wizard-created lead is born at the column
     * default `not-called` and reaches no trigger. That left it owned, scored and active yet absent
     * from Outreach — the same defect scheduleFirstTouch() exists to prevent, reached by a different
     * route. Creation is the same kind of lifecycle crossing qualification is: it stamps ownership,
     * so the obligation to make first contact is real from that instant.
     *
     * `cold_calling = 'scheduled'` WAS REJECTED AS THE MECHANISM, deliberately. Setting it would have
     * been a one-line frontend change and StoreLeadRequest accepts the field, but that column is not
     * an inert flag: it awards the Call Scheduled ENGAGEMENT signal in LeadScoreCalculator, and the
     * Management dashboard counts it as a Consultation ("a booked slot"). Buying the Todo that way
     * would inflate every new lead's score and report a call nobody scheduled. This mints the work
     * directly and MOVES NO LEAD COLUMN.
     *
     * SINGLE CREATION ONLY. Called from {@see LeadCreator::create()} — the manual POST /leads path —
     * not from LeadObserver. Both creation paths share `Lead::create()` and therefore the observer, so
     * placing it there would have applied it to every imported row too; bulk import is deliberately
     * left unchanged pending a separate decision.
     *
     * Reuses every existing safeguard unchanged: the ownership rule below, the prospect exclusion, and
     * `coldCallFollowUpIsRedundant()`. A lead created already `cold_calling = 'scheduled'` therefore
     * receives EXACTLY ONE event — the observer's cold-call follow-up fires first and this declines on
     * dedupe.
     */
    public function scheduleOnLeadCreation(Lead $lead): ?PlannerEvent
    {
        // Same rule and same reason as scheduleForColdCall(): ownerless work is invisible to every BD
        // and impossible to reclaim.
        if ($lead->assigned_to === null) {
            return null;
        }

        // A discovered prospect's first touch belongs to scheduleFirstTouch(), minted on qualification.
        // Without this the two origination paths would both fire for an ingested prospect.
        if ($lead->isProspect()) {
            return null;
        }

        if ($this->coldCallFollowUpIsRedundant($lead)) {
            return null;
        }

        return $this->create($lead, [
            // Undated backlog, exactly like scheduleForColdCall() and scheduleFirstTouch():
            // creation carries no deadline, and inventing one would fabricate urgency and pull the
            // row into the "Follow-ups Due" card, whose query requires `starts_at IS NOT NULL`.
            'status' => 'todo',
            'starts_at' => null,
            'source' => 'outreach',
            'description' => 'First touch — new lead.',
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
