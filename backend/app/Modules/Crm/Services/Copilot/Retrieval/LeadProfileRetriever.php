<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\Copilot\Knowledge\LeadVocabulary;
use App\Modules\Crm\Services\Dashboard\DashboardMetricsService;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The profile of the one lead the question is about (PO decisions D2–D4, 2026-09-30). Nine facts:
 *
 *   - status, priority, website_status — normalised to {@see LeadVocabulary}, so free text in a
 *     category column is supplied as `other`, never verbatim;
 *   - lead_score — the STORED score. The scoring code is never run here;
 *   - last_activity — the Asia/Manila calendar date of `last_activity`, or null;
 *   - owner — `you`, `a teammate` or `unassigned`, never a person;
 *   - the lead's overdue / due-today / upcoming follow-ups — the dashboard's own pending follow-up
 *     definition and Manila day ({@see DashboardMetricsService}), narrowed to this lead, so a BD
 *     counts only events assigned to them and an Admin what the Planner shows them. Counts only.
 *
 * Lead columns are read only through {@see AuthorizedLead::value()}; the follow-up queries are
 * `count(*)`, so no event title or description is ever read.
 */
class LeadProfileRetriever implements LeadScopedCapability
{
    public const KEY = 'lead_profile';

    public function __construct(private readonly DashboardMetricsService $metrics) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return $user->can('crm.leads.view');
    }

    public function retrieve(User $user, AuthorizedLead $lead, string $question): RetrievedFacts
    {
        $lastActivity = $lead->value('last_activity');
        $score = $lead->value('score');

        return new RetrievedFacts(
            self::KEY,
            'The profile of the one lead this question is about. last_activity is the Philippine calendar date of its last recorded activity (null when none is recorded). owner says who holds it relative to this user. The follow-up counts are the pending follow-ups on this lead that this user can see in the Planner.',
            [
                RetrievedFacts::fact('status', LeadVocabulary::normalize('status', $lead->value('status')), RetrievedFacts::LABEL),
                RetrievedFacts::fact('priority', LeadVocabulary::normalize('priority', $lead->value('priority')), RetrievedFacts::LABEL),
                RetrievedFacts::fact('website_status', LeadVocabulary::normalize('website_status', $lead->value('website_status')), RetrievedFacts::LABEL),
                RetrievedFacts::fact('lead_score', is_numeric($score) ? (int) $score : null, RetrievedFacts::POINTS),
                RetrievedFacts::fact('last_activity', $lastActivity instanceof DateTimeInterface
                    ? Carbon::instance($lastActivity)->setTimezone('Asia/Manila')->format('Y-m-d')
                    : null, RetrievedFacts::DATE),
                RetrievedFacts::fact('owner', $lead->ownerLabel(), RetrievedFacts::LABEL),
                ...$this->followUps($user, $lead),
            ],
            1,
            FactScope::LEAD,
        );
    }

    /** @return list<array{name: string, value: int, unit: string}> */
    private function followUps(User $user, AuthorizedLead $lead): array
    {
        [$todayStart, $todayEnd] = $this->metrics->manilaDayWindow();
        $pending = $this->metrics->pendingFollowUps($user);
        $forLead = fn () => $pending()->where('lead_id', $lead->id());

        // Half-open [start, end) windows, identical to DashboardMetricsService::followUpCounts.
        return [
            RetrievedFacts::fact('lead_overdue_follow_ups', $forLead()->where('starts_at', '<', $todayStart)->count(), RetrievedFacts::COUNT),
            RetrievedFacts::fact('lead_follow_ups_due_today', $forLead()->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $todayEnd)->count(), RetrievedFacts::COUNT),
            RetrievedFacts::fact('lead_upcoming_follow_ups', $forLead()->where('starts_at', '>=', $todayEnd)->count(), RetrievedFacts::COUNT),
        ];
    }
}
