<?php

namespace App\Modules\Crm\Services\Scoring;

/**
 * The result of scoring one lead: the final clamped 0–100 Personal Lead Score, the three category
 * subtotals, and the per-signal breakdown that explains it. Immutable — a pure function of the
 * inputs at a fixed evaluation instant (BD-PERF-COMP-SPEC §2 determinism).
 */
final readonly class LeadScoreResult
{
    /**
     * @param  int  $fitMax  the Fit cap the subtotal was clamped to (for a "40 / 40" panel)
     * @param  int  $engagementMax  the Engagement cap the subtotal was clamped to
     * @param  int  $scoreMax  the score ceiling (final clamp upper bound)
     * @param  list<ScoreSignal>  $signals
     */
    public function __construct(
        public int $score,
        public int $fit,
        public int $engagement,
        public int $negative,
        public int $fitMax,
        public int $engagementMax,
        public int $scoreMax,
        public array $signals,
    ) {}
}
