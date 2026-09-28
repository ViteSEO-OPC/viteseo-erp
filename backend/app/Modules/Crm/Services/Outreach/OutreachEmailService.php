<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Events\OutboundEmailSent;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailEvent;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailDocument;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use App\Support\Mail\Module;
use App\Support\Mail\ModuleSender;
use App\Support\Mail\ModuleSenderResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Outbound outreach email use case. Depends ONLY on the EmailProvider contract, so
 * it never knows which transport (SMTP / Gmail / Graph / …) actually sends. On a
 * successful send it persists an Email history row and writes an `email.sent`
 * Activity through the existing centralized Activity log — the same mechanism the
 * LeadObserver uses, so it flows to GET /activities and the Dashboard automatically.
 * No separate activity/email-activity system is introduced.
 */
class OutreachEmailService
{
    public function __construct(
        private readonly EmailProvider $provider,
        private readonly ModuleSenderResolver $senders,
    ) {}

    /**
     * The CRM's outbound identity, from the shared registry (config/mail.php
     * `module_senders.crm`). Resolved per call rather than cached on the instance so a
     * config change takes effect without a rebind.
     *
     * Historically this was read straight from `outreach.from`; that key is deprecated
     * and OUTREACH_FROM_* now feeds the registry instead, so the address is unchanged.
     */
    private function sender(): ModuleSender
    {
        return $this->senders->for(Module::Crm);
    }

    /**
     * @param  array<string, mixed>|null  $bodyDoc  a VALIDATED email document for a formatted reply
     *                                              (see SendEmailRequest), or null for plain text.
     *                                              `$body` must already be its text projection.
     *
     * @throws ValidationException when no recipient can be resolved
     */
    public function sendToLead(Lead $lead, User $sender, string $subject, string $body, ?string $to = null, ?array $bodyDoc = null): Email
    {
        // Pre-flight recipient validation (422) BEFORE anything is persisted — a missing recipient is a
        // request error, not a delivery failure, so it must never create a history row.
        $toEmail = $this->resolveRecipient($lead, $to);

        // EMAIL-001: persist BEFORE transport. The row exists as `sending` the moment we commit to the
        // send, so a provider failure is recorded as `failed` history (with the reason) instead of
        // vanishing on a thrown exception. This stays fully synchronous — no queue, no async — and
        // reuses the SAME single outbound pipeline (sendExisting) drafts and scheduled sends funnel through.
        $from = $this->sender();

        $email = Email::create([
            'lead_id' => $lead->id,
            'user_id' => $sender->id,
            'direction' => 'outbound',
            'from_email' => $from->address,
            'from_name' => $from->name,
            'to_email' => $toEmail,
            'to_name' => $lead->contact_name,
            'subject' => $subject,
            'body' => $body,
            'body_doc' => $bodyDoc,
            'status' => 'sending',
            'attempts' => 1,
            // NOTE: queued_at is intentionally NOT set — it means "claimed by the background sender job"
            // (see the scheduling migration + ScheduledEmailService::claim). Send-Now is fully synchronous
            // and never enters the queue, so leaving it null keeps the queue semantics honest. `attempts`
            // IS a transport-attempt counter (1 = this single synchronous attempt), not a queue marker.
        ]);
        $this->recordEvent($email, 'sending');

        try {
            // Transport + success persistence (status=sent, sent_at, provider ids) + email.sent Activity
            // + OutboundEmailSent event all happen inside the existing pipeline — unchanged on success.
            $sent = $this->sendExisting($email, $sender);
            $this->recordEvent($sent, 'sent', ['provider' => $sent->provider]);

            return $sent;
        } catch (\Throwable $e) {
            // Record the failure as terminal history, then RE-THROW the original exception so the HTTP
            // response (and the frontend's existing error toast) is byte-identical to today's behaviour.
            $this->markFailed($email, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Send a previously-saved draft (Decision 2): the SAME Email row transitions draft → sent —
     * no second record is created. Thin alias over {@see sendExisting()}.
     *
     * @throws ValidationException when the draft has no resolvable recipient
     */
    public function sendDraft(Email $draft, User $sender): Email
    {
        return $this->sendExisting($draft, $sender);
    }

    /**
     * Send an already-persisted outbound Email row (a draft OR a Send-Later scheduled email) through
     * the SAME provider transport and the SAME centralized Activity log — the row transitions to
     * `sent`, no second record is created. This is the single outbound send pipeline (RC3 FROZEN #1):
     * drafts, scheduled sends, and Send-Now all funnel here.
     *
     * @throws ValidationException when the row has no resolvable recipient / lead
     */
    public function sendExisting(Email $email, User $sender): Email
    {
        $lead = $email->lead;

        // Business guard: an email whose lead has been soft-deleted (or unlinked) cannot be sent.
        // Fail as validation (422) BEFORE entering the send pipeline — never a 500 on a null lead.
        if ($lead === null) {
            throw ValidationException::withMessages([
                'lead' => 'This lead no longer exists. The email cannot be sent.',
            ]);
        }

        $toEmail = $this->resolveRecipient($lead, $email->to_email);
        $subject = (string) $email->subject;
        $sent = $this->dispatch($lead, $toEmail, $subject, (string) $email->body, $this->htmlFor($email));

        $email = DB::transaction(function () use ($email, $lead, $sender, $subject, $toEmail, $sent): Email {
            $email->update([
                'from_email' => $this->sender()->address,
                'from_name' => $this->sender()->name,
                'to_email' => $toEmail,
                'to_name' => $lead->contact_name,
                'provider' => $sent->provider,
                'provider_message_id' => $sent->providerMessageId,
                'message_id' => $sent->messageId,
                'status' => $sent->status,   // draft/scheduled → sent (or failed)
                'sent_at' => now(),
            ]);

            $this->logSent($lead, $sender, $email, $subject, $toEmail);

            return $email;
        });

        $this->announceIfSent($email);

        return $email;
    }

    /**
     * The text/html part for this row, or null to send plain text exactly as before.
     *
     * Rendered HERE, at send time, from the stored document — which is what lets a scheduled email
     * keep its formatting: the scheduler re-reads the row when it is due and arrives at this same
     * line. The document is re-validated rather than trusted because it has been at rest in the
     * database since it was accepted; a row that no longer validates is sent as plain text, never
     * rendered on a best-effort basis.
     */
    private function htmlFor(Email $email): ?string
    {
        $doc = $email->body_doc;

        if (! is_array($doc)) {
            return null;
        }

        if (EmailDocument::problem($doc) !== null) {
            Log::warning('outreach.body_doc.invalid', ['email_id' => $email->id]);

            return null;
        }

        return EmailDocument::toHtml($doc);
    }

    /**
     * Emit the OutboundEmailSent domain event after a successful send (RC3 Phase 5 seam — consumed by the
     * n8n `email.sent` webhook). Post-commit and status-guarded; adds no send behaviour.
     */
    private function announceIfSent(Email $email): void
    {
        if ($email->status === 'sent') {
            event(new OutboundEmailSent($email));
        }
    }

    /** @throws ValidationException when no recipient can be resolved */
    private function resolveRecipient(Lead $lead, ?string $to): string
    {
        $toEmail = $to !== null && $to !== '' ? $to : (string) $lead->email;

        if ($toEmail === '') {
            throw ValidationException::withMessages([
                'to' => 'A recipient email is required — this lead has no email on file.',
            ]);
        }

        return $toEmail;
    }

    /** Hand the message to the configured transport (provider-agnostic). */
    private function dispatch(Lead $lead, string $toEmail, string $subject, string $body, ?string $htmlBody = null): SentEmail
    {
        $from = $this->sender();

        return $this->provider->send(new EmailMessage(
            toEmail: $toEmail,
            toName: $lead->contact_name,
            subject: $subject,
            body: $body,
            fromEmail: $from->address,
            fromName: $from->name,
            htmlBody: $htmlBody,
        ));
    }

    /**
     * Mark a persisted outbound email as terminally failed (Send-Now transport failure). Records the
     * reason on the row and the append-only email_events timeline, mirroring the scheduled path's
     * failure semantics (ScheduledEmailService::markFailed) without depending on that service.
     */
    private function markFailed(Email $email, string $error): void
    {
        $email->update(['status' => 'failed', 'failed_at' => now(), 'error' => mb_substr($error, 0, 1000)]);
        $this->recordEvent($email, 'failed', ['error' => mb_substr($error, 0, 255)]);
        Log::warning('outreach.send.failed', ['email_id' => $email->id, 'error' => $error]);
    }

    /**
     * Append a lifecycle transition to the email_events timeline (same shape as
     * ScheduledEmailService::record), so Send-Now shares the scheduled path's audit granularity.
     *
     * @param array<string, mixed> $meta
     */
    private function recordEvent(Email $email, string $type, array $meta = []): void
    {
        EmailEvent::create([
            'email_id' => $email->id,
            'type' => $type,
            'occurred_at' => now(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** Reuse the existing centralized Activity log (identical pattern to LeadObserver). */
    private function logSent(Lead $lead, User $sender, Email $email, string $subject, string $toEmail): void
    {
        $lead->activities()->create([
            'causer_id' => $sender->id,
            'event' => 'email.sent',
            'description' => "{$sender->name} emailed {$lead->business_name}",
            'properties' => [
                'email_id' => $email->id,
                'subject' => $subject,
                'to' => $toEmail,
            ],
        ]);
    }
}
