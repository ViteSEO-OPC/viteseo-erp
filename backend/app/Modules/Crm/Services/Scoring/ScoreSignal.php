<?php

namespace App\Modules\Crm\Services\Scoring;

/**
 * One line of a Personal Lead Score computation — a single frozen signal, whether it fired, and how
 * many (signed) points it contributed. The collection of these is the auditable "why" behind a
 * score (BD-PERF-COMP-SPEC R-3: a BD can read exactly why a lead scored what it did).
 */
final readonly class ScoreSignal
{
    public const FIT = 'fit';

    public const ENGAGEMENT = 'engagement';

    public const NEGATIVE = 'negative';

    /**
     * @param  string  $key  stable machine key (e.g. `website`, `interested-reply`)
     * @param  string  $label  human label (e.g. "Interested Reply")
     * @param  string  $category  one of FIT | ENGAGEMENT | NEGATIVE
     * @param  int  $points  signed points this signal contributed (0 when not applied)
     * @param  bool  $applied  whether the signal's business condition was met
     * @param  bool  $evaluable  false when the underlying data cannot be evaluated yet
     *                           (e.g. Invalid/Bounced Email — pending Gmail); such a signal
     *                           contributes 0 and is surfaced honestly, never guessed.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $category,
        public int $points,
        public bool $applied,
        public bool $evaluable = true,
    ) {}
}
