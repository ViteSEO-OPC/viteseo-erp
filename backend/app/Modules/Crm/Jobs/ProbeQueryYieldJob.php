<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Discovery\GooglePlacesIdProbe;
use App\Modules\Crm\Services\Discovery\QueryFeedback;
use App\Modules\Crm\Services\Discovery\QueryYieldLog;
use App\Support\CurrentOrg;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MEASURES THE PHRASES A FRESH PLAN PROPOSED, off the path that produced it.
 *
 * WHY A JOB. Probing spends money and takes a network round trip per phrase. Doing it inside the
 * strategy request would make an operator wait for measurement they did not ask for, and would put
 * a paid call inside a request whose job is to return advice. Queued, the strategy endpoint answers
 * exactly as fast as it did before and the measurement happens behind it.
 *
 * WHEN IT RUNS — AND WHY THAT IS THE SAMPLING POLICY. It is dispatched only when the strategist
 * produced a FRESH plan, never on a cache hit. A cached plan means the question has not changed, so
 * neither have its phrases, so there is nothing new to measure. That single fact is what makes the
 * policy "one probe per phrase per strategy cycle" rather than "one per run": a cycle is exactly the
 * life of one cached plan.
 *
 * A second guard sits underneath it, because two concurrent strategy calls can both miss the cache:
 * each (phrase, target, cycle) is claimed atomically before it is measured, so a phrase is probed
 * once per cycle even under a race.
 *
 * IT CANNOT AFFECT DISCOVERY. It reads a lead's `place_id` to count novelty and writes two integers
 * to an observability log. It creates nothing, updates nothing, ingests nothing, and never touches
 * the production search.
 */
class ProbeQueryYieldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<string>  $phrases  the plan's phrases, in the order the model ranked them
     * @param  string  $cycle  identity of the plan these phrases came from
     */
    public function __construct(
        private readonly int $orgId,
        private readonly string $category,
        private readonly string $area,
        private readonly array $phrases,
        private readonly string $cycle,
        private readonly ?string $includedType = null,
    ) {}

    public function handle(GooglePlacesIdProbe $probe, QueryYieldLog $log): void
    {
        // A queued job has no authenticated user, and the ledger and the log are org-scoped: without
        // this the measurement would be written under the wrong key, or none.
        CurrentOrg::runAs($this->orgId, function () use ($probe, $log): void {
            if (! config('discovery.probe.enabled')) {
                return;
            }

            foreach ($this->selectPhrases(app(QueryFeedback::class)) as $phrase) {
                if (! $this->claim($phrase)) {
                    continue;
                }

                $this->measure($probe, $log, (string) $phrase);
            }
        });
    }

    /**
     * WHICH PHRASES TO SPEND THE BUDGET ON — two exploiting, one exploring.
     *
     * THE RATCHET THIS EXISTS TO BREAK. Measuring only the top of the ranking means phrases ranked
     * below the budget are never measured, can never earn promotion, and the feedback loop can only
     * ever confirm the order the model guessed first. Reserving one slot for something UNMEASURED
     * from further down is what keeps the loop a loop rather than a ratchet.
     *
     * It spends nothing extra: the same three requests, allocated deliberately instead of by
     * position alone.
     *
     * EXPLOIT (budget minus one) takes, in ranked order:
     *   1. phrases already shown to be rewarding — `productive` or `abundant`, where measuring again
     *      tracks whether ground is running out;
     *   2. then unmeasured phrases from the front, which is what the old behaviour did.
     *   Never `barren` or `no_new`: measurement has already answered those, and re-asking buys a
     *   known answer. (Whether an exhausted phrase RECOVERS is a saturation question, not this one.)
     *
     * EXPLORE (one slot) takes the first unmeasured phrase not already chosen — necessarily from
     * further down the ranking, since the front was taken first. If every phrase is already
     * measured the slot goes UNUSED rather than being spent on a `barren` or `no_new` phrase: an
     * unnecessary request is a real cost, and declining to spend it is the correct answer.
     *
     * @return list<string>
     */
    private function selectPhrases(QueryFeedback $feedback): array
    {
        $budget = max(0, (int) config('discovery.probe.max_phrases_per_cycle', 3));
        if ($budget === 0) {
            return [];
        }

        // One slot is reserved for exploration whenever there is more than one to give.
        $exploreSlots = $budget > 1 ? 1 : 0;
        $exploitSlots = $budget - $exploreSlots;

        $verdicts = [];
        foreach ($this->phrases as $phrase) {
            if (is_string($phrase) && trim($phrase) !== '') {
                $verdicts[trim($phrase)] = $feedback->verdict($this->category, $this->area, trim($phrase));
            }
        }

        $rewarding = [];
        $unmeasured = [];
        foreach ($verdicts as $phrase => $verdict) {
            if (in_array($verdict, QueryFeedback::REWARDING, true)) {
                $rewarding[] = (string) $phrase;
            } elseif ($verdict === QueryFeedback::UNMEASURED) {
                $unmeasured[] = (string) $phrase;
            }
        }

        // Ranked order is preserved throughout: these lists are built by walking $this->phrases.
        $exploit = array_slice([...$rewarding, ...$unmeasured], 0, $exploitSlots);
        $explore = array_slice(array_values(array_diff($unmeasured, $exploit)), 0, $exploreSlots);

        return array_values(array_unique([...$exploit, ...$explore]));
    }

    /**
     * Claim this phrase for this cycle, atomically.
     *
     * `Cache::add` writes only when the key is absent, so two workers racing on the same cycle
     * cannot both pay for the same measurement. The claim outlives the cycle it belongs to rather
     * than expiring on a timer, because a cycle ends when the plan changes, not when a clock does.
     */
    private function claim(string $phrase): bool
    {
        $key = 'crm:discovery:probe-claim:'
            .(CurrentOrg::id() ?? 0).'|'
            .$this->cycle.'|'
            .mb_strtolower(trim($this->category)).'|'
            .mb_strtolower(trim($this->area)).'|'
            .mb_strtolower(trim($phrase));

        return Cache::add($key, true);
    }

    private function measure(GooglePlacesIdProbe $probe, QueryYieldLog $log, string $phrase): void
    {
        try {
            $result = $probe->measure($phrase, $this->area, $this->includedType);
        } catch (Throwable $e) {
            // Measurement is optional by construction: it must never fail a queue worker or retry
            // into a repeated spend. The class only — a message could quote the request.
            Log::warning('Query yield probe errored', ['class' => $e::class]);

            return;
        }

        // A FAILED PROBE MEASURED NOTHING. Recording a zero would put "this phrase finds nothing"
        // into the series when the truth is "we did not find out", and a later saturation decision
        // would read the two identically.
        if ($result['status'] !== GooglePlacesIdProbe::STATUS_OK) {
            Log::info('Query yield probe produced no measurement', ['status' => $result['status']]);

            return;
        }

        $ids = $result['ids'];

        $log->recordProbe(
            $this->category,
            $this->area,
            $phrase,
            count($ids),
            $this->novelty($ids),
            (bool) $result['capped'],
        );
    }

    /**
     * How many of these identifiers the CRM does not already hold.
     *
     * THE NUMBER SATURATION IS ACTUALLY ABOUT. A phrase can keep returning twenty businesses forever
     * and still be exhausted, if they are the same twenty the pool already has. Only the new ones
     * represent ground a search has left to cover.
     *
     * Compared in the form the CRM stores — `places/<id>`, the same shape the discovery path writes
     * — so a match here means the same business, not a similar string. The identifiers are counted
     * and discarded; nothing about them is persisted.
     *
     * @param  list<string>  $ids  bare Google identifiers
     */
    private function novelty(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $qualified = array_map(fn (string $id): string => GooglePlacesIdProbe::ID_PREFIX.$id, $ids);

        // Org isolation comes from the model's own global scope: a lead belonging to another
        // organization is not visible here and so can never mark a phrase as already covered.
        $held = Lead::query()->whereIn('place_id', $qualified)->count();

        return max(0, count($qualified) - $held);
    }
}
