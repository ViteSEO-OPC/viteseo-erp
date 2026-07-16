<?php

namespace App\Modules\Crm\Services\Ranking;

/**
 * One BD's line in the ranking result — raw components, peer-relative sub-scores, the BDR index, and
 * ordinal rank. A BD in the "No portfolio" bucket (`ranked = false`) carries a `reason` and null
 * subs/bdr/rank. Immutable.
 */
final readonly class BdRankingRow
{
    /**
     * @param  array{c1: float, c2: float, c3: float, c4: float}  $raw
     * @param  array{c1: float, c2: float, c3: float, c4: float}|null  $subs  peer-relative sub-scores (null when not ranked)
     */
    public function __construct(
        public string $bdId,
        public string $name,
        public string $initials,
        public bool $ranked,
        public array $raw,
        public ?array $subs,
        public ?float $bdr,
        public ?int $rank,
        public ?string $reason = null,
    ) {}
}
