<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\DiscoveryRunTarget;
use App\Modules\Crm\Services\Discovery\Geo\NationwidePlanner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

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
        private readonly NerbyServiceIdentity $service,
    ) {}

    /**
     * The org-bound service token this run's engine calls back with. Set for the duration of
     * {@see execute()} only; see NerbyServiceIdentity.
     */
    private ?string $crmToken = null;

    public function execute(string $runUuid): void
    {
        $run = DiscoveryRun::query()->where('uuid', $runUuid)->first();

        // Already finished, already cancelled, or gone. Re-entry must be harmless: a worker restart
        // must never re-spend at a paid provider. A run cancelled BETWEEN nationwide slices arrives
        // here too, and leaves no checkpoint behind.
        if ($run === null) {
            return;
        }
        if ($run->isTerminal()) {
            $this->clearCheckpoint($run);

            return;
        }

        // ONE SLICE OF A RUN AT A TIME. A duplicated or retried job must never search the same run
        // concurrently — it would double-spend and race the checkpoint. The holder continues the run;
        // a second job simply stands down. The TTL outlives a job so a crashed slice cannot wedge it.
        $lock = Cache::lock('crm:discovery-run:'.$run->uuid, max(60, (int) config('discovery.nationwide.lock_seconds', 1200)));
        if (! $lock->get()) {
            return;
        }

        $continue = false;
        try {
            $continue = $this->executeWithToken($run);
        } finally {
            $lock->release();
        }

        if ($continue) {
            // Dispatched only AFTER the lock is released, so the next slice can never find it held
            // by the slice that queued it.
            $run->increment('continuations');
            RunDiscoveryJob::dispatch($run->uuid, (int) $run->org_id)->onQueue('discovery');
        }
    }

    /** @return bool true when a nationwide run stopped at its slice deadline and must continue */
    private function executeWithToken(DiscoveryRun $run): bool
    {
        // ONE SHORT-LIVED TOKEN PER RUN, BOUND TO THE RUN'S OWN ORG. Every callback the engine makes
        // for this run — strategy, places, ingest — therefore lands in the tenant that started it,
        // never in whichever org a global identity happens to fall back to. Revoked in `finally`, so
        // a crashed run leaves no live credential behind; its expiry is the backstop.
        $issued = $this->service->issue(
            (int) $run->org_id,
            'run:'.$run->uuid,
            now()->addSeconds(max(60, (int) config('discovery.service_account.run_token_ttl', 7200))),
        );
        $this->crmToken = $issued->plainTextToken;

        try {
            return $this->executeRun($run);
        } finally {
            $this->crmToken = null;
            $this->service->revoke($issued);
        }
    }

    private function executeRun(DiscoveryRun $run): bool
    {
        $metricsPath = $this->metricsPath($run);

        // A NATIONWIDE CONTINUATION RESUMES; IT DOES NOT RESTART. The provider snapshot, start time
        // and counters were set by the first slice and are kept exactly — re-running beginRun() would
        // re-resolve the provider mid-sweep and reset what the user is watching.
        if ($run->isNationwide() && $this->hasCheckpoint($run)) {
            $provider = NerbyProvider::tryFrom((string) $run->provider_effective) ?? NerbyProvider::default();

            return $this->executeNationwide($run, $provider, $metricsPath);
        }

        $effective = $this->beginRun($run);

        // NATIONWIDE IS A DIFFERENT SHAPE OF RUN, not a longer one. A scoped run knows every search
        // it will make before it makes any of them; a nationwide run discovers its own work as it
        // goes, because whether a cell needs subdividing is only knowable once that cell has
        // answered. Branching here keeps the scoped loop below byte-for-byte what it was.
        if ($run->isNationwide()) {
            return $this->executeNationwide($run, $effective, $metricsPath);
        }

        foreach ($run->targets()->orderBy('id')->get() as $target) {
            // Cancellation is cooperative and checked between tasks. It can stop the NEXT call; it
            // cannot un-spend one already made.
            if ($this->isCancelled($run)) {
                $this->skipRemaining($run);

                return false;
            }
            if ($this->aiCreditExhausted($run)) {
                $this->stopForAiCredit($run);

                return false;
            }

            $effective = $this->runTarget($run, $target, $effective, $metricsPath);

            // THE BUDGET IS GONE. Stop the run rather than march every remaining target into the
            // same refusal: each would be recorded as a failed search, which is true and useless,
            // and the run would report dozens of failures where there is one cause.
            if ($this->spendLimited($target)) {
                $this->stopForSpendLimit($run);

                return false;
            }
        }

        // The LAST search can be the one that learns the account cannot pay; the run still must not
        // finish as a normal completion under an AI request.
        if ($this->aiCreditExhausted($run)) {
            $this->stopForAiCredit($run);

            return false;
        }

        $this->finish($run);

        return false;
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

        $result = $this->withRateLimitRetry($run, fn (): array => $this->runner->run(
            $target->area,
            $target->category,
            (int) $target->limit_per_task,
            $provider->value,
            $metricsPath,
            null,
            $country,
            $this->aiFor($run, $provider),
            $this->crmToken,
        ));

        // A present-but-rejected credential is only knowable at this point. Degrade once, record it,
        // and give this task a second chance on the baseline so the work is not simply lost.
        if ($this->shouldDegrade($run, $provider, $result)) {
            $this->degrade($run, (string) ($result['executionStatus'] ?? 'auth_failed'));
            $provider = NerbyProvider::default();

            $result = $this->withRateLimitRetry($run, fn (): array => $this->runner->run(
                $target->area,
                $target->category,
                (int) $target->limit_per_task,
                $provider->value,
                $metricsPath,
                null,
                $country,
                $this->aiFor($run, $provider),
                $this->crmToken,
            ));
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

        $this->recordAiOutcome($run, $result);
    }

    // ── AI SEARCH PLANNING ───────────────────────────────────────────────────────────────────
    //
    // Opt-in per run, and Google-only: the flag travels to the engine only while the run is on Google
    // Places, so a run that degrades to OpenStreetMap searches exactly as the baseline always has.

    /** Should THIS search open the AI strategy channel? Never on the baseline provider. */
    private function aiFor(DiscoveryRun $run, NerbyProvider $provider): bool
    {
        return (bool) $run->ai_strategy_requested && $provider === NerbyProvider::GOOGLE_PLACES;
    }

    /**
     * Fold one search's AI report into the run's outcome.
     *
     * RECORDED FROM WHAT THE ENGINE SAID HAPPENED, never from what was asked for. `applied` needs a
     * search that was actually shaped by a returned plan; a request, or a model that answered with
     * nothing to add, is not enough. A credit refusal on a run that asked for AI is sticky and
     * outranks `applied`: once the account cannot pay, the run must not go on reading as AI-planned.
     *
     * @param  array{strategyApplied?: ?bool, strategyStatus?: ?string}  $result
     */
    private function recordAiOutcome(DiscoveryRun $run, array $result): void
    {
        $current = $run->ai_outcome;

        if ($current === DiscoveryRun::AI_INSUFFICIENT_CREDIT) {
            return;
        }

        $next = match (true) {
            $run->ai_strategy_requested
                && ($result['strategyStatus'] ?? null) === DiscoveryRun::AI_INSUFFICIENT_CREDIT => DiscoveryRun::AI_INSUFFICIENT_CREDIT,
            ($result['strategyApplied'] ?? null) === true => DiscoveryRun::AI_APPLIED,
            default => $current ?? DiscoveryRun::AI_NOT_APPLIED,
        };

        if ($next !== $current) {
            $run->forceFill(['ai_outcome' => $next])->save();
        }
    }

    /** A run that asked for AI and has been told the account cannot pay. */
    private function aiCreditExhausted(DiscoveryRun $run): bool
    {
        return (bool) $run->ai_strategy_requested && $run->ai_outcome === DiscoveryRun::AI_INSUFFICIENT_CREDIT;
    }

    /**
     * Did this target end because the configured Google Places spend limit was reached?
     *
     * Read from the PERSISTED target rather than from a return value, so the scoped and nationwide
     * paths ask the identical question of the identical record.
     */
    private function spendLimited(DiscoveryRunTarget $target): bool
    {
        return $target->execution_status === GooglePlacesTextSearch::STATUS_SPEND_LIMITED;
    }

    /**
     * Stop a run that has reached its configured spend limit.
     *
     * FAILED, NOT COMPLETED, AND THAT IS THE POINT. A run that stopped because the money ran out did
     * not finish its work, and reporting it as completed would tell an operator the area was covered
     * when most of it was never searched. It is also not `cancelled` — nobody cancelled it.
     *
     * The message says plainly what happened, that nothing was lost, and what to do — because the
     * fix is a settings change by a super admin, not a retry.
     */
    private function stopForSpendLimit(DiscoveryRun $run): void
    {
        // A run a user already cancelled stays cancelled, and one already stopped is not re-stopped.
        $run->refresh();
        if ($run->isTerminal()) {
            return;
        }

        $this->skipRemaining($run);

        $run->forceFill([
            'status' => DiscoveryRun::STATUS_FAILED,
            'error_message' => 'The configured Google Places spend limit for this period has been reached, '
                .'so the run was stopped before making further paid requests. Any businesses already '
                .'found were saved. Raise or clear the limit in Settings → Integrations to continue.',
        ])->save();

        Log::warning('Discovery run stopped at the Google Places spend limit', ['run' => $run->uuid]);
    }

    /**
     * Stop an AI run whose planning cannot be paid for, rather than let it carry on as plain Google.
     *
     * The user asked for AI-planned searches. Continuing without it would spend Google calls on a run
     * the user did not ask for, and every later search would be a Google-only search under an AI
     * request. The search that discovered the shortfall has already run, and whatever it found is
     * saved — the message says so rather than implying nothing happened.
     */
    private function stopForAiCredit(DiscoveryRun $run): void
    {
        // A run a user already cancelled stays cancelled, and one already stopped is not re-stopped.
        $run->refresh();
        if ($run->isTerminal()) {
            return;
        }

        $this->skipRemaining($run);

        $run->forceFill([
            'status' => DiscoveryRun::STATUS_FAILED,
            'error_message' => 'AI search planning could not run: the AI provider account has no credit remaining. '
                .'The run was stopped so it would not continue as a plain Google search. '
                .'Any businesses already found were saved.',
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
     * GOOGLE IS REQUIRED AT FULL SIZE, NOT AT EVERY SIZE. A nationwide sweep on the OpenStreetMap
     * baseline would mean thousands of Overpass queries against a free, shared, community-funded
     * endpoint — abuse of someone else's infrastructure, not a graceful fallback. But that is a
     * statement about HOW MANY, not about which provider is configured, and reading it as the latter
     * left the AI-assisted path with no way to use the only planner that covers the country. So the
     * fence is the budget: without Google, a run may spend up to
     * `discovery.nationwide.max_tasks_without_google` searches and no more. The scoped path's
     * degrade-and-carry-on is right for a handful of user-named searches and still wrong at scale.
     */
    private function executeNationwide(DiscoveryRun $run, NerbyProvider $provider, string $metricsPath): bool
    {
        // Every slice gets the same deadline, well inside RunDiscoveryJob's timeout: the margin is
        // for the one search (and its rate-limit retries) that may already be in flight when it passes.
        $deadline = now()->addSeconds(max(1, (int) config('discovery.nationwide.slice_seconds', 540)));

        if ($this->hasCheckpoint($run)) {
            return $this->continueNationwide($run, (array) $run->work_queue, $provider, $metricsPath, $deadline);
        }

        $capability = NationwideCapability::for($provider);

        if ($provider !== NerbyProvider::GOOGLE_PLACES && (int) $run->budget_total > $capability->maxTasks) {
            $this->fail(
                $run,
                'Nationwide discovery on '.$provider->value.' is limited to '.$capability->maxTasks
                .' searches per run, because it answers from the free, community-funded Overpass '
                .'endpoint. This run asked for '.(int) $run->budget_total.'. Lower the budget to '
                .$capability->maxTasks.' or fewer, or add and enable a Google Places key in Settings '
                .'to sweep the country at full size.',
            );

            return false;
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

            return false;
        }

        $run->forceFill([
            'phase' => 'discovering',
            'cells_planned' => count($queue),
        ])->save();

        return $this->continueNationwide($run, $queue, $provider, $metricsPath, $deadline);
    }

    /**
     * Work the queue for one slice; checkpoint and ask to continue if the deadline came first.
     *
     * @param  list<array<string, mixed>>  $queue
     * @return bool true when the run must continue in another slice
     */
    private function continueNationwide(
        DiscoveryRun $run,
        array $queue,
        NerbyProvider $provider,
        string $metricsPath,
        CarbonInterface $deadline,
    ): bool {
        $remaining = $this->workQueue($run, $queue, $provider, $metricsPath, $deadline);

        if ($remaining !== null) {
            // THE CHECKPOINT: exactly the cells not yet concluded, including a cell interrupted
            // between two of its niches. Everything already searched is on the run and its targets.
            $run->forceFill(['work_queue' => $remaining])->save();

            return true;
        }

        if ($this->aiCreditExhausted($run)) {
            $this->stopForAiCredit($run);
        }
        $this->finishNationwide($run);
        $this->clearCheckpoint($run);

        return false;
    }

    private function hasCheckpoint(DiscoveryRun $run): bool
    {
        return is_array($run->work_queue) && $run->work_queue !== [];
    }

    private function clearCheckpoint(DiscoveryRun $run): void
    {
        if ($run->work_queue !== null) {
            $run->forceFill(['work_queue' => null])->save();
        }
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
     * SLICED (H-5). Stops at `$deadline` — between searches, never inside one — and hands back the
     * queue still to do, including the cell it was in the middle of and how far through that cell's
     * niches it got. Null means the queue is finished (done, cancelled, budget spent, or stopped for
     * AI credit); a list means "checkpoint this and continue in the next slice". At least one search
     * is made per slice, so a run always progresses whatever the deadline.
     *
     * @param  list<array<string, mixed>>  $queue  cells: bbox, group, label, depth, parent, and for an
     *                                             interrupted cell nichesDone / truncated / searchFailed /
     *                                             parentForChildren
     * @return list<array<string, mixed>>|null
     */
    private function workQueue(
        DiscoveryRun $run,
        array $queue,
        NerbyProvider $provider,
        string $metricsPath,
        CarbonInterface $deadline,
    ): ?array {
        $niches = $this->nichesOf($run);
        $maxDepth = max(0, (int) config('discovery.nationwide.max_depth', 2));
        $limit = (int) (($run->scope)['limitPerTask'] ?? config('discovery.nationwide.limit_per_task', 20));
        $searchesThisSlice = 0;
        $spendLimited = false;

        while ($queue !== []) {
            if ($spendLimited) {
                $this->stopForSpendLimit($run);

                return null;
            }
            if ($this->isCancelled($run)) {
                $this->skipRemaining($run);

                return null;
            }
            if ($this->aiCreditExhausted($run)) {
                $this->stopForAiCredit($run);

                return null;
            }
            if ($this->budgetSpent($run)) {
                return null;
            }
            if ($searchesThisSlice > 0 && now()->greaterThanOrEqualTo($deadline)) {
                return array_values($queue);
            }

            $cell = array_shift($queue);
            // A cell interrupted by the previous slice's deadline resumes exactly where it stopped:
            // same niches already done, same observations so far, same parent for any children.
            $truncated = (bool) ($cell['truncated'] ?? false);
            // A search in this cell that did not run to completion. Its zero is not a fact about the
            // ground, so the cell may not be recorded as exhausted on its strength (H-4).
            $searchFailed = (bool) ($cell['searchFailed'] ?? false);
            $parentForChildren = isset($cell['parentForChildren']) ? (int) $cell['parentForChildren'] : null;
            $startAt = max(0, (int) ($cell['nichesDone'] ?? 0));

            foreach ($niches as $index => $niche) {
                if ($index < $startAt) {
                    continue;
                }
                if ($this->budgetSpent($run) || $this->aiCreditExhausted($run)) {
                    break;
                }
                if ($searchesThisSlice > 0 && now()->greaterThanOrEqualTo($deadline)) {
                    array_unshift($queue, [
                        'bbox' => $cell['bbox'],
                        'group' => $cell['group'],
                        'label' => $cell['label'],
                        'depth' => $cell['depth'],
                        'parent' => $cell['parent'],
                        'nichesDone' => $index,
                        'truncated' => $truncated,
                        'searchFailed' => $searchFailed,
                        'parentForChildren' => $parentForChildren,
                    ]);

                    return array_values($queue);
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
                $searchesThisSlice++;

                // Break rather than return, exactly as the budget and AI-credit checks above do, so
                // this cell's bookkeeping still runs. The refused search leaves `searchFailed` true,
                // and the H-4 guard below therefore refuses to record the cell as exhausted — a
                // budget stop must never be mistaken for "the provider had nothing left here".
                if ($this->spendLimited($target)) {
                    $spendLimited = true;

                    break;
                }

                if (! $result['ok']) {
                    $searchFailed = true;
                }

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

            if (! $truncated && $searchFailed) {
                // NEITHER EXHAUSTED NOR CAPPED: UNKNOWN. A search here was refused (most often for
                // rate) or failed, so "fewer results than allowed" was never observed — it was never
                // asked. Counting it exhausted is how a throttled sweep used to report hundreds of
                // unsearched cells as "the provider had nothing new left". The failed search is on
                // the run's own `targets_failed`, and the cell is simply not concluded.
                continue;
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

        return null;
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
     * @return array{ok: bool, discovered: int, inserted: int, duplicates: int, rejected: int}
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

        $result = $this->withRateLimitRetry($run, fn (): array => $this->runner->run(
            $target->area,
            $target->category,
            (int) $target->limit_per_task,
            $provider->value,
            $metricsPath,
            $bbox,
            $this->countryOf($run),
            $this->aiFor($run, $provider),
            $this->crmToken,
        ));

        $this->recordTarget($run, $target, $provider, $result);

        return [
            // Did this search actually run? A failed one (rate-limited after its retries, provider
            // error, engine refusal) returned zero businesses without looking — see workQueue().
            'ok' => $result['exitCode'] === 0,
            'discovered' => $result['discovered'],
            'inserted' => $result['inserted'],
            'duplicates' => $result['duplicates'],
            'rejected' => $result['rejected'],
        ];
    }

    // ── RATE REFUSALS ────────────────────────────────────────────────────────────────────────
    //
    // A `rate_limited` search (Google's own quota, or the CRM's callback throttle) found nothing
    // because it was never allowed to look. It is retried a bounded number of times with a growing
    // pause, honouring the refusing server's Retry-After when it is longer. Deliberately NOT a
    // provider degradation (a quota is not a bad credential, and OpenStreetMap is not a substitute),
    // and no extra budget is taken: the task was already counted once, and a refusal made no search.

    /**
     * @param  callable(): array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    private function withRateLimitRetry(DiscoveryRun $run, callable $attempt): array
    {
        $result = $attempt();
        $retries = max(0, (int) config('discovery.rate_limit.max_retries', 3));

        for ($i = 0; $i < $retries && $this->wasRateLimited($result); $i++) {
            // A user who cancels during a backoff must not wait it out, nor be charged a retry.
            if ($this->isCancelled($run)) {
                break;
            }

            Sleep::for($this->backoffSeconds($i, $result['retryAfter'] ?? null))->seconds();

            if ($this->isCancelled($run)) {
                break;
            }

            $result = $attempt();
        }

        return $result;
    }

    /** @param  array<string, mixed>  $result */
    private function wasRateLimited(array $result): bool
    {
        return ($result['executionStatus'] ?? null) === GooglePlacesTextSearch::STATUS_RATE_LIMITED;
    }

    /** Exponential from `base_seconds`, at least the server's Retry-After, never above `max_seconds`. */
    private function backoffSeconds(int $retry, ?int $retryAfter): int
    {
        $base = max(1, (int) config('discovery.rate_limit.base_seconds', 10));
        $cap = max($base, (int) config('discovery.rate_limit.max_seconds', 60));
        $wait = $base * (2 ** $retry);

        if ($retryAfter !== null) {
            $wait = max($wait, $retryAfter);
        }

        return min($wait, $cap);
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
