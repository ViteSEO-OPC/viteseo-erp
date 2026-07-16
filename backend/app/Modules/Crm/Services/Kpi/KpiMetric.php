<?php

namespace App\Modules\Crm\Services\Kpi;

/**
 * One operational KPI (BD-PERF-COMP-SPEC §3, M-01…M-06) — its identity, computed value, and whether it
 * could be evaluated at all. A metric that cannot be computed from real data (e.g. a zero denominator,
 * or a future missing integration) is returned with `evaluable = false`, `value = null`, and a
 * human-readable `reason` — **never a fabricated number** (§2 zero-denominator rule).
 *
 * The Recent Activity feed (former M-07) is intentionally NOT a KPI — it is a dashboard/timeline
 * presentation component served by `GET /activities`, not a business performance metric.
 */
final readonly class KpiMetric
{
    public const COUNT = 'count';

    public const PERCENT = 'percent';

    public const BREAKDOWN = 'breakdown';

    /**
     * @param  string  $id  catalog id, e.g. "M-01"
     * @param  string  $unit  one of COUNT | PERCENT | BREAKDOWN
     * @param  int|float|null  $value  scalar value; null when not evaluable (or when unit = BREAKDOWN)
     * @param  array<string, int>|null  $components  per-key counts (M-02 status breakdown)
     * @param  string|null  $reason  why the metric is not evaluable (present only when evaluable = false)
     * @param  string|null  $feeds  what this metric feeds downstream (e.g. "BDR", "display")
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $unit,
        public int|float|null $value,
        public bool $evaluable,
        public ?array $components = null,
        public ?string $reason = null,
        public ?string $feeds = null,
    ) {}
}
