<?php

namespace App\Modules\Crm\Observers;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Illuminate\Support\Facades\Auth;

/**
 * Records an append-only Activity for every Lead create/update/delete.
 * Chosen over Events/Listeners because no such infrastructure exists in the
 * app (reuse-first check) — this keeps controllers thin and captures all writes.
 *
 * Also keeps the Personal Lead Score (`leads.score`) current: the KPI Engine ({@see LeadScorer})
 * recomputes it whenever a lead is created or a scoring-input field changes. The recompute persists
 * via `updateQuietly`, so it neither re-enters this observer nor logs a "Lead updated" Activity for
 * the score change itself.
 *
 * And bridges Lead Management → Planner: setting Cold Calling to 'scheduled' auto-creates a
 * follow-up PlannerEvent ({@see FollowUpScheduler}) so the BD never hand-creates one after
 * scheduling outreach from the lead. Creating the event is a separate-model write, so it does not
 * re-enter this observer.
 */
class LeadObserver
{
    /** Lead columns that feed the Personal Lead Score — a change to any triggers a recompute. */
    private const SCORING_INPUTS = [
        'website_status', 'status', 'cold_calling', 'email_outreach',
        'contact_name', 'business_name', 'region', 'province', 'city', 'last_activity',
    ];

    public function __construct(
        private readonly LeadScorer $scorer,
        private readonly FollowUpScheduler $followUps,
    ) {}

    public function created(Lead $lead): void
    {
        $this->log($lead, 'created', 'Lead created');
        $this->scorer->refresh($lead);

        // A lead created already marked Cold Calling = Scheduled needs its follow-up too.
        if ($lead->cold_calling === 'scheduled') {
            $this->followUps->scheduleForColdCall($lead);
        }
    }

    public function updated(Lead $lead): void
    {
        $changed = collect($lead->getChanges())
            ->except(['updated_at'])
            ->keys()
            ->all();

        $this->log(
            $lead,
            'updated',
            'Lead updated',
            $changed === [] ? null : ['changed' => $changed],
        );

        if (array_intersect($changed, self::SCORING_INPUTS) !== []) {
            $this->scorer->refresh($lead);
        }

        // Trigger only on the transition INTO 'scheduled' (not every save while already scheduled),
        // so a follow-up is created once per scheduling decision.
        if ($lead->wasChanged('cold_calling') && $lead->cold_calling === 'scheduled') {
            $this->followUps->scheduleForColdCall($lead);
        }
    }

    public function deleted(Lead $lead): void
    {
        $this->log($lead, 'deleted', 'Lead deleted');
    }

    /** @param array<string, mixed>|null $properties */
    private function log(Lead $lead, string $event, string $description, ?array $properties = null): void
    {
        $lead->activities()->create([
            'causer_id' => Auth::id(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}
