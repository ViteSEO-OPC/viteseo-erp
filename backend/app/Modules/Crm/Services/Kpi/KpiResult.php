<?php

namespace App\Modules\Crm\Services\Kpi;

/**
 * The full BD operational KPI set (M-01…M-06) for one ownership scope, computed at a fixed instant.
 * Immutable. The order of {@see $metrics} is the catalog order (M-01 → M-06).
 */
final readonly class KpiResult
{
    /** @param list<KpiMetric> $metrics */
    public function __construct(public array $metrics) {}

    public function get(string $id): ?KpiMetric
    {
        foreach ($this->metrics as $metric) {
            if ($metric->id === $id) {
                return $metric;
            }
        }

        return null;
    }

    /** The metric's computed value, or null if the metric is absent or not evaluable. */
    public function metricValue(string $id): int|float|null
    {
        foreach ($this->metrics as $metric) {
            if ($metric->id === $id) {
                return $metric->value;
            }
        }

        return null;
    }
}
