<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use App\Support\CurrentOrg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Website Intelligence for the leads ONE volume-mode discovery run created — the step that stops them
 * sitting at "Not audited".
 *
 * ADDITIVE AND SEPARATE. It runs after the run is over, never inside it, and can change nothing the
 * run found. It drives the two existing engine commands — `find-websites` (leads with no URL: look for
 * one, or establish there is none) then `audit` (leads with a URL, including one just found) — through
 * the existing queue endpoints, narrowed by `lead_ids` to this run's leads. What those commands write,
 * and how `website_status` is derived, is exactly what the scheduled enrichment writes.
 *
 * NOT THE SCHEDULER. The scheduled `crm:enrich-websites` is deliberately not running (the same
 * scheduler would fire the stale scheduled-email sender), and this does not need it: it is one queued
 * job on the discovery worker, per run.
 *
 * BOUNDED. At most `volume.enrich_limit` leads per run, in chunks of {@see CHUNK}; each chunk is one job
 * whose two steps are each capped at `volume.enrich_step_timeout` seconds, so a job always fits the
 * worker's own 900s, and queues the next chunk when it is done. A failed step is logged, never retried:
 * the regular enrichment queue still holds the lead.
 *
 * WHICH LEADS: created by the discovery engine (source set) in this run's org between its start and its
 * finish. A second discovery run overlapping in the same org would have its new leads enriched here too
 * — which is harmless, since they are equally new and equally un-audited.
 */
class EnrichRunLeadsJob implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 50;

    /** Crawling is not idempotent traffic; a failure is logged and left to the regular queue. */
    public int $tries = 1;

    /** Matches the worker's --timeout=900. */
    public int $timeout = 900;

    public function __construct(
        public readonly string $runUuid,
        public readonly int $orgId,
        public readonly int $afterLeadId = 0,
    ) {}

    public function handle(ScraperProcessRunner $runner, NerbyServiceIdentity $service): void
    {
        CurrentOrg::runAs($this->orgId, fn () => $this->enrich($runner, $service));
    }

    private function enrich(ScraperProcessRunner $runner, NerbyServiceIdentity $service): void
    {
        $run = DiscoveryRun::query()->where('uuid', $this->runUuid)->first();
        if ($run === null || $run->started_at === null) {
            return;
        }

        $all = self::leadIdsFor($run);
        $chunk = array_values(array_slice(array_filter($all, fn (int $id): bool => $id > $this->afterLeadId), 0, self::CHUNK));
        if ($chunk === []) {
            return;
        }

        $timeout = max(30, (int) config('discovery.nationwide.volume.enrich_step_timeout', 400));
        $issued = $service->issue($this->orgId, 'enrich:'.$run->uuid, now()->addSeconds($timeout * 2 + 120));

        try {
            $search = $runner->findWebsitesForLeads($chunk, $issued->plainTextToken, $timeout);
            $audit = $runner->auditLeads($chunk, $issued->plainTextToken, $timeout);
        } finally {
            $service->revoke($issued);
        }

        Log::info('Volume run website intelligence', [
            'run' => $run->uuid,
            'leads' => count($chunk),
            'find_websites_exit' => $search['exitCode'],
            'audit_exit' => $audit['exitCode'],
        ]);

        $last = end($chunk);
        if (count(array_filter($all, fn (int $id): bool => $id > $last)) > 0) {
            self::dispatch($this->runUuid, $this->orgId, (int) $last)->onQueue('discovery');
        }
    }

    /**
     * The leads this run created, oldest first, capped at `volume.enrich_limit`.
     *
     * @return list<int>
     */
    public static function leadIdsFor(DiscoveryRun $run): array
    {
        return Lead::query()
            ->whereNotNull('source')
            ->where('created_at', '>=', $run->started_at)
            ->where('created_at', '<=', $run->finished_at ?? now())
            ->orderBy('id')
            ->limit(max(0, (int) config('discovery.nationwide.volume.enrich_limit', 200)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
