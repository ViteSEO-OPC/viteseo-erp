<?php

namespace App\Modules\Crm\Services\Ranking;

/**
 * Typed, immutable view of `config/ranking.php` — the externalized BD Ranking (Interim) parameters
 * (component weights, index precision, minimum peer count). The calculator depends on this object
 * rather than the raw config array so weight access is type-safe and swappable in a test.
 */
final readonly class RankingConfig
{
    public function __construct(
        public float $c1Weight,
        public float $c2Weight,
        public float $c3Weight,
        public float $c4Weight,
        public int $bdrDecimals,
        public int $minRankedPeers,
    ) {}

    /** Whether the C1–C4 weights sum to 1.00 within a small tolerance (R-7 weight integrity). */
    public function weightsSumToOne(): bool
    {
        return abs(($this->c1Weight + $this->c2Weight + $this->c3Weight + $this->c4Weight) - 1.0) < 1e-9;
    }

    /** @param array<string, mixed> $config the `config('ranking')` array */
    public static function fromArray(array $config): self
    {
        /** @var array<string, mixed> $w */
        $w = is_array($config['weights'] ?? null) ? $config['weights'] : [];

        return new self(
            c1Weight: (float) ($w['c1_portfolio_quality'] ?? 0.40),
            c2Weight: (float) ($w['c2_engagement_coverage'] ?? 0.25),
            c3Weight: (float) ($w['c3_interest_rate'] ?? 0.20),
            c4Weight: (float) ($w['c4_activity_volume'] ?? 0.15),
            bdrDecimals: (int) ($config['bdr_decimals'] ?? 1),
            minRankedPeers: (int) ($config['min_ranked_peers'] ?? 2),
        );
    }
}
