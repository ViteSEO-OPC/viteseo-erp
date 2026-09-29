<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Services\Kpi\KpiMetric;
use App\Modules\Crm\Services\Performance\PerformanceSummaryService;

/**
 * The caller's performance figures — exactly what My Performance already shows them.
 *
 * SUBJECT IS ALWAYS THE CALLER. Both services take an already-resolved subject; this passes the
 * authenticated user and nothing else, so a BD gets their own figures and an Admin/Super Admin gets
 * the whole-CRM figures `visibleTo` already gives them. There is no `user_id`, no drill-down and no
 * cross-BD comparison. Gate: `crm.dashboard.view`, the permission on /dashboard/performance-summary
 * and /dashboard/kpi-metrics.
 *
 * Percentages are supplied as `percent` facts and peso amounts as `php` facts; those are the ONLY
 * percentages and amounts the answer may quote.
 */
class PerformanceRetriever implements CopilotCapability
{
    public const KEY = 'performance';

    public function __construct(
        private readonly PerformanceSummaryService $summary,
        private readonly BdKpiService $kpi,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return $user->can('crm.dashboard.view');
    }

    public function retrieve(User $user, string $question): RetrievedFacts
    {
        $s = $this->summary->for($user);

        $facts = [
            RetrievedFacts::fact('assigned_leads', (int) $s['assignedLeads'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('active_leads', (int) $s['activeLeads'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('engaged_leads', (int) $s['engagedLeads'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('converted_leads', (int) $s['convertedLeads'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('overall_revenue', (float) $s['overallRevenue'], RetrievedFacts::PHP),
            RetrievedFacts::fact('estimated_pipeline_value', (float) $s['estimatedPipelineValue'], RetrievedFacts::PHP),
            RetrievedFacts::fact('completed_follow_ups', (int) $s['completedFollowUps'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('missed_follow_ups', (int) $s['followUpDiscipline']['missedFollowUps'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('missed_meetings', (int) $s['followUpDiscipline']['missedMeetings'], RetrievedFacts::COUNT),
            RetrievedFacts::fact('missed_calls', (int) $s['followUpDiscipline']['missedCalls'], RetrievedFacts::COUNT),
        ];
        // Null means "not computable" (e.g. nothing engaged yet) — supplied as such, never as 0.
        $facts[] = RetrievedFacts::fact('conversion_rate', $s['conversionRate'], RetrievedFacts::PERCENT);
        $facts[] = RetrievedFacts::fact(
            'personal_target',
            $s['personalTarget'] === null ? null : ['target' => (int) $s['personalTarget']['value'], 'progress' => (int) $s['personalTarget']['progress']],
            RetrievedFacts::COUNT,
        );

        foreach ($this->kpi->forUser($user)->metrics as $metric) {
            // M-02 duplicates the status breakdown the portfolio already supplies.
            if ($metric->unit === KpiMetric::BREAKDOWN) {
                continue;
            }
            $facts[] = RetrievedFacts::fact(
                $metric->label,
                $metric->evaluable ? $metric->value : null,
                $metric->unit === KpiMetric::PERCENT ? RetrievedFacts::PERCENT : RetrievedFacts::COUNT,
            );
        }

        return new RetrievedFacts(
            self::KEY,
            "This user's own performance figures as shown on My Performance (for an Admin, the whole CRM). Amounts are in Philippine pesos. conversion_rate = converted / engaged leads. A null value means the figure cannot be computed yet.",
            $facts,
            count($facts),
            FactScope::forViewer($user),
        );
    }
}
