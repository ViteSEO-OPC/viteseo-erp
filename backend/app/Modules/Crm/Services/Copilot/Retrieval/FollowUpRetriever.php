<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\Dashboard\DashboardMetricsService;

/**
 * Open follow-up work, counted into overdue / today / upcoming by the Manila day — the SAME
 * definition and buckets as the dashboard and the Reports page.
 *
 * Scope: `PlannerEvent::visibleTo` (a BD: their own events plus events on leads they own). Counts
 * only — no titles, notes or lead names in Phase 1. Gate: dashboard AND follow-up view permissions.
 */
class FollowUpRetriever implements CopilotCapability
{
    public const KEY = 'follow_ups';

    public function __construct(private readonly DashboardMetricsService $metrics) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return $user->can('crm.dashboard.view') && $user->can('crm.follow-ups.view');
    }

    public function retrieve(User $user, string $question): RetrievedFacts
    {
        $counts = $this->metrics->followUpCounts($user);

        return new RetrievedFacts(
            self::KEY,
            'Open, dated follow-up work (follow-ups, calls, emails, meetings) that is not completed, split by the Asia/Manila calendar day.',
            [
                RetrievedFacts::fact('overdue', (int) $counts['overdue'], RetrievedFacts::COUNT),
                RetrievedFacts::fact('due_today', (int) $counts['today'], RetrievedFacts::COUNT),
                RetrievedFacts::fact('upcoming', (int) $counts['upcoming'], RetrievedFacts::COUNT),
            ],
            3,
            FactScope::forViewer($user),
        );
    }
}
