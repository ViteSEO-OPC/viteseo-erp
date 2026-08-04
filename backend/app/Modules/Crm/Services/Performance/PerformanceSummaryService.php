<?php

namespace App\Modules\Crm\Services\Performance;

use App\Models\User;
use App\Modules\Crm\Enums\PerformanceTargetMetric;
use App\Modules\Crm\Enums\PerformanceTargetPeriod;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The headline performance figures for one reporting subject — the single owner of the numbers
 * behind My Performance, the Reports & Performance scorecard, and the exported PDF.
 *
 * EXTRACTED VERBATIM from DashboardController::performanceSummary(). Every formula, frozen status
 * set, rounding rule and zero-denominator decision below is the approved computation moved as-is;
 * nothing was redesigned, renamed or rescoped in the move. The controller now returns
 * `$service->for($subject)` and its JSON shape is unchanged.
 *
 * WHY IT MOVED. The PDF export must not recompute anything — a second implementation of Conversion
 * Rate or Revenue would let the document and the screen disagree for the same subject. With one
 * service behind both, drift is impossible by construction rather than by discipline.
 *
 * SCOPE IS THE CALLER'S BUSINESS. This takes an already-resolved subject; it never reads the
 * request. PerformanceScopeRequest stays the single place a request becomes an authorized scope
 * (BD → self, Admin/SA → whole CRM, `?user_id` → one BD, management-only), so every consumer
 * inherits the same RBAC without this class knowing anything about it.
 */
class PerformanceSummaryService
{
    public function __construct(private readonly PerformanceTargetService $targets) {}

    /**
     * @return array{
     *     assignedLeads: int, activeLeads: int, engagedLeads: int, convertedLeads: int,
     *     overallRevenue: float, estimatedPipelineValue: float, conversionRate: float|null,
     *     completedFollowUps: int, personalTarget: array{value:int, progress:int}|null,
     *     followUpDiscipline: array{missedFollowUps: int, missedMeetings: int, missedCalls: int}
     * }
     */
    public function for(User $user): array
    {
        // Frozen ACTIVE_SET (BD-PERF-COMP-SPEC R-1): all statuses except `declined`.
        $activeSet = ['new', 'interested', 'awaiting-reply', 'call-back-req'];

        $assignedLeads = Lead::query()->active()->visibleTo($user)->count();
        $activeLeads = Lead::query()->active()->visibleTo($user)->whereIn('status', $activeSet)->count();

        // Engagement basis (M-04): declared outreach OR Zerem-verified outreach activity — the single
        // canonical definition ({@see Lead::scopeEngaged}), shared with the KPI Engine and the Reports
        // funnel. Also the Conversion Rate denominator below, whose FORMULA is unchanged.
        $engagedLeads = Lead::query()->active()->visibleTo($user)->engaged()->count();

        // Converted leads + revenue from the deals table, scoped to the user's visible leads.
        $convertedLeads = Lead::query()->active()->visibleTo($user)->has('deals')->count();
        $visibleLeadIds = Lead::query()->active()->visibleTo($user)->pluck('id');
        // REVENUE = realized, won business → Σ deals.amount ONLY (never leads.amount). Unchanged.
        $overallRevenue = $visibleLeadIds->isEmpty()
            ? 0.0
            : (float) Deal::query()->whereIn('lead_id', $visibleLeadIds)->sum('amount');

        // ESTIMATED PIPELINE VALUE = prospect-stage Estimated Deal Value → Σ leads.amount, ownership-
        // scoped. Distinct from Revenue: this is the sum of `leads.amount` (an estimate on open leads),
        // NOT realized revenue. Single source of truth for the pipeline figure — computed only here.
        $estimatedPipelineValue = (float) Lead::query()->active()->visibleTo($user)->sum('amount');

        // Conversion Rate = converted ÷ engaged (approved). Zero denominator → null (undefined).
        $conversionRate = $engagedLeads > 0
            ? round($convertedLeads / $engagedLeads * 100, 1)
            : null;

        // Planner-based follow-up metrics — scoped exactly like the lead metrics, via
        // PlannerEvent::visibleTo (a BD sees their own events, Admin/SA all).
        //
        // Follow-up discipline (PO-frozen rule): "missed" = an event whose `starts_at` is in the past
        // and whose `status` is not `completed` — grouped by event_type (follow-up / meeting / call).
        $missedFor = fn (string $type): int => PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', $type)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', now())
            ->where('status', '!=', 'completed')
            ->count();

        // Completed Follow-ups — count for the CURRENT month, timestamped by the real `completed_at`
        // (never `updated_at`). Rows completed before `completed_at` existed carry null and are excluded.
        $completedFollowUps = PlannerEvent::query()
            ->visibleTo($user)
            ->where('event_type', 'follow-up')
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->count();

        // Personal Target — the monthly converted-leads goal from Goal Management, or null when
        // none is configured. NULL IS THE HONEST ANSWER: every consumer keeps its existing Pending
        // state rather than showing a fabricated denominator. The target supplies the denominator
        // only; `convertedLeads` above remains the authoritative computed numerator.
        $targetValue = $this->targets->valueFor(
            $user->id,
            PerformanceTargetPeriod::MONTH,
            PerformanceTargetMetric::CONVERTED_LEADS,
        );

        return [
            'assignedLeads' => $assignedLeads,
            'activeLeads' => $activeLeads,
            'engagedLeads' => $engagedLeads,
            'convertedLeads' => $convertedLeads,
            'overallRevenue' => $overallRevenue,
            'estimatedPipelineValue' => $estimatedPipelineValue,
            'conversionRate' => $conversionRate,
            'completedFollowUps' => $completedFollowUps,
            'personalTarget' => $targetValue === null
                ? null
                : ['value' => $targetValue, 'progress' => $convertedLeads],
            'followUpDiscipline' => [
                'missedFollowUps' => $missedFor('follow-up'),
                'missedMeetings' => $missedFor('meeting'),
                'missedCalls' => $missedFor('call'),
            ],
        ];
    }
}
