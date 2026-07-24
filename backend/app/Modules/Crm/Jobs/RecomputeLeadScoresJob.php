<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Services\Scoring\ScoringConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronous Lead Score recompute, dispatched AFTER a Business Rules publish/rollback commits (§6a).
 * Reuses the EXISTING bulk recompute maintenance command (`leads:recompute-scores`) — there is no new
 * scoring code and no new score-writing path: the same frozen scorer re-derives every lead's score from the
 * CURRENT active configuration (resolved via the Configuration Provider at run time).
 *
 * VERSION-PINNED (§6a.11): `targetVersion` records the version whose publish/rollback triggered this run —
 * a diagnostic tag for traceability and troubleshooting, NOT a parameter source. The recompute always
 * converges toward the current active configuration, so overlapping runs are safe (§6a.7/§6a.8).
 *
 * Idempotent and convergent: running it once or many times yields the same stored scores, so retries and
 * duplicates cannot corrupt data. Runs on the platform queue so the publish request never blocks.
 */
class RecomputeLeadScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $targetVersion) {}

    public function handle(): void
    {
        Log::info('business_rules.recompute_started', [
            'event' => 'business_rules.recompute_started',
            'module' => 'lead_scoring',
            'target_version' => $this->targetVersion,
        ]);

        // Force a FRESH ScoringConfig: drop any container-cached singleton so the recompute resolves the
        // CURRENT active configuration via the provider (whose cache the publish/rollback just invalidated).
        // In production the async worker already has a fresh container; this also makes the sync driver
        // correct, so the recompute can never run on stale parameters.
        app()->forgetInstance(ScoringConfig::class);

        Artisan::call('leads:recompute-scores');

        Log::info('business_rules.recompute_completed', [
            'event' => 'business_rules.recompute_completed',
            'module' => 'lead_scoring',
            'target_version' => $this->targetVersion,
            'result' => trim(Artisan::output()),
        ]);
    }
}
