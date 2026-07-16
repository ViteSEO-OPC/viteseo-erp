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
        // Body is composed as plain text in the workspace; send it as-is.
        $sent = $this->mailer->raw($message->body, function (Message $mail) use ($message): void {
            $mail->to($message->toEmail, $message->toName)
                ->from($message->fromEmail, $message->fromName)
                ->subject($message->subject);
        });

        // Capture the actually-transmitted RFC Message-ID so inbound replies can thread to it.
        $messageId = $sent !== null
            ? $this->normalizeMessageId($sent->getSymfonySentMessage()->getMessageId())
            : null;

        return new SentEmail(provider: self::PROVIDER, messageId: $messageId);
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
