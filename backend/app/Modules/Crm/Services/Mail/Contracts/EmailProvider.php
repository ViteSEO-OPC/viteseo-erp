<?php

namespace App\Modules\Crm\Services\Mail\Contracts;

use App\Modules\Crm\Services\Mail\EmailMessage;
use App\Modules\Crm\Services\Mail\SentEmail;

/**
 * Transport-agnostic email sender. The Outreach business layer depends on this
 * contract only — never on a concrete provider. Add Gmail OAuth, Microsoft Graph,
 * Resend, SendGrid, Amazon SES, etc. by binding a new implementation in
 * OutreachServiceProvider; no Outreach code changes.
 */
interface EmailProvider
{
    /** Sends the message and returns a result carrying the provider identity + status. */
    public function send(EmailMessage $message): SentEmail;
}
