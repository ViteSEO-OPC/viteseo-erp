<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use Illuminate\Support\Facades\Log;

/**
 * Executes one discovery run: task by task, provider-honestly, without touching anything frozen.
 *
 * WHAT THIS ORCHESTRATES, AND WHAT IT REFUSES TO DO
 *
 * It decides WHICH (area, niche) tasks to run and in what order, invokes the canonical Python engine
 * for each, and records what happened. It does NOT discover businesses itself, does not talk to
 * Google or Overpass, does not normalize, and does not write a single `crm_leads` row — the engine
 * posts to the existing `POST /leads/discovered`, and the existing `ingestDiscovered` remains the
 * only thing that creates prospects, with its existing deduplication and its existing allowlist.
 *
 * THE POOL IS NOT THE SOURCE. Scope comes from the user's request. Nothing here reads `crm_leads` to
 * decide what may be discovered.
 *
 * PRIORITY IS NEVER A DISCOVERY FILTER. Every valid business the provider returns is handed to the
 * ingest. The ingest's field allowlist cannot even accept `priority`, `status` or `score`, so
 * discovery is structurally incapable of pre-filtering by them; the existing frozen scorer assigns
 * priority afterwards, exactly as it does for every other lead.
 *
 * PROVIDER DEGRADATION IS AN EVENT, NOT A SECRET. A credential that is missing or switched off is
 * already resolved before the run starts. A credential that is present but REJECTED can only be
 * discovered by using it — so the first task that comes back `auth_failed` degrades the run to the
 * OpenStreetMap baseline, stamps the reason and the time, and re-runs that same task on the
 * baseline. Every task records which provider actually answered it.
 */
class DiscoveryRunOrchestrator
{
    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly ScraperProcessRunner $runner,
        private readonly NationwidePlanner $planner,
    ) {}

    public function execute(string $runUuid): void
    {
        $run = DiscoveryRun::query()->where('uuid', $runUuid)->first();

        // Already finished, already cancelled, or gone. Re-entry must be harmless: a worker restart
        // must never re-spend at a paid provider.
        if ($run === null || $run->isTerminal()) {
            return;
        }

        $token = (string) config('discovery.crm_token', '');
        if ($token === '') {
            // Fail fast and say exactly why. Without this the engine would run, discover real
            // businesses, and then discard every one of them at the ingest step.
            $this->fail($run, 'Discovery is not configured: the engine has no CRM token to submit results with.');

            return;
        }

        $effective = $this->beginRun($run);
        $metricsPath = $this->metricsPath($run);

        // NATIONWIDE IS A DIFFERENT SHAPE OF RUN, not a longer one. A scoped run knows every search
        // it will make before it makes any of them; a nationwide run discovers its own work as it
        // goes, because whether a cell needs subdividing is only knowable once that cell has
        // answered. Branching here keeps the scoped loop below byte-for-byte what it was.
        if ($run->isNationwide()) {
            $this->executeNationwide($run, $effective, $metricsPath);

            return;
        }

        foreach ($run->targets()->orderBy('id')->get() as $target) {
            // Cancellation is cooperative and checked between tasks. It can stop the NEXT call; it
            // cannot un-spend one already made.
            if ($this->isCancelled($run)) {
                $this->skipRemaining($run);

                return;
            }

            $effective = $this->runTarget($run, $target, $effective, $metricsPath);
        }

        $this->finish($run);
    }

    /** Snapshot which provider will run, and why, before anything is spent. */
    private function beginRun(DiscoveryRun $run): NerbyProvider
    {
        $resolved = $this->settings->resolveQuietly();

        $run->forceFill([
            'status' => DiscoveryRun::STATUS_RUNNING,
            'phase' => 'discovering',
            'started_at' => now(),
            'provider_requested' => $resolved->configured->value,
            'provider_effective' => $resolved->provider->value,
            'provider_reason' => $resolved->reason,
            'provider_mode' => $resolved->mode,
        ])->save();

        return $resolved->provider;
    }

    /**
     * Run one task, degrading the whole run to the baseline if the credential turns out to be bad.
     *
     * @return NerbyProvider the provider subsequent tasks should use
     */
    private function runTarget(
        DiscoveryRun $run,
        DiscoveryRunTarget $target,
        NerbyProvider $provider,
        string $metricsPath,
    ): NerbyProvider {
        $target->forceFill([
            'status' => DiscoveryRunTarget::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        // THE COUNTRY TRAVELS WITH EVERY SEARCH, scoped and nationwide alike. It fences the engine's
        // geocoder, so a location outside it cannot resolve and therefore cannot become a provider
        // request — the refusal happens before anything is spent, not after results are discarded.
        $country = $this->countryOf($run);

        $result = $this->runner->run(
            $target->area,
            $target->category,
            (int) $target->limit_per_task,
            $provider->value,
            $metricsPath,
            null,
            $country,
        );

        // A present-but-rejected credential is only knowable at this point. Degrade once, record it,
        // and give this task a second chance on the baseline so the work is not simply lost.
        if ($this->shouldDegrade($run, $provider, $result)) {
            $this->degrade($run, (string) ($result['executionStatus'] ?? 'auth_failed'));
            $provider = NerbyProvider::default();

            $result = $this->runner->run(
                $target->area,
                $target->category,
                (int) $target->limit_per_task,
                $provider->value,
                $metricsPath,
                null,
                $country,
            );
        }

        $this->recordTarget($run, $target, $provider, $result);

        return $provider;
    }

    /**
     * @param  array{exitCode: int, executionStatus: ?string, errorReason: ?string}  $result
     */
    private function shouldDegrade(DiscoveryRun $run, NerbyProvider $provider, array $result): bool
    {
        return $provider !== NerbyProvider::default()
            && $run->provider_degraded_at === null
            && in_array(
                $result['executionStatus'],
                [
                    GooglePlacesTextSearch::STATUS_AUTH_FAILED,
                    GooglePlacesTextSearch::STATUS_INVALID_REQUEST,
                ],
                true,
            );
    }

    private function degrade(DiscoveryRun $run, string $reason): void
    {
        $run->forceFill([
            'provider_effective' => NerbyProvider::default()->value,
            'provider_degraded_at' => now(),
            'degraded_reason' => $reason,
        ])->save();

        // Operators need to find this. The reason is a status word, never the credential.
        Log::warning('Nearby discovery run degraded to the baseline provider', [
            'run' => $run->uuid,
            'configured' => $run->provider_requested,
            'effective' => NerbyProvider::default()->value,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array{
     *     exitCode: int, executionStatus: ?string, errorReason: ?string,
     *     discovered: int, inserted: int, duplicates: int, rejected: int, stderrTail: string
     * }  $result
     */
    private function recordTarget(
        DiscoveryRun $run,
        DiscoveryRunTarget $target,
        NerbyProvider $provider,
        array $result,
    ): void {
        $ok = $result['exitCode'] === 0;

        $target->forceFill([
            'status' => $ok ? DiscoveryRunTarget::STATUS_COMPLETED : DiscoveryRunTarget::STATUS_FAILED,
            'provider_used' => $provider->value,
            'exit_code' => $result['exitCode'],
            'execution_status' => $result['executionStatus'],
            'error_reason' => $ok ? null : $this->explain($result),
            'discovered' => $result['discovered'],
            'inserted' => $result['inserted'],
            'duplicates' => $result['duplicates'],
            'rejected' => $result['rejected'],
            'finished_at' => now(),
        ])->save();

        // Counters accumulate in the database rather than in memory, so a crashed worker still
        // leaves a truthful partial record behind.
        $run->increment($ok ? 'targets_completed' : 'targets_failed');
        $run->forceFill([
            'discovered' => (int) $run->discovered + $result['discovered'],
            'inserted' => (int) $run->inserted + $result['inserted'],
            'duplicates' => (int) $run->duplicates + $result['duplicates'],
            'rejected' => (int) $run->rejected + $result['rejected'],
        ])->save();
    }

    /**
     * A short, human-readable cause.
     *
     * Exit 3 is the engine's "the provider did not execute" — a FAILED task, never a quiet empty
     * one. Exit 2 is a usage/configuration refusal and is task-local, not run-fatal: the commonest
     * case is a free-text niche that OpenStreetMap has no selector for, which must fail that one
     * search and leave the rest of the run alone.
     *
     * @param  array{exitCode: int, executionStatus: ?string, errorReason: ?string, stderrTail: string}  $result
     */
    private function explain(array $result): string
    {
        if ($result['errorReason'] !== null) {
            return $result['errorReason'];
        }

        return match ($result['exitCode']) {
            2 => 'The engine refused this search (unsupported niche for this provider, or a configuration problem).',
            3 => 'The provider did not complete the search, so nothing was retrieved. This is a failed search, not an empty area.',
            default => $result['stderrTail'] !== ''
                ? $result['stderrTail']
                : 'The search failed for an unknown reason.',
        };
    }

    private function isCancelled(DiscoveryRun $run): bool
    {
        return DiscoveryRun::query()->whereKey($run->id)->value('status') === DiscoveryRun::STATUS_CANCELLED;
    }

    private function skipRemaining(DiscoveryRun $run): void
    {
        $run->targets()
            ->whereIn('status', [DiscoveryRunTarget::STATUS_PENDING, DiscoveryRunTarget::STATUS_RUNNING])
            ->update(['status' => DiscoveryRunTarget::STATUS_SKIPPED, 'updated_at' => now()]);

        $run->forceFill(['phase' => null, 'finished_at' => now()])->save();
    }

    /**
     * Partial success is success.
     *
     * A run is only `failed` when NOTHING succeeded — the same rule the engine applies to its own
     * multi-target path, so one flaky area never reads as a broken run.
     */
    private function finish(DiscoveryRun $run): void
    {
        $run->refresh();
        $everythingFailed = (int) $run->targets_completed === 0 && (int) $run->targets_failed > 0;

        $run->forceFill([
            'status' => $everythingFailed ? DiscoveryRun::STATUS_FAILED : DiscoveryRun::STATUS_COMPLETED,
            'phase' => null,
            'finished_at' => now(),
            'error_message' => $everythingFailed
                ? 'Every search in this run failed. See the individual searches for the reason.'
                : null,
        ])->save();
    }

    private function fail(DiscoveryRun $run, string $message): void
    {
        $run->forceFill([
            'status' => DiscoveryRun::STATUS_FAILED,
            'phase' => null,
            'error_message' => $message,
            'finished_at' => now(),
        ])->save();
    }

    // ── NATIONWIDE ───────────────────────────────────────────────────────────────────────────
    //
    // The scoped path above and this one share everything that touches money or data — the same
    // process runner, the same per-task recording, the same cancellation check, and the same ingest
    // at the far end. What differs is only WHERE the work comes from: a scoped run is handed its
    // searches, a nationwide run derives them from the national polygon and then from its own
    // results.

    /**
     * Execute a nationwide run: tile the country, then search every cell until the PROVIDER runs out.
     *
     * THERE IS NO QUOTA HERE, AND THAT IS THE POINT. Nothing counts toward a per-city target, because
     * there is no per-city target: the run asks the provider for everything it will give, cell by
     * cell, and stops when the provider stops answering or the safety budget ends it. The vendored
     * city anchors label cells so a human can read the progress; they do not appear in any decision
     * about where to look, how deep to go, or when to stop.
     *
     * THE STOPPING RULE, IN FULL — there are exactly four ways this run ends:
     *
     *   1. THE QUEUE EMPTIES. Every cell was searched and none of them had more to give. This is
     *      provider-driven exhaustion and the only ending that means "the country has been covered".
     *   2. THE BUDGET RUNS OUT. A spending fence, not a discovery boundary. Recorded, and reported as
     *      the reason, so a short result is never mistaken for a fact about the Philippines.
     *   3. CANCELLATION, between searches.
     *   4. THE PROVIDER FAILS in a way that makes continuing pointless (a rejected credential).
     *
     * A CELL ITSELF stops for one of two reasons, and they are recorded separately because they mean
     * opposite things: it came back with FEWER results than it was allowed (exhausted — there is
     * nothing more there), or it came back FULL and could not be split any finer (capped — there IS
     * more and this run did not reach it).
     *
     * GOOGLE IS REQUIRED, NOT PREFERRED. A nationwide sweep on the OpenStreetMap baseline would mean
     * thousands of Overpass queries against a free, shared, community-funded endpoint — abuse of
     * someone else's infrastructure, not a graceful fallback. The scoped path's degrade-and-carry-on
     * is right for a handful of user-named searches and wrong at this scale.
     */
    private function executeNationwide(DiscoveryRun $run, NerbyProvider $provider, string $metricsPath): void
    {
        if ($provider !== NerbyProvider::GOOGLE_PLACES) {
            $this->fail(
                $run,
                'Nationwide discovery needs Google Places as the active provider. '
                .'It is currently running on '.$provider->value.', which is not configured for a '
                .'search of this size. Add and enable a Google Places key in Settings, then start '
                .'the run again.',
            );

            return;
        }

        $run->forceFill(['phase' => 'planning'])->save();

        // THE PLAN IS COMPUTED HERE, ON THE QUEUE, not in the HTTP request that asked for it. Tiling
        // the national polygon means thousands of geometric containment tests, far too much to hold a
        // browser connection open for — and the number of cells is not knowable any other way,
        // because it falls out of the geometry rather than being typed in.
        $scope = (array) $run->scope;
        $cellDegrees = (float) ($scope['cellDegrees'] ?? config('discovery.nationwide.cell_degrees', 0.25));
        $cells = $this->planner->plan(
            $this->nichesOf($run),
            $cellDegrees,
            // The plan is the CELL list; niches multiply within each cell at execution time, so the
            // ceiling passed here bounds cells, not calls. The budget bounds calls.
            PHP_INT_MAX,
        );

        // `plan()` returns (cell x niche) tasks interleaved by island group; the distinct cells in
        // that order are the queue. Taking them from the plan keeps ONE definition of coverage and
        // of ordering, rather than a second traversal that could drift from it.
        $queue = [];
        $seen = [];
        foreach ($cells as $task) {
            $key = implode(',', $task['bbox']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $queue[] = [
                'bbox' => $task['bbox'],
                'group' => $task['group'],
                'label' => $task['area'],
                'depth' => 0,
                'parent' => null,
            ];
        }

        if ($queue === []) {
            $this->fail($run, 'The national boundary produced no searchable cells. Nationwide discovery cannot run.');

            return;
        }

        $run->forceFill([
            'phase' => 'discovering',
            'cells_planned' => count($queue),
        ])->save();

        $this->workQueue($run, $queue, $provider, $metricsPath);
        $this->finishNationwide($run);
    }

    /**
     * Search cells until the provider is exhausted, the budget ends, or the run is cancelled.
     *
     * THE DESCENT, AND THE OBSERVATION THAT DRIVES IT. A cell that returns exactly the limit it was
     * given has not been exhausted — it has been TRUNCATED, and whatever lies beyond the cut is
     * invisible at that resolution. That is the only honest signal available for "there is more
     * here", and it is the only thing that causes a subdivision. A cell that comes back short is
     * finished: splitting it would re-find the same businesses in quarters and spend four requests to
     * learn nothing.
     *
     * DUPLICATES ARE NOT A STOPPING SIGNAL. A cell full of businesses the CRM already knows is still
     * a full cell, so it still subdivides — the ingest deduplicates, and coverage is about ground
     * searched, not rows inserted. Nothing here invents a business when the provider has no more.
     *
     * @param  list<array{bbox: array{0: float, 1: float, 2: float, 3: float}, group: string, label: string, depth: int, parent: ?int}>  $queue
     */
    private function workQueue(DiscoveryRun $run, array $queue, NerbyProvider $provider, string $metricsPath): void
    {
        $niches = $this->nichesOf($run);
        $maxDepth = max(0, (int) config('discovery.nationwide.max_depth', 2));
        $limit = (int) (($run->scope)['limitPerTask'] ?? config('discovery.nationwide.limit_per_task', 20));

        while ($queue !== []) {
            if ($this->isCancelled($run)) {
                $this->skipRemaining($run);

                return;
            }
            if ($this->budgetSpent($run)) {
                return;
            }

            $cell = array_shift($queue);
            $truncated = false;
            $parentForChildren = null;

            foreach ($niches as $niche) {
                if ($this->budgetSpent($run)) {
                    break;
                }

                $target = $run->targets()->create([
                    'area' => $cell['label'],
                    'category' => $niche,
                    'limit_per_task' => $limit,
                    'status' => DiscoveryRunTarget::STATUS_PENDING,
                    'bbox_south' => $cell['bbox'][0],
                    'bbox_west' => $cell['bbox'][1],
                    'bbox_north' => $cell['bbox'][2],
                    'bbox_east' => $cell['bbox'][3],
                    'island_group' => $cell['group'],
                    'depth' => $cell['depth'],
                    'parent_id' => $cell['parent'],
                ]);
                $run->increment('targets_total');
                $run->increment('budget_used');

                $result = $this->runBoundedTarget($run, $target, $provider, $metricsPath, $cell['bbox']);

                // The provider filled the page: there is more in this box than it was willing to say.
                if ($result['discovered'] >= $limit) {
                    $truncated = true;
                }
                $parentForChildren ??= (int) $target->id;
            }

            $run->increment('cells_searched');
            if ($cell['depth'] === 0) {
                $run->increment('cells_first_pass_done');
            }

            if (! $truncated) {
                // The provider had less than it was allowed to give. Nothing more is here.
                $run->increment('cells_exhausted');

                continue;
            }

            $children = $cell['depth'] < $maxDepth ? $this->planner->subdivide($cell['bbox']) : [];
            if ($children === []) {
                // Still full, but the descent limit or the geometric floor stopped the split. This
                // cell is CAPPED, not exhausted: there is more here and this run did not reach it.
                $run->increment('cells_capped');

                continue;
            }

            $run->increment('cells_subdivided');
            foreach ($children as $child) {
                // Children go to the FRONT, so a dense area is finished before the sweep moves on.
                // Appending instead would push every subdivision behind the entire rest of the
                // country, and a budget-limited run would then only ever search at the coarsest
                // resolution — exactly the shallow coverage the descent exists to avoid.
                array_unshift($queue, [
                    'bbox' => $child,
                    'group' => $cell['group'],
                    'label' => $cell['label'],
                    'depth' => $cell['depth'] + 1,
                    'parent' => $parentForChildren,
                ]);
            }
        }
    }

    /**
     * One bounded task, run and recorded.
     *
     * Separate from {@see runTarget()} because the degradation rule is genuinely different: the
     * scoped path drops to the baseline and carries on, which is right for a few user-named searches
     * and wrong for a national sweep (see {@see executeNationwide()}). A nationwide run that loses its
     * credential records the task honestly and lets the run's own accounting report the failures —
     * it never silently continues on a different provider.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return array{discovered: int, inserted: int, duplicates: int, rejected: int}
     */
    private function runBoundedTarget(
        DiscoveryRun $run,
        DiscoveryRunTarget $target,
        NerbyProvider $provider,
        string $metricsPath,
        array $bbox,
    ): array {
        $target->forceFill([
            'status' => DiscoveryRunTarget::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        $result = $this->runner->run(
            $target->area,
            $target->category,
            (int) $target->limit_per_task,
            $provider->value,
            $metricsPath,
            $bbox,
            $this->countryOf($run),
        );

        $this->recordTarget($run, $target, $provider, $result);

        return [
            'discovered' => $result['discovered'],
            'inserted' => $result['inserted'],
            'duplicates' => $result['duplicates'],
            'rejected' => $result['rejected'],
        ];
    }

    /** Has this run spent the calls it was allowed? Read from the database, never from a local tally. */
    private function budgetSpent(DiscoveryRun $run): bool
    {
        $run->refresh();

        return (int) $run->budget_used >= (int) $run->budget_total;
    }

    /**
     * A nationwide run's free-text niches. No whitelist, no canonicalization — exactly as typed.
     *
     * @return list<string>
     */
    private function nichesOf(DiscoveryRun $run): array
    {
        $niches = (array) (($run->scope)['niches'] ?? []);

        return array_values(array_filter($niches, static fn ($n): bool => is_string($n) && trim($n) !== ''));
    }

    /** The country this run is restricted to. Travels to the engine, which fences its geocoder with it. */
    private function countryOf(DiscoveryRun $run): string
    {
        $country = ($run->scope)['country'] ?? null;

        return is_string($country) && $country !== ''
            ? $country
            : (string) config('discovery.default_country', 'PH');
    }

    /**
     * A nationwide run fails only when nothing worked at all.
     *
     * FINDING FEW BUSINESSES IS NOT A FAILURE, and must never be drawn as one. A cell with three
     * pharmacies has been searched correctly; a run that covered the country and found what the
     * provider had has done its job and reported the Philippines as Google knows it. Only a run where
     * every single search failed — a dead credential, an unreachable engine — is a failure.
     */
    private function finishNationwide(DiscoveryRun $run): void
    {
        $run->refresh();

        // A RUN THAT IS ALREADY OVER STAYS OVER. Cancellation is recorded by the controller the
        // moment a user asks for it, and the queue only notices between searches — so the worker
        // reaches this method with the run already terminal. Writing `completed` here would tell a
        // user their cancelled sweep had finished normally, and would erase the one state that says
        // the rest of the country was never searched.
        if ($run->isTerminal()) {
            return;
        }

        $everythingFailed = (int) $run->targets_completed === 0 && (int) $run->targets_failed > 0;

        $run->forceFill([
            'status' => $everythingFailed ? DiscoveryRun::STATUS_FAILED : DiscoveryRun::STATUS_COMPLETED,
            'phase' => null,
            'finished_at' => now(),
            'error_message' => $everythingFailed
                ? 'Every search in this run failed. See the individual searches for the reason.'
                : null,
        ])->save();
    }

    /** Per-run telemetry, under storage because `/scraper` is mounted read-only. */
    private function metricsPath(DiscoveryRun $run): string
    {
        $dir = storage_path('app/discovery');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.'/'.$run->uuid.'.jsonl';
    }
}
