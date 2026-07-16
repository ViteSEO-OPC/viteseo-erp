<?php

namespace App\Modules\Crm\Services\Kpi;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Computes the BD operational KPI catalog (M-01…M-06) for an ownership scope, from **real CRM data
 * only**. This is the query/orchestration half of the BD KPI Engine; the pure arithmetic and the
 * frozen definitions live in {@see KpiCalculator}. Mirrors the Lead Score Engine split (pure
 * calculator + DB-aware orchestrator).
 *
 * Ownership scoping reuses the single sources of truth already in the app — `Lead::scopeVisibleTo`
 * (lead metrics) and `Activity::scopeVisibleTo` (activity metrics): a Business Development Manager
 * sees only their own portfolio/activity; Admin / Super Admin see all visible. All counting is
 * server-side; nothing is fabricated. Every lead-based count comes from a single status GROUP BY (no
 * per-status round trips, no N+1).
 */
class BdKpiService
{
    public function __construct(
        private readonly KpiCalculator $calculator,
        private readonly KpiConfig $config,
    ) {}

    public function forUser(User $user, ?Carbon $now = null): KpiResult
    {
        $now ??= Carbon::now();

        // One status aggregation over the ownership-scoped leads → powers M-01/M-02/M-03/M-05.
        /** @var array<string, int> $statusCounts */
        $statusCounts = Lead::query()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        // M-04 numerator: engaged = any outreach started OR any calling activity.
        $engagedCount = Lead::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q->where('email_outreach', '!=', 'none')
                ->orWhere('cold_calling', '!=', 'not-called'))
            ->count();

        // M-06: scoped activities within the rolling window (effort proxy).
        $windowStart = $now->copy()->subDays($this->config->activityWindowDays);
        $activityVolume = Activity::query()
            ->visibleTo($user)
            ->where('created_at', '>=', $windowStart)
            ->count();

        return $this->calculator->compute($statusCounts, $engagedCount, $activityVolume);
    }
}
