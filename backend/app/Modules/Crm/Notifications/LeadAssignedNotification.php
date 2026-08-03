<?php

namespace App\Modules\Crm\Notifications;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A lead was assigned to a BD — delivered to the BD who now owns it.
 *
 * NOT the same thing as the LeadAssigned broadcast event, and both exist on purpose.
 * That event is a transient toast: it tells a BD who happens to be looking right now,
 * and is gone on refresh. This is the durable record — it survives a reload, a logout,
 * and a Reverb outage, and it is what puts the assignment in the bell and in History.
 *
 * Database channel only. The push to the bell is not this class's business: persisting
 * fires NotificationSent, which BroadcastCrmNotification turns into the realtime
 * update, exactly as it does for every other CRM notification. There is no second
 * broadcast path here.
 *
 * Built from primitives rather than models. The producer already holds the loaded
 * Lead and actor, and a notification's payload is a SNAPSHOT: it must keep saying
 * "Rea assigned you Acme" even after the lead is renamed or reassigned, so re-reading
 * either record at render time would be wrong, not merely slower.
 */
class LeadAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Lead $lead,
        private readonly User $assignedBy,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_assigned',
            // `lead_id` is the conventional key across CRM payloads — LinksToLead and
            // the legacy fallback in NotificationResource both look for exactly it.
            'lead_id' => $this->lead->id,
            'business_name' => $this->lead->business_name,
            'assigned_by_id' => $this->assignedBy->id,
            'assigned_by_name' => $this->assignedBy->name,
        ];
    }
}
