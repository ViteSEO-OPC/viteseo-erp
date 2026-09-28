<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Cache;

/**
 * WHAT EACH EXECUTED SEARCH ACTUALLY RETURNED — the measurement Phase 3 will need before it can
 * decide anything adaptive.
 *
 * THE GAP THIS CLOSES. The system could say which phrases it ran (the E2 ledger) and how many
 * businesses a run produced (the run targets), but nothing connected the two: no record anywhere
 * said what a given search was WORTH. Saturation, diminishing returns and adaptive strategy are all
 * judgements about yield over time, and none of them can be made from data that was never kept.
 *
 * THE UNIT IS AN EXECUTION, NOT A PHRASE, AND THAT IS NOT A SHORTCUT. The provider merges the
 * results of every phrase in one request into a single set keyed by provider id — two phrases that
 * both return the same business produce one entry, deliberately, because that is what
 * de-duplication means. So the count that comes back belongs to the phrase SET, and splitting it
 * between the phrases would be inventing a number the provider never reported.
 *
 * TRUE PER-PHRASE YIELD THEREFORE EXISTS ONLY WHEN ONE PHRASE RAN, and each record says so in
 * `attributable` rather than leaving a reader to work it out. A measurement that quietly implies
 * more precision than it has is worse than one that admits its own shape.
 *
 * IT RECORDS COUNTS, NEVER RECORDS. The phrases are text this CRM dispatched; everything else is an
 * integer. No business name, provider id, place id, coordinate, address, phone number or website is
 * stored, and none could be — the only things written are the outgoing request's phrases and the
 * SIZE of the response.
 *
 * IT IS NOT WIRED TO THE MODEL. Nothing here reaches a prompt. Feeding yield back into strategy is
 * adaptive behaviour and belongs to a later, separately reviewed slice; this one only starts
 * keeping the evidence.
 *
 * ORG-SCOPED, like the ledger beside it: yield is a fact about one organization's searching.
 */
class QueryYieldLog
{
    /**
     * How many executions are kept per target, oldest first.
     *
     * Enough to see a trend across repeated runs of the same target — which is the question Phase 3
     * will ask — without letting an observability record grow without bound.
     */
    public const MAX_ENTRIES = 50;

    /**
     * WHERE A MEASUREMENT CAME FROM. An execution is what a production search returned; a probe is
     * what one phrase reaches when asked on its own. Entries written before probing existed carry
     * no source and are read as executions, which is what they were.
     */
    public const SOURCE_EXECUTION = 'execution';

    public const SOURCE_PROBE = 'probe';

    private const PREFIX = 'crm:discovery:query-yield:';

    /**
     * Record one executed search.
     *
     * @param  list<string>  $phrases  the phrases that ACTUALLY ran, from {@see ExecutedQueryLedger::executed()}
     * @param  int  $returned  how many businesses the provider returned for that phrase set
     * @param  int  $pages  provider pages fetched, which is what the search cost
     */
    public function record(
        string $category,
        string $area,
        string $provider,
        array $phrases,
        int $returned,
        int $pages,
    ): void {
        // A search that ran no phrase measured nothing. Recording a zero here would put a
        // meaningless point into a series Phase 3 is meant to read a trend from.
        if ($phrases === []) {
            return;
        }

        $key = $this->key($category, $area);
        $entries = $this->read($key);

        $entries[] = [
            'at' => now()->toIso8601String(),
            'provider' => $provider,
            'source' => self::SOURCE_EXECUTION,
            'phrases' => array_values($phrases),
            // THE HONESTY FIELD. True only when exactly one phrase ran, which is the only case where
            // `returned` is that phrase's own yield rather than a set's.
            'attributable' => count($phrases) === 1,
            'returned' => max(0, $returned),
            'pages' => max(0, $pages),
        ];

        Cache::forever($key, array_slice($entries, -self::MAX_ENTRIES));
    }

    /**
     * Record one MEASUREMENT of a single phrase, taken by the ids-only probe.
     *
     * Kept in the same series as executions so Phase 3.3 reads one history, and tagged `source` so
     * the two are never silently averaged: an execution is what a production search returned, a
     * probe is what a phrase reaches when asked on its own. Both are true; they answer different
     * questions.
     *
     * A probe is always one phrase, so it is always attributable — that is the point of it.
     *
     * @param  int  $returned  how many distinct identifiers the phrase reached
     * @param  int  $novel  how many of those the CRM does not already hold
     * @param  bool  $capped  whether the page came back full, making `returned` a floor
     */
    public function recordProbe(
        string $category,
        string $area,
        string $phrase,
        int $returned,
        int $novel,
        bool $capped,
    ): void {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return;
        }

        $key = $this->key($category, $area);
        $entries = $this->read($key);

        $entries[] = [
            'at' => now()->toIso8601String(),
            'provider' => 'google_places',
            'source' => self::SOURCE_PROBE,
            'phrases' => [$phrase],
            'attributable' => true,
            'returned' => max(0, $returned),
            // NEW to this CRM, which is the number saturation is actually about: a phrase can keep
            // returning businesses and still be exhausted, if they are all businesses already held.
            'novel' => max(0, min($novel, max(0, $returned))),
            'capped' => $capped,
            'pages' => 1,
        ];

        Cache::forever($key, array_slice($entries, -self::MAX_ENTRIES));
    }

    /**
     * What probing says about one phrase: how often it was measured, what it reached, and how much
     * of that was new. Null when the phrase has never been probed — never confused with zero.
     *
     * @return array{probes: int, returned: int, novel: int}|null
     */
    public function probedYield(string $category, string $area, string $phrase): ?array
    {
        $target = mb_strtolower(trim($phrase));
        $probes = 0;
        $returned = 0;
        $novel = 0;

        foreach ($this->recent($category, $area) as $entry) {
            if (($entry['source'] ?? self::SOURCE_EXECUTION) !== self::SOURCE_PROBE) {
                continue;
            }
            if (mb_strtolower((string) ($entry['phrases'][0] ?? '')) !== $target) {
                continue;
            }

            $probes++;
            $returned += (int) ($entry['returned'] ?? 0);
            $novel += (int) ($entry['novel'] ?? 0);
        }

        return $probes === 0 ? null : ['probes' => $probes, 'returned' => $returned, 'novel' => $novel];
    }

    /**
     * Executions recorded for this target, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $category, string $area): array
    {
        return $this->read($this->key($category, $area));
    }

    /**
     * Yield for one phrase, counting ONLY executions where that phrase ran alone.
     *
     * A set execution is deliberately excluded rather than divided: the provider reported one number
     * for several phrases, and apportioning it would manufacture precision that was never measured.
     * Null means the phrase has never run by itself, which is a different answer from "it found
     * nothing" and must not be collapsed into zero.
     *
     * @return array{executions: int, returned: int}|null
     */
    public function attributableYield(string $category, string $area, string $phrase): ?array
    {
        $target = mb_strtolower(trim($phrase));
        $executions = 0;
        $returned = 0;

        foreach ($this->recent($category, $area) as $entry) {
            // EXECUTIONS ONLY. A probe is also attributable, but it measures a different thing —
            // mixing the two would average "what this search returned in production" with "what
            // this phrase reaches alone" into a number that answers neither question.
            if (($entry['source'] ?? self::SOURCE_EXECUTION) !== self::SOURCE_EXECUTION) {
                continue;
            }
            if (($entry['attributable'] ?? false) !== true) {
                continue;
            }
            if (mb_strtolower((string) ($entry['phrases'][0] ?? '')) !== $target) {
                continue;
            }

            $executions++;
            $returned += (int) ($entry['returned'] ?? 0);
        }

        return $executions === 0 ? null : ['executions' => $executions, 'returned' => $returned];
    }

    /**
     * The MOST RECENT probe of one phrase, or null if it has never been probed.
     *
     * DELIBERATELY THE LATEST, NOT A SUM. A phrase that was productive last month and returns
     * nothing new today is exhausted NOW, and that is the fact a strategy decision needs. Summing
     * its history would blend a productive past into an exhausted present and read as healthy.
     *
     * @return array<string, mixed>|null
     */
    public function latestProbe(string $category, string $area, string $phrase): ?array
    {
        $target = mb_strtolower(trim($phrase));
        $latest = null;

        foreach ($this->recent($category, $area) as $entry) {
            if (($entry['source'] ?? self::SOURCE_EXECUTION) !== self::SOURCE_PROBE) {
                continue;
            }
            if (mb_strtolower((string) ($entry['phrases'][0] ?? '')) !== $target) {
                continue;
            }

            // Entries are appended, so the last match wins.
            $latest = $entry;
        }

        return $latest;
    }

    /**
     * Every phrase this target has a probe measurement for, oldest measurement first.
     *
     * @return list<string>
     */
    public function probedPhrases(string $category, string $area): array
    {
        $phrases = [];

        foreach ($this->recent($category, $area) as $entry) {
            if (($entry['source'] ?? self::SOURCE_EXECUTION) !== self::SOURCE_PROBE) {
                continue;
            }

            $phrase = trim((string) ($entry['phrases'][0] ?? ''));
            if ($phrase === '') {
                continue;
            }

            // Keyed so a phrase probed repeatedly appears once, at its first appearance.
            $phrases[mb_strtolower($phrase)] ??= $phrase;
        }

        return array_values($phrases);
    }

    /** Drop a target's measurements. */
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
