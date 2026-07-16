<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly EmailProvider $provider) {}

    /**
     * @throws ValidationException when no recipient can be resolved
     */
    public function sendToLead(Lead $lead, User $sender, string $subject, string $body, ?string $to = null): Email
    {
        $toEmail = $this->resolveRecipient($lead, $to);
        $sent = $this->dispatch($lead, $toEmail, $subject, $body);

        return DB::transaction(function () use ($lead, $sender, $subject, $body, $toEmail, $sent): Email {
            $email = Email::create([
                'lead_id' => $lead->id,
                'user_id' => $sender->id,
                'direction' => 'outbound',
                'from_email' => (string) config('outreach.from.address'),
                'from_name' => (string) config('outreach.from.name'),
                'to_email' => $toEmail,
                'to_name' => $lead->contact_name,
                'subject' => $subject,
                'body' => $body,
                'provider' => $sent->provider,
                'provider_message_id' => $sent->providerMessageId,
                'message_id' => $sent->messageId,
                'status' => $sent->status,
                'sent_at' => now(),
            ]);

            $this->logSent($lead, $sender, $email, $subject, $toEmail);

            return $email;
        });
    }

    /**
     * Send a previously-saved draft (Decision 2): the SAME Email row transitions draft → sent —
     * no second record is created. Reuses the provider transport and the centralized Activity log,
     * so the send behaviour is identical to sendToLead().
     *
     * @throws ValidationException when the draft has no resolvable recipient
     */
    public function sendDraft(Email $draft, User $sender): Email
    {
        $lead = $draft->lead;

        // Business guard: a draft whose lead has been soft-deleted (or unlinked) cannot be sent.
        // Fail as validation (422) BEFORE entering the send pipeline — never a 500 on a null lead.
        if ($lead === null) {
            throw ValidationException::withMessages([
                'lead' => 'This lead no longer exists. The draft cannot be sent.',
            ]);
        }

        $toEmail = $this->resolveRecipient($lead, $draft->to_email);
        $subject = (string) $draft->subject;
        $sent = $this->dispatch($lead, $toEmail, $subject, (string) $draft->body);

        return DB::transaction(function () use ($draft, $lead, $sender, $subject, $toEmail, $sent): Email {
            $draft->update([
                'from_email' => (string) config('outreach.from.address'),
                'from_name' => (string) config('outreach.from.name'),
                'to_email' => $toEmail,
                'to_name' => $lead->contact_name,
                'provider' => $sent->provider,
                'provider_message_id' => $sent->providerMessageId,
                'message_id' => $sent->messageId,
                'status' => $sent->status,   // draft → sent (or failed)
                'sent_at' => now(),
            ]);

            $this->logSent($lead, $sender, $draft, $subject, $toEmail);

            return $draft;
        });
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
    private function dispatch(Lead $lead, string $toEmail, string $subject, string $body): SentEmail
    {
        return $this->provider->send(new EmailMessage(
            toEmail: $toEmail,
            toName: $lead->contact_name,
            subject: $subject,
            body: $body,
            fromEmail: (string) config('outreach.from.address'),
            fromName: (string) config('outreach.from.name'),
        ));
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
