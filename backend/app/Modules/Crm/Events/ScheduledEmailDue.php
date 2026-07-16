<?php

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Email;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised (RC3, FROZEN #6 event-driven) when a scheduled email is due and has been claimed by the
 * sender job. Its listener performs the actual send through the single outbound pipeline. Keeping this
 * a domain event lets future consumers (delivery monitoring, n8n webhooks) subscribe without touching
 * the scheduling code.
 */
class ScheduledEmailDue
{
    use Dispatchable;

    public function __construct(public readonly Email $email) {}
}
