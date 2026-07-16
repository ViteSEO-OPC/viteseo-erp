<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recomputes the Personal Lead Score (`leads.score`) for every non-deleted lead (BD-PERF-COMP-SPEC
 * §4 + Amendment A/B). Per-lead recompute happens automatically on write (see LeadObserver); this
 * command covers the cases an event hook cannot:
 *
 *   - **Backfill** — populate scores for leads that predate the engine.
 *   - **Registry change** — a Service-Area edit changes the Target Location (+10) verdict for many
 *     leads at once (no lead row changed, so no observer fired).
 *   - **Staleness** — the time-relative signals (Recent Activity ≤7d, No Activity >30d) cross their
 *     thresholds as the clock advances even with no data change; a scheduled run keeps stored scores
 *     in spec (BD-PERF-COMP-SPEC §2 fixed evaluation instant).
 *
 * A fresh {@see LeadScorer} is resolved so the active Service-Area registry is read at run time. All
 * leads are evaluated against a single "now" so a run is internally consistent.
 */
class RecomputeLeadScoresCommand extends Command
{
    protected $signature = 'leads:recompute-scores {--chunk=500 : Rows per batch}';

    protected $description = 'Recompute the Personal Lead Score for all leads (backfill / registry change / staleness)';

    public function handle(LeadScorer $scorer): int
    {
        $now = Carbon::now();
        $updated = 0;
        $processed = 0;

        Lead::query()
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($leads) use ($scorer, $now, &$updated, &$processed): void {
                foreach ($leads as $lead) {
                    $before = $lead->score;
                    $after = $scorer->refresh($lead, $now);
                    $processed++;
                    if ($before !== $after) {
                        $updated++;
                    }
                }
            });

        $this->info("Recomputed {$processed} lead score(s); {$updated} changed.");

        return self::SUCCESS;
    }
}
