<?php

namespace App\Modules\Crm\Notifications;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * First production notification (RC2). Delivered to a lead's assigned BD when a prospect's inbound
 * reply is matched to that lead. Database channel only — the notification is written inside the
 * already-queued inbound job, so the in-app notification center reads it from the `notifications`
 * table. Additional channels (email/SMS/Slack) are Post-RC2 (see KB: Notification-Engine).
 */
class InboundReplyNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Lead $lead,
        private readonly Email $email,
    ) {}

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
            'type' => 'inbound_reply',
            'lead_id' => $this->lead->id,
            'lead_name' => $this->lead->business_name,
            'email_id' => $this->email->id,
            'from' => $this->email->from_email,
            'from_name' => $this->email->from_name,
            'subject' => $this->email->subject,
            'preview' => Str::limit((string) $this->email->body, 140),
            'received_at' => $this->email->received_at?->toIso8601String(),
        ];
    }
}
