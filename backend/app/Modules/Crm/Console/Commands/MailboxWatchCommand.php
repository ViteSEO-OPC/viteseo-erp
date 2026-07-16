<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Jobs\RenewMailboxWatchJob;
use Illuminate\Console\Command;

/**
 * Arms (or renews) the Gmail push watch for the configured mailbox(es). Run once after the
 * Google Cloud / domain-wide-delegation setup to turn inbound sync on; the daily scheduler
 * keeps it renewed thereafter.
 */
class MailboxWatchCommand extends Command
{
    protected $signature = 'outreach:watch';

    protected $description = 'Arm/renew the inbound mailbox push watch (Gmail) for configured mailboxes';

    public function handle(): int
    {
        RenewMailboxWatchJob::dispatchSync();
        $this->info('Mailbox watch renewal executed. Check mailbox_syncs for cursor + expiry.');

        return self::SUCCESS;
    }
}
