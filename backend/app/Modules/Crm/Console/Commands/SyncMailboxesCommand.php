<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Jobs\SyncMailboxJob;
use App\Modules\Crm\Models\MailboxSync;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fans inbound mailbox synchronization out to one queued job per mailbox.
 *
 * Scheduled every five minutes (see CrmServiceProvider) — this command IS the inbound trigger.
 * It replaced `outreach:watch`, which armed a Gmail push watch against a Pub/Sub topic and had to
 * be re-armed before the watch lapsed. Polling needs no Google Cloud resources, no public HTTPS
 * webhook, and no renewal: if the scheduler runs, inbound works.
 *
 * The command only decides WHICH mailboxes to sync and hands each to the queue. It deliberately
 * does no Gmail work itself, so the scheduler tick returns immediately and one unreachable mailbox
 * cannot hold up the others — see SyncMailboxJob for the per-mailbox guarantees.
 *
 *   php artisan outreach:sync                          every active mailbox (what the scheduler runs)
 *   php artisan outreach:sync --mailbox=ops@acme.test  one mailbox, e.g. to onboard or debug it
 *   php artisan outreach:sync --now                    run inline instead of queueing (no worker needed)
 */
class SyncMailboxesCommand extends Command
{
    /** The one status that opts a mailbox OUT of polling; see targets(). */
    private const STATUS_PAUSED = 'paused';

    protected $signature = 'outreach:sync
        {--mailbox= : Sync only this mailbox (created on first use if unknown)}
        {--org= : Org that owns --mailbox, when onboarding an address with no row yet}
        {--now : Run synchronously in this process instead of dispatching to the queue}';

    protected $description = 'Poll the configured inbound mailboxes for new mail (scheduled every five minutes)';

    public function handle(): int
    {
        $mailboxes = $this->targets();

        if ($mailboxes->isEmpty()) {
            // Not a failure: a deployment with inbound configured but no mailbox registered yet is a
            // normal state, and the scheduler must not log an error every five minutes because of it.
            $this->warn('No active mailboxes to sync. Set OUTREACH_MAILBOX or pass --mailbox=.');

            return self::SUCCESS;
        }

        foreach ($mailboxes as $mailbox => $orgId) {
            if ($this->option('now')) {
                SyncMailboxJob::dispatchSync($mailbox, $orgId);
            } else {
                SyncMailboxJob::dispatch($mailbox, $orgId);
            }

            $this->line("  queued sync for {$mailbox}".($orgId !== null ? " (org #{$orgId})" : ''));
        }

        $this->info("Inbound sync dispatched for {$mailboxes->count()} mailbox(es).");

        return self::SUCCESS;
    }

    /**
     * The mailboxes this run should sync.
     *
     * `--mailbox` is taken at face value rather than validated against the table, so a new mailbox
     * can be onboarded with one command: SyncMailboxJob creates the row and the provider bootstraps
     * the cursor from a bounded recent window.
     *
     * Otherwise: every mailbox that is not PAUSED, falling back to the configured mailbox when the
     * table is empty. That fallback is what makes a fresh deployment self-starting — without it,
     * inbound would sit silent until someone manually inserted a row.
     *
     * Note the filter is "not paused", NOT "active". `status` distinguishes a deliberate stop
     * (`paused`) from a transient failure (`error`, set by SyncMailboxJob when Gmail is unreachable).
     * Selecting only `active` would mean one Gmail outage silently removed a mailbox from the
     * rotation for good, since nothing else ever sets it back. A failing mailbox must keep being
     * retried; only a human pausing it should stop the polling.
     *
     * TENANT FENCE: the console has no authenticated user, so `CurrentOrg::id()` falls
     * through to the FIRST org and the BelongsToOrg scope would silently narrow this to
     * that one tenant's mailboxes — dropping every other tenant out of the rotation with
     * no error, while the command reported success every five minutes. The fan-out
     * therefore reads ACROSS orgs explicitly and hands each job its mailbox's own org;
     * SyncMailboxJob pins to it before touching anything.
     *
     * @return Collection<string, int|null>  mailbox address => owning org id
     */
    private function targets(): Collection
    {
        $explicit = $this->option('mailbox');
        if (is_string($explicit) && $explicit !== '') {
            $org = $this->option('org');

            // No --org: the job resolves the owner from the existing row. An address with
            // no row anywhere is registered in the default org context, which is what
            // onboarding the provider's own mailbox has always done.
            return collect([$explicit => ($org !== null && $org !== '' ? (int) $org : null)]);
        }

        /** @var Collection<string, int|null> $syncable */
        $syncable = MailboxSync::withoutGlobalScope('org')
            ->where('status', '!=', self::STATUS_PAUSED)
            ->get(['email', 'org_id'])
            ->mapWithKeys(fn ($row) => [$row->email => $row->org_id !== null ? (int) $row->org_id : null]);

        if ($syncable->isNotEmpty()) {
            return $syncable;
        }

        // Deployment default: the provider org's own fallback, so a fresh single-org
        // install is self-starting. Null org — it is nobody's tenant mailbox yet.
        $configured = config('outreach.mailbox');

        return is_string($configured) && $configured !== ''
            ? collect([$configured => null])
            : collect();
    }
}
