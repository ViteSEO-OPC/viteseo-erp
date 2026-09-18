<?php

namespace App\Modules\Crm\Services\Discovery;

/**
 * WHAT MEASUREMENT SAYS ABOUT A SEARCH PHRASE, reduced to one word the model can act on.
 *
 * FIVE VERDICTS, NOT NUMBERS, AND THAT IS A SAFETY DECISION AS MUCH AS A DESIGN ONE. A raw count
 * invites the model to reason about how many businesses exist in a place — which is a FACTUAL CLAIM
 * ABOUT THE WORLD, the one thing this architecture never lets a model make. A count also implies a
 * precision that a capped measurement does not have. So the model is told what a phrase is worth,
 * never how much it returned.
 *
 * THE DISTINCTION THE WHOLE SLICE RESTS ON. `barren` and `no_new` are both "no new businesses", and
 * they demand OPPOSITE responses:
 *
 *   - `barren`  — the phrase found nothing at all. The WORDING is wrong; abandon it.
 *   - `no_new`  — the phrase found businesses, all of which are already held. The wording WORKS and
 *                 the ground is mined; vary the angle, not the words.
 *
 * Collapsing them into "zero novelty" would teach the model to throw away its best phrasing. They
 * are computed separately, sent separately, and tested separately.
 *
 * MEASURED IS NOT COVERED. A probe asks Google about a phrase in isolation; it does not mean a
 * production search ever ran it. {@see ExecutedQueryLedger} owns what actually executed and is what
 * excludes a phrase from a plan. This class owns what was MEASURED and only ever informs — it
 * excludes nothing. Conflating the two would suppress searches that never happened.
 *
 * ALWAYS DERIVED FROM THE LATEST PROBE. See {@see QueryYieldLog::latestProbe()} for why a sum would
 * report an exhausted phrase as healthy.
 */
class QueryFeedback
{
    /** No measurement exists. Not a judgement — the absence of one. */
    public const UNMEASURED = 'unmeasured';

    /** Probed and returned nothing at all: the wording itself reaches no businesses. */
    public const BARREN = 'barren';

    /** Returned businesses, none of them new to this CRM. The wording works; the ground is mined. */
    public const NO_NEW = 'no_new';

    /** New businesses, and the page was not full — a real total. */
    public const PRODUCTIVE = 'productive';

    /** New businesses and the page WAS full. A floor, never a total. */
    public const ABUNDANT = 'abundant';

    /** Verdicts that mean a phrase still has ground to cover. */
    public const REWARDING = [self::PRODUCTIVE, self::ABUNDANT];

    /** Verdicts that mean measurement has already answered this phrase. */
    public const ANSWERED = [self::BARREN, self::NO_NEW];

    /** How many measured phrases may travel to the model, matching the covered-value ceiling. */
    public const MAX_REPORTED = OpenAiDiscoveryStrategist::MAX_KNOWN_SELECTORS;

    public function __construct(private readonly QueryYieldLog $log) {}

    /** Whether measurements are permitted to influence strategy at all. Default off. */
    public function enabled(): bool
    {
        return (bool) config('discovery.feedback.enabled', false);
    }

    /**
     * The verdict for one phrase.
     *
     * Order matters: `barren` is decided before novelty is consulted, because a phrase that returned
     * nothing has no novelty to speak of and must not be reported as merely mined out.
     */
    public function verdict(string $category, string $area, string $phrase): string
    {
        $probe = $this->log->latestProbe($category, $area, $phrase);

        if ($probe === null) {
            return self::UNMEASURED;
        }

        $returned = (int) ($probe['returned'] ?? 0);
        if ($returned <= 0) {
            return self::BARREN;
        }

        if ((int) ($probe['novel'] ?? 0) <= 0) {
            return self::NO_NEW;
        }

        return ($probe['capped'] ?? false) === true ? self::ABUNDANT : self::PRODUCTIVE;
    }

    /**
     * Verdicts for the phrases this target has measurements for, ready to travel to the model.
     *
     * ONLY MEASURED PHRASES APPEAR. An `unmeasured` entry would carry no information and cost prompt
     * space to say nothing; the instruction tells the model that an absent phrase is unmeasured.
     *
     * Returns an empty list when feedback is switched off, so the payload is byte-identical to its
     * pre-feedback shape rather than carrying an empty key.
     *
     * @return list<array{phrase: string, verdict: string}>
     */
    public function reportFor(string $category, string $area): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $report = [];

        foreach ($this->log->probedPhrases($category, $area) as $phrase) {
            $report[] = [
                'phrase' => $phrase,
                'verdict' => $this->verdict($category, $area, $phrase),
            ];

            if (count($report) >= self::MAX_REPORTED) {
                break;
            }
        }

        return $report;
    }

    /**
     * A stable identity for a feedback report, for the strategy cache key.
     *
     * WHY FEEDBACK MUST REACH THE KEY. Plans are cached permanently. "How should I search this niche,
     * given that two of my phrasings are now exhausted" is a DIFFERENT question from the one asked
     * before those measurements existed — and a cache that ignored the difference would answer the
     * older question forever, which is exactly the adaptivity this slice exists to add.
     *
     * Order-insensitive, because the same verdicts learned in a different order are the same state.
     *
     * @param  list<array{phrase: string, verdict: string}>  $report
     */
    public function fingerprint(array $report): string
    {
        if ($report === []) {
            // Named rather than hashed, so "no feedback in play" is legible in a key.
            return 'none';
        }

        $pairs = array_map(
            fn (array $entry): string => mb_strtolower($entry['phrase']).'='.$entry['verdict'],
            $report,
        );
        sort($pairs);

        return substr(sha1(implode("\n", $pairs)), 0, 12);
    }
}
