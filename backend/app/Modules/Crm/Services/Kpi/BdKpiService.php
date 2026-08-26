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
            ->active()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        // M-04 numerator: engaged = declared outreach OR Zerem-verified outreach activity.
        // Single canonical definition — {@see Lead::scopeEngaged}. Reports' `Contacted` stage and the
        // Conversion Rate denominator read the SAME scope, so the three can no longer drift apart.
        $engagedCount = Lead::query()
            ->active()
            ->visibleTo($user)
            ->engaged()
            ->count();

        // M-06: activities this BD PERFORMED within the rolling window (effort proxy).
        //
        // `performedBy` (causer_id), NOT `visibleTo`. The visibility scope also matches activities on
        // leads the user currently owns, so after a reassignment it credited the previous owner's work
        // to the new owner as well — the same effort counted twice, and a departing BD's record
        // silently transferred. Effort is attributed to whoever actually performed it.
        $windowStart = $now->copy()->subDays($this->config->activityWindowDays);
        $activityVolume = Activity::query()
            ->performedBy($user)
            ->forCrmReporting() // M-06 counts CRM activity only — prospect-pool activity is excluded
            ->where('created_at', '>=', $windowStart)
            ->count();

        return $this->calculator->compute($statusCounts, $engagedCount, $activityVolume);
    }
}
