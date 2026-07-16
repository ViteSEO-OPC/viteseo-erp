<?php

namespace App\Modules\Crm\Services\Scoring;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Observers\LeadObserver;
use Illuminate\Support\Carbon;

/**
 * Orchestrates Personal Lead Score computation for a real {@see Lead}: resolves the two inputs the
 * pure {@see LeadScoreCalculator} cannot derive on its own — the Target-Location verdict (registry
 * membership) and the recency instant (`leads.last_activity`) — then applies the frozen formula and,
 * for {@see refresh()}, persists the result to the reused `leads.score` column.
 *
 * The KPI Engine is the **only** writer of `leads.score` (BD-PERF-COMP-SPEC R-4). Persistence uses
 * `updateQuietly` so it neither fires model events (no recursion via {@see LeadObserver})
 * nor logs a spurious "Lead updated" Activity — a score refresh is an engine action, not a user edit.
 *
 * The active Service-Area registry is loaded once per scorer instance ({@see $matcher}) so recomputing
 * many leads in one request/run doesn't re-query it. The scorer is bound as a singleton; a batch run
 * that must reflect a just-changed registry constructs a fresh scorer (see the recompute command).
 */
class LeadScorer
{
    private ?TargetAreaMatcher $matcher = null;

    public function __construct(private readonly LeadScoreCalculator $calculator) {}

    /** Compute (without persisting) — used for the score-breakdown endpoint and by refresh(). */
    public function calculate(Lead $lead, ?Carbon $now = null): LeadScoreResult
    {
        return $this->calculator->calculate(
            websiteStatus: $lead->website_status,
            businessName: $lead->business_name,
            contactName: $lead->contact_name,
            status: $lead->status,
            coldCalling: $lead->cold_calling,
            emailOutreach: $lead->email_outreach,
            inTargetLocation: $this->matcher()->matches($lead),
            lastActivityAt: $lead->last_activity,
            now: $now ?? Carbon::now(),
        );
    }

    /** Compute and persist `leads.score`. Returns the stored score. */
    public function refresh(Lead $lead, ?Carbon $now = null): int
    {
        $result = $this->calculate($lead, $now);

        if ($lead->score !== $result->score) {
            $lead->score = $result->score;
            $lead->updateQuietly(['score' => $result->score]);
        }

        return $result->score;
    }

    private function matcher(): TargetAreaMatcher
    {
        return $this->matcher ??= TargetAreaMatcher::fromRegistry();
    }
}
