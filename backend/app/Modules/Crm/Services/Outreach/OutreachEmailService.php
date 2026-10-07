<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Events\OutboundEmailSent;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailDesign;
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
        // The designed-send collaborators. The renderer, validator and branding sit behind
        // EmailTemplateDesignService::designedHtml(), the SAME sequence the composer's per-lead preview
        // calls, and the token map is the one the composer is handed — which is what makes "what the
        // sender previewed is what the recipient receives" a property of the code rather than a hope
        // about two implementations staying in step.
        private readonly EmailTemplateDesignService $designs,
        private readonly TemplateRenderer $templates,
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
     * @param  int|null  $emailDesignId  the Email Design a Lead Details reply is sent in (validated, this
     *                                   org, live), or null — every other send.
     *
     * @throws ValidationException when no recipient can be resolved, or the chosen design cannot carry the reply
     */
    public function sendToLead(
        Lead $lead,
        User $sender,
        string $subject,
        string $body,
        ?string $to = null,
        ?array $bodyDoc = null,
        ?int $emailTemplateId = null,
        ?int $emailDesignId = null,
    ): Email {
        // Pre-flight recipient validation (422) BEFORE anything is persisted — a missing recipient is a
        // request error, not a delivery failure, so it must never create a history row.
        $toEmail = $this->resolveRecipient($lead, $to);

        // A designed reply is rendered once here, BEFORE anything is persisted, for the same reason: a design
        // that cannot carry this reply (too large for Gmail, a stored layout that no longer validates) is a
        // 422 the BD can act on — never a failed history row, and never plain text they did not choose.
        if ($emailDesignId !== null) {
            $this->designedReplyOrRefuse(EmailDesign::query()->findOrFail($emailDesignId), $bodyDoc, $lead, $sender);
        }

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
            // The designed template this was composed from, when it was. Recorded so the send path can
            // re-render its design; NULL — the usual case — sends plain text exactly as before.
            'email_template_id' => $emailTemplateId,
            // The design a Lead Details reply is sent in, when the BD chose one; NULL sends as before.
            'email_design_id' => $emailDesignId,
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
        // A designed reply sends both parts from its design — the text part is the design's text form, so
        // its signature reaches a plain-text reader too — while `body` stays the reply the BD wrote, which is
        // what CRM lists and notifications show. Every other email takes htmlFor() and `body`, as before.
        $designedReply = $this->designedReplyParts($email, $lead, $sender);
        $html = $designedReply['html'] ?? $this->htmlFor($email, $sender);
        $sent = $this->dispatch($lead, $toEmail, $subject, $designedReply['text'] ?? (string) $email->body, $html);

        $email = DB::transaction(function () use ($email, $lead, $sender, $subject, $toEmail, $sent, $html): Email {
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
                // THE SNAPSHOT OF WHAT WENT OUT: the very string handed to the transport above, so Lead
                // Details shows the sender what the recipient received rather than a re-render of
                // whatever the template, design, lead or logo look like later. Kept only when the send
                // succeeded — a message that never left must not read as a delivered design — and null
                // whenever the email went out as plain text, which is what it then was.
                'body_html' => $sent->status === 'sent' ? $html : null,
            ]);

            $this->logSent($lead, $sender, $email, $subject, $toEmail);

            return $email;
        });

        $this->announceIfSent($email);

        return $email;
    }

    /**
     * Both parts of a Lead Details reply sent in an Email Design, or null when this email has no design.
     *
     * Only Send-Now writes `email_design_id` (SendEmailRequest refuses it with scheduled_at), and it has
     * already rendered this exact reply once before persisting, so a refusal here means the design changed
     * in between. That fails the send — recorded as a failed row by sendToLead() — rather than quietly
     * sending plain text the BD did not choose.
     *
     * @return array{html: string, text: string}|null
     */
    private function designedReplyParts(Email $email, Lead $lead, User $sender): ?array
    {
        if ($email->email_design_id === null) {
            return null;
        }

        $design = $email->emailDesign;
        if ($design === null || $design->trashed()) {
            throw new \RuntimeException('The email design this reply was sent in is no longer available.');
        }

        return $this->designedReplyOrRefuse($design, $email->body_doc, $lead, $sender);
    }

    /**
     * Render a designed reply, or refuse it with a 422 the BD can act on.
     *
     * @param  array<string, mixed>|null  $bodyDoc
     * @return array{html: string, text: string}
     *
     * @throws ValidationException
     */
    private function designedReplyOrRefuse(EmailDesign $design, ?array $bodyDoc, Lead $lead, User $sender): array
    {
        if (! is_array($bodyDoc) || EmailDocument::problem($bodyDoc) !== null) {
            throw ValidationException::withMessages(['body_doc' => 'Write your reply before choosing a design.']);
        }

        $rendered = $this->designs->designedReplyHtml($design, $bodyDoc, $lead, $sender);

        if ($rendered['html'] === null || $rendered['text'] === null) {
            Log::warning('outreach.designed_reply.refused', [
                'email_design_id' => $design->id,
                'reason' => $rendered['reason'],
                'bytes' => $rendered['bytes'],
            ]);

            throw ValidationException::withMessages([
                'email_design_id' => EmailTemplateDesignService::replyRefusalMessage((string) $rendered['reason']),
            ]);
        }

        return ['html' => $rendered['html'], 'text' => $rendered['text']];
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
    private function htmlFor(Email $email, User $sender): ?string
    {
        $doc = $email->body_doc;

        // A FORMATTED REPLY STILL WINS, AND ITS BEHAVIOUR IS UNCHANGED — including the invalid case,
        // which has always meant "send plain text" and must not now mean "try the design instead".
        // A row carrying a document was written by someone typing in the composer; that is the
        // message, whatever else the row remembers.
        if (is_array($doc)) {
            if (EmailDocument::problem($doc) !== null) {
                Log::warning('outreach.body_doc.invalid', ['email_id' => $email->id]);

                return null;
            }

            return EmailDocument::toHtml($doc);
        }

        return $this->designedHtmlFor($email, $sender);
    }

    /**
     * The text/html part for an email composed from a designed template — the design a person previewed.
     *
     * THE SENDER IS A PARAMETER, NOT `$email->user`. The Email model has no `user` relation — reading
     * one silently yields null, the signature's sender name renders blank, and the edit fence below
     * then sees a mismatch it invented and refuses every designed send. The acting sender is already
     * in hand at the only call site, and it is also the right answer: the signature should name whoever
     * is sending this message.
     *
     * RENDERED HERE, AT SEND TIME, from the template and design documents, exactly as the formatted-reply
     * path above renders from its stored document. Nothing is stored pre-rendered, so a draft sent later
     * renders against the design as it stands then rather than a frozen copy.
     *
     * EVERY FAILURE RETURNS NULL, which is the plain-text send this email was always able to fall back to.
     * There is no best-effort render: half a design is not a smaller version of the design, it is an email
     * nobody approved. Each reason is logged, because a silent downgrade from a designed email to plain
     * text is exactly the kind of thing that goes unnoticed for months.
     */
    private function designedHtmlFor(Email $email, User $sender): ?string
    {
        if ($email->email_template_id === null) {
            return null;
        }

        $reason = static fn (string $why): string => $why;

        // `nullOnDelete` means the id can simply be gone. A deleted template is not an error; it is an
        // email that can no longer re-render, and it still has the body it was always going to send.
        $template = $email->emailTemplate;
        $lead = $email->lead;

        $problem = match (true) {
            $template === null => $reason('template_missing'),
            ! $template->isDesigned() => $reason('template_not_designed'),
            $template->design === null => $reason('design_missing'),
            $lead === null => $reason('lead_missing'),
            default => null,
        };

        if ($problem !== null) {
            Log::warning('outreach.designed_html.skipped', ['email_id' => $email->id, 'reason' => $problem]);

            return null;
        }

        // ── THE EDIT FENCE ────────────────────────────────────────────────────────────────────────
        // The composer hands a BD the template's flattened plain text and lets them change it. If they
        // did, the blocks no longer say what the message says, and rendering them would send the
        // recipient an email the sender never wrote — silently, because the HTML part is the one a
        // person reads. So the server recomputes exactly what the composer was given
        // (EmailTemplateController::index renders the same two fields the same way) and refuses to
        // render a design whose text no longer matches. Decided HERE rather than trusting the browser
        // to have dropped the id: this is a correctness guarantee, not a convenience.
        // COMPARED TRIMMED AT THE EDGES, because the request pipeline already normalised one side and
        // not the other: TrimStrings trims `body` on the way in (`body_doc.*` is exempt in
        // bootstrap/app.php; `body` is not), while a rendered email's plain text ends with a newline.
        // Comparing raw would make EVERY designed send look edited — the fence would be permanently
        // closed and nothing would ever render. Interior differences, which are what an edit actually
        // is, still count.
        $asOffered = $this->templates->render($template->subject, $template->body, $lead, $sender)['body'];

        if (trim($asOffered) !== trim((string) $email->body)) {
            Log::info('outreach.designed_html.skipped', ['email_id' => $email->id, 'reason' => 'body_edited']);

            return null;
        }

        // Validation, rendering and the Gmail guard are the shared sequence the composer's per-lead
        // preview also calls (EmailTemplateDesignService::designedHtml), so what that preview shows is the
        // HTML this line hands the provider. Each refusal is logged here exactly as it always was.
        $rendered = $this->designs->designedHtml($template, $lead, $sender);

        if ($rendered['html'] === null) {
            Log::warning('outreach.designed_html.skipped', $rendered['reason'] === 'html_too_large'
                ? [
                    'email_id' => $email->id,
                    'reason' => 'html_too_large',
                    'bytes' => $rendered['bytes'],
                    'max' => EmailTemplateDesignService::MAX_HTML_BYTES,
                ]
                : ['email_id' => $email->id, 'reason' => $rendered['reason']]);

            return null;
        }

        return $rendered['html'];
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
