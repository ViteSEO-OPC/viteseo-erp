<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Events\InboundReplyReceived;
use App\Modules\Crm\Listeners\InboundReplyDeliveryDispatcher;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Notifications\InboundReplyNotification;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Illuminate\Support\Facades\Log;

/**
 * The post-ingest fan-out for a matched, human inbound reply (RC2). Runs AFTER
 * {@see InboundEmailService::ingest()} has persisted the inbound Email and written the
 * `email.received` Activity — invoked once per newly-persisted, matched, non-automated reply
 * (idempotency is decided upstream by ingest, so Pub/Sub redelivery never re-fans-out).
 *
 * Reuse-first: every step routes through an EXISTING owner — Planner (`FollowUpScheduler`) completes
 * the follow-up, the KPI Engine (`LeadScorer`) refreshes the score with the frozen formula, and the
 * standard Laravel Notifiable delivers the BD notification. This service adds no business rules.
 *
 * Additional INTERNAL notification channels (mail today; slack/teams/sms later) are NOT delivered here:
 * this service emits {@see InboundReplyReceived} and the delivery subsystem
 * ({@see InboundReplyDeliveryDispatcher}) owns them, so a new channel never
 * edits this fan-out. Customer communication is unaffected — it stays on the shared outreach mailbox.
 *
 * Lifecycle boundary (PO-approved 2026-07-15): inbound email touches the COMMUNICATION lifecycle only
 * — `leads.email_outreach` (Email Outreach Status) and `leads.last_activity`. It NEVER writes
 * `leads.status` (the Lead Status / sales lifecycle), which stays a manual BD decision.
 */
class InboundReplyProcessor
{
    public function __construct(
        private readonly FollowUpScheduler $followUps,
        private readonly LeadScorer $scorer,
    ) {}

    public function process(Lead $lead, Email $email): void
    {
        // 1. Planner — complete the lead's open follow-up. Planner OWNS completion; we only trigger it,
        //    through its single lifecycle component (which logs `planner-event.completed` via the
        //    observer). Idempotent: no open follow-up → 0 completed, safe no-op.
        $completed = $this->followUps->completeOpenFor($lead);

        // 2. Communication lifecycle — advance Email Outreach Status + recency. NEVER `leads.status`.
        //    `updateQuietly` so the LeadObserver does not stack a generic `updated` Activity on top of
        //    the `email.received` already written by ingest(). Advance-only: never regress engagement.
        $updates = ['last_activity' => $email->received_at ?? now()];
        if ($lead->email_outreach !== 'replied') {
            $updates['email_outreach'] = 'replied';
        }
        $lead->updateQuietly($updates);

        // 3. Lead Score — refresh through the existing engine (formula UNCHANGED; `LeadScorer` stays the
        //    sole writer of `leads.score`). Active CRM leads only, mirroring LeadObserver: a discovered
        //    prospect carries no score until a BD qualifies it into the CRM.
        if (! $lead->isProspect()) {
            $this->scorer->refresh($lead);
        }

        // 4. Notify the assigned BD in-app (first production notification). No assignee → nothing to
        //    deliver. Always-on and unchanged: the in-app notification is the baseline channel.
        $lead->assignedTo?->notify(new InboundReplyNotification($lead, $email));

        // 5. Announce the reply so the DELIVERY subsystem can alert the assigned user on any additional
        //    ENABLED internal channel (mail today; slack/teams/sms later). Emitted last so a channel can
        //    never affect steps 1–4, and kept as an event so adding a channel touches no business logic.
        event(new InboundReplyReceived($lead, $email));

        Log::info('outreach.inbound.fanout', [
            'lead_id' => $lead->id,
            'email_id' => $email->id,
            'followups_completed' => $completed,
            'notified' => $lead->assigned_to !== null,
        ]);
    }
}
