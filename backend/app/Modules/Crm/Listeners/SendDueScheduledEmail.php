<?php

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\ScheduledEmailDue;
use App\Modules\Crm\Services\Outreach\ScheduledEmailService;

/**
 * Delivers a due scheduled email through the single outbound pipeline (RC3). Runs inline inside the
 * already-queued SendScheduledEmailsJob (not itself ShouldQueue), so no extra job is spawned.
 */
class SendDueScheduledEmail
{
    public function __construct(private readonly ScheduledEmailService $scheduled) {}

    public function handle(ScheduledEmailDue $event): void
    {
        $this->scheduled->deliver($event->email);
    }
}
