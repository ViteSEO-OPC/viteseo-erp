<?php

namespace App\Modules\Crm\Services\Mail\Providers;

use App\Modules\Crm\Services\Mail\Contracts\EmailProvider;
use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

/**
 * SMTP implementation — the Phase 1 provider. Delegates to Laravel's configured
 * mailer, so the concrete transport (smtp / log / …) is controlled by MAIL_MAILER
 * and this class stays free of transport wiring. Works with Google Workspace,
 * Microsoft 365, Zoho, Hostinger, cPanel, and any other SMTP host.
 */
class SmtpEmailProvider implements EmailProvider
{
    /** Identity persisted on the email history record. Internal to this provider. */
    private const PROVIDER = 'smtp';

    public function __construct(private readonly Mailer $mailer) {}

    public function send(EmailMessage $message): SentEmail
    {
        $sent = $message->htmlBody === null
            // Plain text only — every email that is not a formatted reply, byte-for-byte as before.
            ? $this->mailer->raw($message->body, fn (Message $mail) => $this->address($mail, $message))
            // A formatted reply: text/plain AND text/html, as multipart/alternative. The text part is
            // the same canonical `body` every other path sends, so a client that shows plain text
            // (or a reader that prefers it) gets exactly the readable version, never an empty part.
            : $this->mailer->html($message->htmlBody, function (Message $mail) use ($message): void {
                $this->address($mail, $message);
                $mail->getSymfonyMessage()->text($message->body);
            });

        // Capture the actually-transmitted RFC Message-ID so inbound replies can thread to it.
        $messageId = $sent !== null
            ? $this->normalizeMessageId($sent->getSymfonySentMessage()->getMessageId())
            : null;

        return new SentEmail(provider: self::PROVIDER, messageId: $messageId);
    }

    private function address(Message $mail, EmailMessage $message): void
    {
        $mail->to($message->toEmail, $message->toName)
            ->from($message->fromEmail, $message->fromName)
            ->subject($message->subject);
    }

    /** Strip angle brackets/whitespace so stored + parsed Message-IDs compare consistently. */
    private function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $bare = trim($messageId, " \t<>");

        return $bare === '' ? null : $bare;
    }
}
