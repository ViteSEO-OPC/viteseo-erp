<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Email;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RC3 Phase 5 — raised after an outbound email is successfully sent through the single pipeline
 * (OutreachEmailService → EmailProvider). A domain seam (FROZEN #6) that consumers subscribe to without
 * touching the send path — RC3 uses it to emit the `email.sent` CRM → n8n webhook.
 */
class OutboundEmailSent
{
    use Dispatchable;

    public function __construct(public readonly Email $email) {}
}
