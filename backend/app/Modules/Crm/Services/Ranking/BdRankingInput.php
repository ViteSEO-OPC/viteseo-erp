<?php

namespace App\Modules\Crm\Services\Ranking;

/**
 * One BD's raw component inputs for the ranking calculation (BD-PERF-COMP-SPEC §5.2). The
 * {@see BdRankingService} builds these from live data; the pure {@see BdRankingCalculator} normalizes
 * them. A BD with no portfolio (`hasPortfolio = false`, 0 assigned leads) is carried through so it can
 * be surfaced in the "No portfolio" bucket rather than ranked last with 0 (§5.6).
 *
 * When `hasPortfolio` is true the raw components are: C1 mean PLS, C2 engagement coverage %, C3 interest
 * rate %, C4 activity volume (rolling-window count). C3 is 0.0 when the interest rate is undefined
 * (all-declined portfolio) — the BD still has a portfolio and is ranked, contributing 0 on that axis.
 */
final readonly class BdRankingInput
{
    public function __construct(
        public string $bdId,
        public string $name,
        public string $initials,
        public bool $hasPortfolio,
        public float $c1,
        public float $c2,
        public float $c3,
        public float $c4,
    ) {}
}
