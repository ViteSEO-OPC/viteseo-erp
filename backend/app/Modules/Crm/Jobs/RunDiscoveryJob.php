<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Services\Discovery\DiscoveryRunOrchestrator;
use App\Support\CurrentOrg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes one on-demand discovery run off the web request.
 *
 * THE QUEUE NAME IS NOT DECORATION. Every other job in this codebase runs on the default queue, so
 * this is the first job that names one — and it must. The dedicated worker defined in
 * docker-compose.yml consumes `--queue=discovery` and nothing else, precisely so a fifteen-minute
 * sweep never sits behind a mailbox sync and a mailbox sync never sits behind a sweep. A dispatch
 * that forgets `->onQueue('discovery')` is enqueued forever with no consumer, which is exactly the
 * silent dead end this arrangement exists to prevent. See DiscoveryRunController::store().
 *
 * `$tries = 1` MATCHES the worker's `--tries=1` and is a spending decision, not a reliability one:
 * a discovery run buys results from a paid provider, and a retry would buy them a second time.
 * `ingestDiscovered` deduplicates the ROWS, but nothing refunds the calls. A failed run is therefore
 * reported to a human and re-requested deliberately, never retried automatically.
 *
 * THE PAYLOAD CARRIES NO CREDENTIALS — only a run id and the org that owns it. Every secret is
 * resolved inside handle(), at execution time, on the machine that needs it.
 */
class RunDiscoveryJob implements ShouldQueue
{
    use Queueable;

    /** A retry would re-spend at the provider. Failures are surfaced, not repeated. */
    public int $tries = 1;

    /** Matches the worker's --timeout=900. */
    public int $timeout = 900;

    public function __construct(
        private readonly string $runUuid,
        private readonly int $orgId,
    ) {}

    public function handle(DiscoveryRunOrchestrator $orchestrator): void
    {
        // A queued job has no authenticated user, and CurrentOrg fails closed without one: a scoped
        // read would return nothing and a stamped insert would fail on the NOT NULL column. So the
        // tenant is carried in the payload and pinned explicitly, exactly as SyncMailboxJob does.
        CurrentOrg::runAs($this->orgId, fn () => $orchestrator->execute($this->runUuid));
    }

    /**
     * The queue gave up. Leave a terminal state behind, or the poller spins on "running" forever.
     *
     * Mirrors ConvertXlsImportJob::failed(), the one existing precedent for a job a UI is watching.
     */
    public function failed(?Throwable $e): void
    {
        CurrentOrg::runAs($this->orgId, function (): void {
            $run = DiscoveryRun::query()->where('uuid', $this->runUuid)->first();

            if ($run === null || $run->isTerminal()) {
                return;
            }

            $run->forceFill([
                'status' => DiscoveryRun::STATUS_FAILED,
                'phase' => null,
                'finished_at' => now(),
                'error_message' => 'The discovery run stopped unexpectedly. Any businesses already found have been saved.',
                // A dead slice leaves no checkpoint for a successor that will never come (H-5).
                'work_queue' => null,
            ])->save();
        });
    }
}
