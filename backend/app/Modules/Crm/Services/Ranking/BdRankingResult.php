<?php

namespace App\Modules\Crm\Services\Ranking;

/**
 * The BD Ranking (Interim) outcome. `interim` is always true — the model must be labelled *Interim*
 * wherever surfaced (R-10). `status` is:
 *   - `ranked` — a valid relative ranking was produced (≥ min peers with a portfolio);
 *   - `na`     — ranking not available (fewer than the required peers) — §5.6.
 *
 * `rows` are ordered: ranked BDs by rank ascending, then the "No portfolio" bucket. Immutable.
 */
final readonly class BdRankingResult
{
    public const RANKED = 'ranked';

    public const NA = 'na';

    /** @param list<BdRankingRow> $rows */
    public function __construct(
        public string $status,
        public bool $interim,
        public array $rows,
    ) {}
}
