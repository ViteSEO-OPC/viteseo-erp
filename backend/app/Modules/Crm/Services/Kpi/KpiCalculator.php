<?php

namespace App\Modules\Crm\Services\Kpi;

/**
 * Pure, deterministic assembler of the BD operational KPI catalog (BD-PERF-COMP-SPEC §3).
 *
 * The catalog is the **business KPIs M-01…M-06**. M-07 "Recent Activity" is deliberately **not** here:
 * per PO decision (2026-07-13) an ordered activity feed is a **dashboard/timeline presentation
 * component, not a business performance metric** — it stays served by `GET /activities`, keeping a clean
 * separation between business computation and dashboard presentation.
 *
 * Given already-scoped raw counts (the {@see BdKpiService} runs the ownership-scoped DB queries), this
 * class applies the frozen definitions — the status-set memberships (M-03/M-05), the percentage
 * formulas (M-04/M-05), the fixed rounding (1 dp, §2), and the **zero-denominator rule**: a ratio with
 * an empty denominator is `— (undefined)` — returned as `evaluable = false, value = null` with a
 * reason, **never `0%`** and never fabricated. Being pure (scalar-in), every rule is unit-testable
 * without a database.
 *
 * Every M-01…M-06 metric is computable from real CRM data today; none depends on a future integration.
 * The mechanism to mark a metric "not evaluable / pending integration" exists (and is used for the
 * zero-denominator case) so a future metric blocked on an integration can be surfaced honestly.
 */
class KpiCalculator
{
    public function __construct(private readonly KpiConfig $config) {}

    /**
     * @param  array<string, int>  $statusCounts  count of visible leads per status enum value
     * @param  int  $engagedCount  visible leads with any outreach or any calling (M-04 numerator)
     * @param  int  $activityVolume  scoped activities within the rolling window (M-06)
     */
    public function compute(array $statusCounts, int $engagedCount, int $activityVolume): KpiResult
    {
        $c = $this->config;

        $assigned = array_sum($statusCounts);
        $active = $this->sumOf($statusCounts, $c->activeStatuses);
        $interestNumerator = $this->sumOf($statusCounts, $c->interestStatuses);
        $declined = $statusCounts[$c->declinedStatus] ?? 0;
        $nonDeclined = $assigned - $declined;

        $metrics = [];

        // M-01 Assigned Leads
        $metrics[] = new KpiMetric('M-01', 'Assigned Leads', KpiMetric::COUNT, $assigned, true,
            feeds: 'BDR denominators');

        // M-02 Leads by Status — a breakdown (counts by enum), always in the frozen status order.
        $components = [];
        foreach ($c->statuses as $status) {
            $components[$status] = $statusCounts[$status] ?? 0;
        }
        $metrics[] = new KpiMetric('M-02', 'Leads by Status', KpiMetric::BREAKDOWN, null, true,
            components: $components, feeds: 'M-03, M-05');

        // M-03 Active Leads (status ∈ ACTIVE_SET)
        $metrics[] = new KpiMetric('M-03', 'Active Leads', KpiMetric::COUNT, $active, true, feeds: '—');

        // M-04 Engagement Coverage = engaged ÷ assigned. Zero denominator → undefined (never 0%).
        $metrics[] = $assigned > 0
            ? new KpiMetric('M-04', 'Engagement Coverage', KpiMetric::PERCENT,
                round($engagedCount / $assigned * 100, 1), true, feeds: 'BDR')
            : new KpiMetric('M-04', 'Engagement Coverage', KpiMetric::PERCENT, null, false,
                reason: 'No assigned leads', feeds: 'BDR');

        // M-05 Interest Rate = (interested + call-back-req) ÷ non-declined assigned. Zero denom → undefined.
        $metrics[] = $nonDeclined > 0
            ? new KpiMetric('M-05', 'Interest Rate', KpiMetric::PERCENT,
                round($interestNumerator / $nonDeclined * 100, 1), true, feeds: 'BDR')
            : new KpiMetric('M-05', 'Interest Rate', KpiMetric::PERCENT, null, false,
                reason: 'No non-declined assigned leads', feeds: 'BDR');

        // M-06 Activity Volume (rolling window)
        $metrics[] = new KpiMetric('M-06', "Activity Volume ({$c->activityWindowDays}d)", KpiMetric::COUNT,
            $activityVolume, true, feeds: 'BDR');

        return new KpiResult($metrics);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $keys
     */
    private function sumOf(array $counts, array $keys): int
    {
        $sum = 0;
        foreach ($keys as $key) {
            $sum += $counts[$key] ?? 0;
        }

        return $sum;
    }
}
