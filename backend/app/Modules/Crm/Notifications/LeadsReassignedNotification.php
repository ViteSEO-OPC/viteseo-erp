<?php

namespace App\Modules\Crm\Notifications;

use App\Models\User;
use App\Modules\Crm\Enums\NotificationType;
use Illuminate\Notifications\Notification;

/**
 * ONE bell for a whole resignation handover.
 *
 * The single-lead {@see LeadAssignedNotification} is deliberately not reused per lead here: thirteen
 * notifications for one Admin click is noise that buries the thing it is announcing. The per-lead
 * `lead.assigned` Activity records are still written for every lead — the audit trail stays complete
 * and granular; only the announcement is collapsed.
 */
class LeadsReassignedNotification extends Notification
{
    public function __construct(
        private readonly User $from,
        private readonly User $actor,
        private readonly int $leadCount,
        private readonly int $plannerCount,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * DATA ONLY — no rendered title, message or link.
     *
     * {@see \App\Modules\Crm\Resources\NotificationResource} reads all three from the type's
     * presenter, never from the payload, so `title`/`body`/`url` keys written here would simply be
     * ignored and the row would render as a humanised type string with no message and no link.
     *
     * `type` MUST be the snake_case catalog value. A dotted spelling does not match any
     * {@see NotificationType} case, `tryFrom()` returns null, and the row silently degrades to the
     * legacy fallback — the exact failure {@see NotificationType}'s docblock records from an earlier
     * attempt at this.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::LEADS_REASSIGNED->value,
            'reassigned_by_name' => $this->actor->name,
            'from_user_id' => $this->from->id,
            'from_user_name' => $this->from->name,
            'lead_count' => $this->leadCount,
            'planner_count' => $this->plannerCount,
        ];
    }
}
