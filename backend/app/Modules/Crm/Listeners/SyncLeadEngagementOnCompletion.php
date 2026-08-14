<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Outreach\InboundReplyProcessor;
use App\Modules\Crm\Services\Scoring\LeadScorer;

/**
 * Planner → Lead Engagement Status synchronization (PO-approved 2026-08-14).
 *
 * Completing a CALL or EMAIL planner activity advances the lead's Engagement Status, so the Leads
 * table stops showing "Not Called" for a lead the BD has demonstrably worked. Before this, the two
 * engagement columns were written only by hand (the Edit drawer / import) and by
 * {@see InboundReplyProcessor} — which this listener deliberately
 * mirrors, line for line, as the established shape of an automated engagement write.
 *
 * WHAT IT DOES NOT TOUCH, and why each is deliberate:
 *
 *  - **`leads.status` (the SALES lifecycle) — never.** Frozen, PO-approved 2026-07-15: automated
 *    flows may advance the communication lifecycle but must never auto-mutate the sales one, which
 *    stays a manual BD decision. Encoding activity states as lead statuses is the defect PDR-002 §2.1
 *    records this codebase removing twice already.
 *  - **`last_activity` — never.** InboundReplyProcessor writes it; this does not, by explicit PO
 *    decision, keeping the blast radius to the single engagement column. It is a recency scoring
 *    input, so writing it would move scores beyond the engagement signal itself.
 *  - **`meeting`, `task`, `follow-up`, `consultation` — no engagement write.** Only `call` and `email`
 *    name a channel that an engagement column models. `task` and `consultation` never even reach here
 *    (they are outside {@see PlannerEvent::FOLLOW_UP_WORK_TYPES}, so
 *    FollowUpCompleted is not raised for them); `follow-up` and `meeting` reach here and are ignored.
 *    A consultation carries its own outcome axis and must not be flattened into a lead column.
 *
 * PLANNED != PERFORMED is inherited from the trigger, not re-implemented: FollowUpCompleted is raised
 * by PlannerEventObserver ONLY on a transition INTO `completed`, so scheduling, rescheduling, or
 * merely editing an event can never advance engagement. That also means the Calling Workspace is
 * covered for free — CallSessionService completes the planner event on a CONNECTED disposition, which
 * routes through the same observer, so a real dial advances `cold_calling` without a second code path.
 */
class SyncLeadEngagementOnCompletion
{
    /**
     * Engagement ranks. A write is applied only when it moves the lead FORWARD, so a completion can
     * never destroy a more advanced state that some other signal established:
     *
     *  - `email_outreach`: `opened` and `replied` are facts about the RECIPIENT's behaviour (the
     *    latter written by InboundReplyProcessor from a real inbound message). Marking an email task
     *    done says only that we sent something, so it must never pull those back to `sent`.
     *  - `cold_calling`: `scheduled` is intent, `called` is performed — advancing is correct and
     *    PO-approved. `called` is terminal here; nothing in this listener regresses it.
     *
     * Manual BD edits stay unconstrained on purpose (LeadController::update validates enum membership
     * and nothing else). Monotonicity is a property of THIS automated write, not of the column: a BD
     * who corrects a value by hand keeps it until another event transitions into `completed`.
     */
    private const RANK = [
        'email_outreach' => ['none' => 0, 'sent' => 1, 'opened' => 2, 'replied' => 3],
        'cold_calling' => ['not-called' => 0, 'scheduled' => 1, 'called' => 2],
    ];

    /** Planner channel → the engagement column it advances, and the value completing it proves. */
    private const CHANNEL = [
        'call' => ['column' => 'cold_calling', 'value' => 'called'],
        'email' => ['column' => 'email_outreach', 'value' => 'sent'],
    ];

    public function __construct(private readonly LeadScorer $scorer) {}

    public function handle(FollowUpCompleted $event): void
    {
        $channel = self::CHANNEL[$event->event->event_type] ?? null;

        if ($channel === null) {
            return; // follow-up / meeting — real follow-up work, but neither names an engagement channel.
        }

        $lead = $event->event->lead;

        if (! $lead instanceof Lead) {
            return; // lead-less planner work — nothing to synchronize (mirrors EscalateUnansweredFollowUp).
        }

        ['column' => $column, 'value' => $value] = $channel;

        if (! $this->advances($column, $lead->{$column}, $value)) {
            return; // already at or beyond this state — no write, no score churn, no Activity noise.
        }

        // `updateQuietly` for the same two reasons InboundReplyProcessor uses it: the lead's timeline
        // already carries the `planner-event.completed` Activity, so a generic `lead.updated` on top
        // would be duplicate noise; and staying out of LeadObserver means this can never re-enter
        // FollowUpScheduler (which reacts to `cold_calling` changes by CREATING planner events).
        $lead->updateQuietly([$column => $value]);

        // LeadScorer stays the sole writer of `leads.score`; the formula is untouched. Active CRM leads
        // only — a discovered prospect carries no score until a BD qualifies it in. Called explicitly
        // because updateQuietly deliberately suppressed the observer that normally does this.
        if (! $lead->isProspect()) {
            $this->scorer->refresh($lead);
        }
    }

    /** True when `$to` outranks the lead's current value. Unknown/legacy values are treated as lowest. */
    private function advances(string $column, ?string $from, string $to): bool
    {
        $ranks = self::RANK[$column];

        return ($ranks[$to] ?? 0) > ($ranks[$from] ?? -1);
    }
}
