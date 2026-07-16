<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;

/**
 * RC3 Outreach Automation Pack — No-Response Escalation (workflow #4), server-side and authoritative
 * ("Laravel decides"). When a follow-up completes WITHOUT a reply, create a Planner escalation task for
 * the lead's assigned BD; the existing reminder pipeline (SendDueRemindersJob → PlannerReminderDue →
 * ReminderDeliveryDispatcher) delivers the "Notify BD" notification. No new scheduler (Planner owns the
 * timing), no new send path, and — critically — `leads.status` is never touched.
 *
 * Reply detection is robust to the inbound fan-out order: a reply is present if the lead is flagged
 * `email_outreach = replied` OR any inbound email exists (the inbound row is persisted before the
 * follow-up is completed, so a reply-driven completion is correctly NOT escalated). Idempotent: one open
 * system escalation task per lead.
 */
class EscalateUnansweredFollowUp
{
    public function handle(FollowUpCompleted $event): void
    {
        $lead = $event->event->lead;

        if (! $lead instanceof Lead) {
            return; // orphaned/lead-less follow-up — nothing to escalate.
        }

        if ($this->hasReplied($lead) || $this->hasOpenEscalation($lead)) {
            return; // replied → good outcome; already escalated → idempotent no-op.
        }

        $offset = (int) config('automation.escalation.start_offset_minutes', 1);

        // A distinct Planner TASK (not a follow-up), provenance `system`. Creating it logs a
        // `planner-event.created` Activity via PlannerEventObserver and, once due, is picked up by the
        // existing reminder pipeline to notify the assigned BD.
        PlannerEvent::create([
            'title' => "No response — follow up with {$lead->business_name}",
            'event_type' => 'task',
            'status' => 'todo',
            'priority' => 'high',
            'source' => 'system',
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $lead->assigned_to,
            'starts_at' => now()->addMinutes($offset),
            'reminder_minutes' => (int) config('automation.escalation.reminder_minutes', 1),
            'description' => 'Auto-created: a follow-up completed with no reply from the lead.',
        ]);
    }

    /** A reply exists if the comms status is `replied` OR any inbound email was recorded. */
    private function hasReplied(Lead $lead): bool
    {
        if ($lead->email_outreach === 'replied') {
            return true;
        }

        return Email::query()
            ->where('lead_id', $lead->id)
            ->where('direction', 'inbound')
            ->exists();
    }

    /** One open (non-completed) system escalation task per lead — the idempotency guard. */
    private function hasOpenEscalation(Lead $lead): bool
    {
        return PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->where('source', 'system')
            ->where('status', '!=', 'completed')
            ->exists();
    }
}
