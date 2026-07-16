<?php

namespace App\Modules\Crm\Services\Scoring;

/**
 * Typed, immutable view of `config/scoring.php` — the externalized Personal Lead Score business
 * parameters (weights, windows, caps). The calculator depends on this object rather than the raw
 * config array so weight access is type-safe (PHPStan-clean) and so the values can be swapped in a
 * test without booting the framework. Editing the numbers is a config change, never a code change.
 */
final readonly class ScoringConfig
{
    /** @param array<string, int> $points */
    public function __construct(
        public array $points,
        public int $recentDays,
        public int $staleDays,
        public int $fitMax,
        public int $engagementMax,
        public int $scoreMin,
        public int $scoreMax,
    ) {}

    /** Points for a signal key (0 if the key is absent — a missing weight never crashes scoring). */
    public function point(string $key): int
    {
        return $this->points[$key] ?? 0;
    }

    /** @param array<string, mixed> $config the `config('scoring')` array */
    public static function fromArray(array $config): self
    {
        $points = [];
        /** @var array<array-key, mixed> $rawPoints */
        $rawPoints = is_array($config['points'] ?? null) ? $config['points'] : [];
        foreach ($rawPoints as $key => $value) {
            $points[(string) $key] = (int) $value;
        }

        /** @var array<string, mixed> $windows */
        $windows = is_array($config['windows'] ?? null) ? $config['windows'] : [];
        /** @var array<string, mixed> $caps */
        $caps = is_array($config['caps'] ?? null) ? $config['caps'] : [];

        return new self(
            points: $points,
            recentDays: (int) ($windows['recent_days'] ?? 7),
            staleDays: (int) ($windows['stale_days'] ?? 30),
            fitMax: (int) ($caps['fit_max'] ?? 40),
            engagementMax: (int) ($caps['engagement_max'] ?? 60),
            scoreMin: (int) ($caps['score_min'] ?? 0),
            scoreMax: (int) ($caps['score_max'] ?? 100),
        );
    }
}
