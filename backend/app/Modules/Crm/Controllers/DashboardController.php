<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Crm\Resources\LeadResource;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use App\Models\User;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Services\Kpi\KpiMetric;
use App\Modules\Crm\Services\Ranking\BdRankingRow;
use App\Modules\Crm\Services\Ranking\BdRankingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /**
     * Supported KPI counts (backend-computable only) + per-status breakdown.
     * Scoped by ownership: Admin/SA see all leads; a BD sees only their own (Lead::scopeVisibleTo).
     */
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();

        // Single status aggregation (reuses the groupBy pattern from priorityBuckets);
        // powers both the `interested` KPI and the Lead Status Summary tab counts —
        // no duplicate aggregation, no new endpoint.
        $statusCounts = Lead::query()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'data' => [
                'totalLeads' => Lead::query()->visibleTo($user)->count(),
                'highPriority' => Lead::query()->visibleTo($user)->where('priority', 'high')->count(),
                'interested' => (int) $statusCounts->get('interested', 0),
                // Raw per-status counts (frozen enum keys). The UI applies its own
                // label mapping — keeping this response terminology-neutral.
                'statusCounts' => [
                    'new' => (int) $statusCounts->get('new', 0),
                    'interested' => (int) $statusCounts->get('interested', 0),
                    'awaiting-reply' => (int) $statusCounts->get('awaiting-reply', 0),
                    'call-back-req' => (int) $statusCounts->get('call-back-req', 0),
                    'declined' => (int) $statusCounts->get('declined', 0),
                ],
            ],
        ]);
    }

    /**
     * Dashboard Copilot AI summary metrics.
     *
     * TODO: these are CENTRALIZED PLACEHOLDER values. No AI scoring engine exists yet — the
     * lead `score` is a plain stored column and there is no re-score / priority-move tracking,
     * so these numbers have no real source to compute from (deliberately NOT fabricated here).
     * Once the AI scoring engine lands, replace these with real metrics: leads re-scored and
     * leads promoted to High priority in the last run, plus the actual run timestamp.
     */
    public function copilotSummary(): JsonResponse
    {
        return response()->json([
            'data' => [
                'rescoredLeads' => 18,
                'movedToHigh' => 4,
                'generatedAt' => '2026-07-03T11:20:00+00:00',
            ],
        ]);
    }

    /**
     * Lead counts / average score / pipeline share per website_status (ownership-scoped).
     *
     * Sprint 6: the "Lead Priority Overview" cards are website-condition segmentation
     * (No Website / Needs Redesign / Has Website), so they aggregate on website_status,
     * not priority. Method + route names are intentionally left unchanged for the demo
     * sprint — a rename is deferred to a dedicated follow-up refactor. Bucket order
     * matches the order the cards are rendered.
     */
    public function priorityBuckets(Request $request): JsonResponse
    {
        $user = $request->user();
        $total = Lead::query()->visibleTo($user)->count();

        /** @var Collection<int, object{website_status: string, count: int, avg_score: float|null}> $rows */
        $rows = Lead::query()
            ->visibleTo($user)
            ->selectRaw('website_status, COUNT(*) as count, AVG(score) as avg_score')
            ->groupBy('website_status')
            ->get();

        $buckets = collect(['no-website', 'needs-redesign', 'has-website'])->map(function (string $websiteStatus) use ($rows, $total): array {
            $row = $rows->firstWhere('website_status', $websiteStatus);
            $count = (int) ($row->count ?? 0);

            return [
                'websiteStatus' => $websiteStatus,
                'count' => $count,
                'avgScore' => (int) round((float) ($row->avg_score ?? 0)),
                'pipelinePct' => $total > 0 ? (int) round($count / $total * 100) : 0,
            ];
        })->all();

        return response()->json(['data' => $buckets]);
    }

    /** Latest leads (newest first) — same newest-by-id ordering as the Leads list, so
     * just-created/imported records surface here too (they have no last_activity).
     * Ownership-scoped: a BD only sees their own recent leads. */
    public function recentLeads(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $leads = Lead::query()
            ->visibleTo($user)
            ->latest('id')
            ->limit(5)
            ->get();

        return LeadResource::collection($leads)
            ->additional(['meta' => ['total' => Lead::query()->visibleTo($user)->count()]]);
    }

    /**
     * "BD Performance Overview" — one live operational row per active Business Development
     * Manager (Admin/SA Management Dashboard only; route-guarded to those roles).
     *
     * Every active BD is aggregated automatically, so a newly-created BD account appears here
     * with no code change. Only metrics with a legitimate, unambiguous backend source today are
     * returned:
     *   - assigned = leads assigned to the BD (leads.assigned_to) — a raw count, no business rule
     *
     * Deliberately NOT returned: follow-ups, converted leads, conversion rate, and any performance
     * score / status. There is no follow-ups backend (an outbound email is not, by definition, a
     * follow-up — mapping the two would invent a business rule), and the lead-status enum has no
     * converted/won state. Those columns stay display-only placeholders on the frontend until the
     * required business data exists and the KPI Engine milestone lands. No proxy is invented here.
     */
    public function bdPerformance(): JsonResponse
    {
        // Active Business Development Managers, name-ordered. `whereHas('roles')` matches the
        // same role slug the seeder + User Management use; `status = active` mirrors the sign-in gate.
        $bds = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'crm_bd'))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($bds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $ids = $bds->pluck('id');

        // Assigned leads per BD — one GROUP BY, no N+1. DB is the source of truth.
        $assigned = Lead::query()
            ->whereIn('assigned_to', $ids)
            ->selectRaw('assigned_to, COUNT(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $rows = $bds->map(fn (User $bd): array => [
            'id' => (string) $bd->id,
            'name' => $bd->name,
            'initials' => $this->initialsFor($bd->name),
            'assigned' => (int) ($assigned->get($bd->id) ?? 0),
        ])->values()->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * "Lead Acquisition" — raw count of NEW leads per calendar month over the last 6 months
     * (rolling window, oldest→newest). One `COUNT(*)` per month bucket on `leads.created_at`; every
     * newly created or imported lead lands in its month bucket automatically. Ownership-scoped via
     * `Lead::visibleTo` (Admin/SA = all leads).
     *
     * Descriptive only: the per-month counts, their total, the monthly average, and the current
     * calendar month's count. NO month-over-month %, growth, trend, forecast, or interpretation is
     * computed — those are deferred to the KPI Engine. DB is the single source of truth.
     */
    public function leadAcquisition(Request $request): JsonResponse
    {
        $user = $request->user();

        // Anchor to the first day of the month 5 months back, so the current month is the last of
        // 6 buckets. Buckets are generated up front → a month with no new leads still renders a 0 bar.
        $start = now()->startOfMonth()->subMonthsNoOverflow(5);

        // One COUNT per calendar-month bucket over the ownership-scoped leads. Per-month counting
        // (vs a DB-specific DATE_FORMAT/strftime GROUP BY) keeps this portable across MySQL and the
        // SQLite test suite, and makes every bar a literal COUNT(*). Every newly created/imported
        // lead lands in its month automatically.
        $points = [];
        $total = 0;
        $currentMonthCount = 0;
        for ($i = 0; $i < 6; $i++) {
            $month = (clone $start)->addMonthsNoOverflow($i);
            $count = Lead::query()
                ->visibleTo($user)
                ->whereBetween('created_at', [(clone $month)->startOfMonth(), (clone $month)->endOfMonth()])
                ->count();
            $total += $count;
            $points[] = ['month' => $month->format('M'), 'count' => $count];
            $currentMonthCount = $count; // last iteration (i=5) is the current calendar month
        }

        return response()->json(['data' => [
            'points' => $points,
            'totalNew' => $total,
            'monthlyAverage' => (int) round($total / 6),
            'currentMonthCount' => $currentMonthCount,
        ]]);
    }

    /**
     * "My Performance" personal KPI summary (BD-facing surface S1), ownership-scoped via
     * `Lead::scopeVisibleTo` — a BD sees only their own portfolio; Admin/SA see all visible leads.
     *
     * All computation is server-side (the frontend renders these numbers, it does not derive them):
     *   - assignedLeads   : visible leads (for a BD == leads assigned to them)
     *   - activeLeads     : visible leads in the frozen ACTIVE_SET (BD-PERF-COMP-SPEC R-1)
     *   - engagedLeads    : visible leads with any outreach or any calling (M-04 Engagement basis)
     *   - convertedLeads  : visible leads with ≥1 deal (won conversion)
     *   - overallRevenue  : Σ deal amounts across visible leads
     *   - conversionRate  : convertedLeads ÷ engagedLeads × 100 (approved denominator), 1 dp;
     *                       null when engagedLeads == 0 (undefined — never 0%, frozen edge rule)
     *
     * Converted/revenue come from the `deals` table (approved conversion record) — the frozen Lead
     * Status enum is untouched. "Completed Follow-ups", outreach/target, and follow-up discipline
     * are NOT computed here (no follow-up/appointment/target module exists) — they stay Demo on the
     * frontend. No proxy or invented business rule.
     */
    public function performanceSummary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Frozen ACTIVE_SET (BD-PERF-COMP-SPEC R-1): all statuses except `declined`.
        $activeSet = ['new', 'interested', 'awaiting-reply', 'call-back-req'];

        $assignedLeads = Lead::query()->visibleTo($user)->count();
        $activeLeads = Lead::query()->visibleTo($user)->whereIn('status', $activeSet)->count();

        // Engagement basis (M-04): any outreach started OR any calling activity.
        $engagedLeads = Lead::query()->visibleTo($user)
            ->where(fn (Builder $q) => $q->where('email_outreach', '!=', 'none')
                ->orWhere('cold_calling', '!=', 'not-called'))
            ->count();

        // Converted leads + revenue from the deals table, scoped to the user's visible leads.
        $convertedLeads = Lead::query()->visibleTo($user)->has('deals')->count();
        $visibleLeadIds = Lead::query()->visibleTo($user)->pluck('id');
        $overallRevenue = $visibleLeadIds->isEmpty()
            ? 0.0
            : (float) Deal::query()->whereIn('lead_id', $visibleLeadIds)->sum('amount');

        // Conversion Rate = converted ÷ engaged (approved). Zero denominator → null (undefined).
        $conversionRate = $engagedLeads > 0
            ? round($convertedLeads / $engagedLeads * 100, 1)
            : null;

        return response()->json(['data' => [
            'assignedLeads' => $assignedLeads,
            'activeLeads' => $activeLeads,
            'engagedLeads' => $engagedLeads,
            'convertedLeads' => $convertedLeads,
            'overallRevenue' => $overallRevenue,
            'conversionRate' => $conversionRate,
        ]]);
    }

    /**
     * BD operational KPI catalog (BD-PERF-COMP-SPEC §3, M-01…M-07) — the explainable breakdown from
     * the BD KPI Engine, computed entirely server-side from real CRM data and ownership-scoped via
     * `Lead::scopeVisibleTo` / `Activity::scopeVisibleTo` (a BD sees only their own portfolio; Admin/SA
     * see all visible). A metric with no computable value (e.g. a zero denominator) is returned as
     * `evaluable: false` with a `reason` and a null value — never a fabricated number.
     */
    public function kpiMetrics(Request $request, BdKpiService $kpi): JsonResponse
    {
        $result = $kpi->forUser($request->user());

        return response()->json(['data' => [
            'metrics' => array_map(fn (KpiMetric $m): array => [
                'id' => $m->id,
                'label' => $m->label,
                'unit' => $m->unit,
                'value' => $m->value,
                'evaluable' => $m->evaluable,
                'reason' => $m->reason,
                'components' => $m->components,
                'feeds' => $m->feeds,
            ], $result->metrics),
        ]]);
    }

    /**
     * BD Ranking (Interim) — the relative 0–100 index and ordinal rank across active BDs
     * (BD-PERF-COMP-SPEC §5, B-01). **Interim model** (R-10): the response always carries
     * `interim: true`, and consumers must label it Interim. Admin/Super-Admin only (route-guarded) —
     * a cross-BD ranking is a management view. `status` is `ranked` or `na` (fewer than the required
     * peers, §5.6). Each row exposes its raw components, peer-relative sub-scores, the BDR index, and
     * rank — the explainable breakdown; a BD with no portfolio is surfaced (unranked) with a reason,
     * never ranked last with 0.
     */
    public function bdRanking(BdRankingService $ranking): JsonResponse
    {
        $result = $ranking->rank();

        $round1 = fn (float $v): float => round($v, 1);

        return response()->json(['data' => [
            'interim' => $result->interim,
            'status' => $result->status,
            'model' => 'Interim Ranking Model',
            'rows' => array_map(fn (BdRankingRow $r): array => [
                'bdId' => $r->bdId,
                'name' => $r->name,
                'initials' => $r->initials,
                'ranked' => $r->ranked,
                'rank' => $r->rank,
                'bdr' => $r->bdr,
                'reason' => $r->reason,
                'raw' => [
                    'portfolioQuality' => $round1($r->raw['c1']),
                    'engagementCoverage' => $round1($r->raw['c2']),
                    'interestRate' => $round1($r->raw['c3']),
                    'activityVolume' => $r->raw['c4'],
                ],
                'subScores' => $r->subs === null ? null : [
                    'c1' => $round1($r->subs['c1']),
                    'c2' => $round1($r->subs['c2']),
                    'c3' => $round1($r->subs['c3']),
                    'c4' => $round1($r->subs['c4']),
                ],
            ], $result->rows),
        ]]);
    }

    /** First letters of the first two name parts, uppercased (e.g. "Jham Reyes" → "JR"). */
    private function initialsFor(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $letters = array_map(fn (string $p): string => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }
}
