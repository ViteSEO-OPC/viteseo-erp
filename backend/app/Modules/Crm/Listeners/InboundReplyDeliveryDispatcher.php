<?php

namespace App\Modules\Crm\Listeners;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Events\InboundReplyReceived;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NotificationDelivery;
use App\Modules\Crm\Notifications\InboundReplyMailNotification;
use Illuminate\Support\Facades\Log;

/**
 * Multi-Channel Internal Notifications — the DELIVERY subsystem for inbound-reply alerts, decoupled from
 * the inbound business layer exactly as {@see ReminderDeliveryDispatcher} is decoupled from Planner.
 *
 * Subscribes to {@see InboundReplyReceived} and delivers the alert on each ENABLED channel, recording an
 * append-only `crm_notification_deliveries` row per (email, channel). Ships the `mail` channel; slack /
 * teams / sms / whatsapp plug in here later — InboundEmailService and InboundReplyProcessor never change.
 *
 * The always-on in-app database notification is NOT delivered here: it stays in the untouched RC2
 * fan-out, so this subsystem can be switched on or off with no effect on in-app behaviour.
 *
 * Idempotent + race-safe: firstOrCreate on the unique (email_id, channel) claims the delivery, and only
 * the winner notifies — so a redelivered Pub/Sub push can never double-email a CRM user.
 *
 * Fail-soft by contract: this runs inside the queued inbound ingest job, so a channel that throws is
 * logged and swallowed. An internal alert must never fail an ingest, stall the mailbox cursor, or lose a
 * customer reply.
 */
class InboundReplyDeliveryDispatcher
{
    private const CHANNEL_MAIL = 'mail';

    public function handle(InboundReplyReceived $event): void
    {
        $recipient = $event->lead->assignedTo;

        // No assignee → nobody to alert. (The RC2 in-app notification makes the same call.)
        if ($recipient === null) {
            return;
        }

        if ($this->mailEnabled()) {
            $this->deliverMail($event->lead, $event->email, $recipient);
        }
    }

    /** Off by default — internal staff mail is opt-in per environment. */
    private function mailEnabled(): bool
    {
        return (bool) config('outreach.internal_notifications.mail', false);
    }

    /**
     * Deliver the internal email alert to the assigned user's own company mailbox, once.
     */
    private function deliverMail(Lead $lead, Email $email, User $recipient): void
    {
        // A suspended account must not receive lead data — the person may have left the company.
        // Read from `crm_user_access`: losing Zerem must not depend on the shared `users.status`
        // column, which other products also write.
        if (app(CrmAccessService::class)->isSuspended($recipient)) {
            return;
        }

        $address = (string) $recipient->email;
        if ($address === '') {
            return;
        }

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['email_id' => $email->id, 'channel' => self::CHANNEL_MAIL],
            [
                'delivered_at' => now(),
                'meta' => ['notifiable_id' => $recipient->id, 'recipient' => $address],
            ],
        );

        // Another fan-out already delivered this channel → do nothing.
        if (! $delivery->wasRecentlyCreated) {
            return;
        }

        try {
            $recipient->notify(new InboundReplyMailNotification($lead, $email));
        } catch (\Throwable $e) {
            // Release the claim so a later reconcile pass can retry, then swallow: ingestion must not
            // fail because a staff alert could not be queued.
            $delivery->delete();

            Log::error('outreach.inbound.internal_notification_failed', [
                'email_id' => $email->id,
                'channel' => self::CHANNEL_MAIL,
                'notifiable_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('outreach.inbound.internal_notification_delivered', [
            'email_id' => $email->id,
            'lead_id' => $email->lead_id,
            'channel' => self::CHANNEL_MAIL,
            'notifiable_id' => $recipient->id,
        ]);
    }
}
