<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\Dashboard\DashboardMetricsService;

/**
 * The dashboard's KPI figures, from the SAME service that serves GET /dashboard/kpis — the model and
 * the dashboard can never disagree.
 *
 * Gate: `crm.dashboard.view`, the permission on the dashboard route group.
 */
class DashboardKpiRetriever implements CopilotCapability
{
    public const KEY = 'dashboard_kpis';

    public function __construct(private readonly DashboardMetricsService $metrics) {}

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
        $kpis = $this->metrics->kpis($user);

        return new RetrievedFacts(
            self::KEY,
            'The Dashboard KPI cards for this user. follow_ups_due = open, dated follow-up work (overdue + today + upcoming). completed_outreach = outbound emails actually sent (a count of emails, not of leads).',
            [
                RetrievedFacts::fact('total_leads', (int) $kpis['totalLeads'], RetrievedFacts::COUNT),
                RetrievedFacts::fact('high_priority_leads', (int) $kpis['highPriority'], RetrievedFacts::COUNT),
                RetrievedFacts::fact('interested_leads', (int) $kpis['interested'], RetrievedFacts::COUNT),
                RetrievedFacts::fact('follow_ups_due', (int) $kpis['followUpsDue'], RetrievedFacts::COUNT),
                RetrievedFacts::fact('completed_outreach_emails', (int) $kpis['completedOutreach'], RetrievedFacts::COUNT),
            ],
            5,
            FactScope::forViewer($user),
        );
    }
}
