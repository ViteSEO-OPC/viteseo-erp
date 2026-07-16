<?php

namespace App\Modules\Crm\Notifications;

use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * RC3 Phase 3 — reminder for an upcoming Planner event, delivered to the event's assigned BD via the
 * RC2 Database Notification foundation (same `notifications` table + Notifiable pattern as
 * InboundReplyNotification). Database channel for RC3; email/sms/etc. are future channels handled by the
 * dispatcher, not by adding channels here.
 */
class PlannerReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly PlannerEvent $event) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'planner_reminder',
            'planner_event_id' => $this->event->id,
            'title' => $this->event->title,
            'event_type' => $this->event->event_type,
            'starts_at' => $this->event->starts_at?->toIso8601String(),
            'lead_id' => $this->event->lead_id,
        ];
    }
}
