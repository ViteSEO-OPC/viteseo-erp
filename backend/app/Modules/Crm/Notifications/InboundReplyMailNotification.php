<?php

namespace App\Modules\Crm\Notifications;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Multi-Channel Internal Notifications — the INTERNAL email alert telling an assigned CRM user that a
 * prospect replied. Sent to the user's own company mailbox (`users.email`, e.g. bruce@viteseo.ph).
 *
 * This is a STAFF notification, never customer communication: the customer conversation stays entirely
 * on the shared outreach mailbox (crm@…). Consequently this notification writes NO `crm_emails` row —
 * it must never appear in a lead's email thread, be threaded against by inbound matching, or be counted
 * as outreach.
 *
 * ShouldQueue is load-bearing (not a preference): {@see InboundReplyNotification} is delivered
 * synchronously inside the already-queued inbound ingest job, so putting a live SMTP call on that path
 * would let a slow or failing mail host delay inbound ingestion — and because ingest() returns early on
 * redelivery, a transient SMTP error would drop the alert permanently. Queuing isolates the transport:
 * SMTP failures retry on the queue and land in `failed_jobs`, while ingestion is unaffected.
 */
class InboundReplyMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Lead $lead,
        private readonly Email $email,
    ) {}

    /**
     * Mail only. The in-app alert is delivered separately by the untouched
     * {@see InboundReplyNotification} (database channel), so this class can never double-post in-app.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = (string) $this->lead->business_name;
        $sender = $this->email->from_name ?? $this->email->from_email ?? 'the prospect';
        $preview = Str::limit(trim((string) $this->email->body), 300);

        $mail = (new MailMessage)
            // Subject is prefixed so internal alerts are trivially filterable in a staff inbox and are
            // never mistaken for the customer-facing thread.
            ->subject("[Zerem CRM] New reply from {$business}")
            ->greeting("Hi {$notifiable->name},")
            ->line("**{$sender}** replied to your outreach for **{$business}**.")
            ->line('Subject: '.(string) $this->email->subject);

        if ($preview !== '') {
            $mail->line('---')->line($preview)->line('---');
        }

        $url = $this->leadUrl();
        if ($url !== null) {
            $mail->action('Open the lead in Zerem CRM', $url);
        }

        // The prospect must be answered from the CRM (which sends via the shared mailbox), never by
        // replying to this internal alert — a reply here would reach crm@ and ingest as unmatched.
        return $mail
            ->line('Reply to the prospect from the CRM — do not reply to this internal notification.')
            ->withSymfonyMessage(function (MimeEmail $message): void {
                // Marks this as machine-generated so responders and filters do not treat it as a human
                // conversation, and so it is self-evidently automated in an audit.
                $headers = $message->getHeaders();
                $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                $headers->addTextHeader('X-Zerem-Notification', 'inbound-reply');
            });
    }

    /**
     * Deep link to the lead in the CRM SPA.
     *
     * Built from `outreach.frontend_url` — the SPA ORIGIN — not from APP_URL. Those are the same
     * host only when one origin serves both the API and the frontend; anywhere they are split (this
     * repo's local setup runs the API on :8001 and Next on :3000) APP_URL produced a link to the API
     * host, which 404s. The frontend origin is already configured for exactly this purpose, so the
     * button now resolves wherever the SPA actually lives.
     *
     * Origin from config, path in code — the same split the OAuth callback's return URL uses, so a
     * change to the SPA's routing is a code change next to the route it mirrors rather than an env
     * edit across every deployment. Returns null when no origin is configured, so a misconfigured
     * environment yields no button rather than a broken link.
     */
    private function leadUrl(): ?string
    {
        $base = rtrim((string) config('outreach.frontend_url', ''), '/');

        return $base === '' ? null : "{$base}/zerem/leads/{$this->lead->id}";
    }
}
