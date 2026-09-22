<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Cache;

/**
 * WHEN THE MODEL WAS ASKED AND HAD NOTHING USABLE TO SAY.
 *
 * THE GAP THIS CLOSES. A search that ran without an AI phrase and a search AI was never consulted
 * for were indistinguishable everywhere downstream. {@see QueryYieldLog::record()} returns early on
 * an empty phrase list, {@see GooglePlacesTextSearch} falls back to the category-derived phrase so a
 * plan of nothing can never become zero searches, and the run's own `ai_outcome` only ever rises —
 * one search shaped by AI leaves the whole run reading `applied`. Nothing in that chain is wrong,
 * but together they mean "AI is exhausted here" looks exactly like "AI is switched off", and the
 * difference is the entire signal for whether the intelligence layer is still contributing.
 *
 * IT RECORDS A REFUSAL TO PLAN, NEVER A SEARCH. No phrase is invented here and none is suppressed:
 * this is written after a plan comes back empty, and it changes nothing about what is then searched.
 * A reader of this log learns that the model was consulted for a target and produced no query it
 * could use — and, through `covered`, how much history it was working against at the time, which is
 * what distinguishes an exhausted target from a model that simply answered poorly once.
 *
 * IT IS DELIBERATELY NOT THE YIELD SERIES. {@see QueryYieldLog} answers "what did an executed search
 * return", and {@see QueryFeedback} reads that series to judge phrases. An entry that measures no
 * search would be a foreign value in both, and would push real measurements out of a capped series.
 * Separate key, separate cap, no existing reader affected.
 *
 * COUNTS AND CATEGORIES ONLY. The category and area are the target the CRM itself dispatched; every
 * other field is an integer, a status word or a timestamp. No business name, identifier, coordinate
 * or provider record can enter here, because none of them is in scope at the moment a plan is judged
 * empty — the plan is all there is.
 *
 * Org-scoped like the ledger and the yield log beside it: exhaustion is a fact about one
 * organization's searching.
 */
class StrategyOutcomeLog
{
    /**
     * How many outcomes are kept per target, oldest first.
     *
     * Matches {@see QueryYieldLog::MAX_ENTRIES} so the two series cover the same span of history and
     * can be read side by side without one silently outliving the other.
     */
    public const MAX_ENTRIES = 50;

    /** The model answered, and the answer contained no query this search could run. */
    public const REASON_EMPTY_PLAN = 'empty_plan';

    /** A fresh call to the model produced this outcome. */
    public const SOURCE_LIVE = 'live';

    /**
     * A permanently cached plan produced it.
     *
     * Recorded as well as the live case, and marked apart from it, because the two are the same fact
     * for a RUN — this target got no usable phrase — and different facts for COST. Collapsing them
     * would make a saturated target look busy; dropping the cached one would hide most of the
     * saturation, since a repeat of the same question never reaches the model again.
     */
    public const SOURCE_CACHE = 'cache';

    private const PREFIX = 'crm:discovery:strategy-outcome:';

    /**
     * Record one consultation that produced no usable query.
     *
     * @param  list<string>  $dialects  the output shapes that were requested, from the strategist
     * @param  int  $covered  how many phrases the ledger already held for this target
     */
    public function recordEmptyPlan(
        string $category,
        string $area,
        string $source,
        array $dialects,
        int $covered,
    ): void {
        $key = $this->key($category, $area);
        $entries = $this->read($key);

        $entries[] = [
            'at' => now()->toIso8601String(),
            'reason' => self::REASON_EMPTY_PLAN,
            'source' => $source,
            'dialects' => array_values(array_filter($dialects, 'is_string')),
            // THE SATURATION SIGNAL. A target whose ledger is full and whose plan is empty has run
            // out of phrasing; one with a near-empty ledger and an empty plan has not, and the two
            // call for opposite responses. Without this number they read identically.
            'covered' => max(0, $covered),
        ];

        Cache::forever($key, array_slice($entries, -self::MAX_ENTRIES));
    }

    /**
     * Outcomes recorded for this target, oldest first. Empty is the honest answer for a target that
     * has never been refused a plan.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $category, string $area): array
    {
        return $this->read($this->key($category, $area));
    }

    /** Drop a target's outcomes, so it reads as one that has never been refused a plan. */
    public function forget(string $category, string $area): void
    {
        Cache::forget($this->key($category, $area));
    }

    /** @return list<array<string, mixed>> */
    private function read(string $key): array
    {
        $stored = Cache::get($key);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    private function key(string $category, string $area): string
    {
        return self::PREFIX
            .(CurrentOrg::id() ?? 0).'|'
            .mb_strtolower(trim($category)).'|'
            .mb_strtolower(trim($area));
    }
}
