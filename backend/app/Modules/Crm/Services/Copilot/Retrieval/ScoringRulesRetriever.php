<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\BusinessRules\ScoringConfigProvider;
use App\Modules\Crm\Support\CrmRuleDefaults;

/**
 * The ACTIVE, published lead-scoring rules: each signal's points, the recency windows and the caps.
 *
 * SIGNS. The rule table stores every point value as a positive magnitude and carries the sign in the
 * rule's category (CrmRuleDefaults::scoringRuleset); LeadScoreCalculator subtracts the `negative`
 * category. Supplied raw, "has_website: 15" reads as a bonus. So the points are grouped by that
 * frozen category and penalties are supplied NEGATIVE (Has Website = -15) — the model is given what
 * the calculator does, not the storage format. A key with no frozen category has no known sign and
 * is not supplied.
 *
 * Gate: `crm.business_rules.view` — the permission on the Business Rules screen. A role without it
 * (a BD today) gets no rule table from the Copilot; it still gets the user-facing scoring articles
 * through the knowledge capability. No drafts, versions, audit history or authors.
 */
class ScoringRulesRetriever implements CopilotCapability
{
    public const KEY = 'scoring_rules';

    public function __construct(private readonly ScoringConfigProvider $scoring) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return $user->can('crm.business_rules.view');
    }

    public function retrieve(User $user, string $question): RetrievedFacts
    {
        $config = $this->scoring->resolve();

        $byCategory = ['fit' => [], 'engagement' => [], 'negative' => []];
        foreach (CrmRuleDefaults::scoringRuleset() as [$key, $category]) {
            if (isset($byCategory[$category]) && array_key_exists($key, $config->points)) {
                $magnitude = abs((int) $config->points[$key]);
                $byCategory[$category][$key] = $category === 'negative' ? -$magnitude : $magnitude;
            }
        }

        return new RetrievedFacts(
            self::KEY,
            'The active lead-scoring rules. fit_signal_points and engagement_signal_points: points a lead GAINS when the signal applies. negative_signal_points: PENALTIES, given as negative numbers — points a lead LOSES when the signal applies (state them with a minus sign). A signal worth 0 is switched off. The fit and engagement parts are each capped, and the final score is kept within the score range.',
            [
                RetrievedFacts::fact('fit_signal_points', $byCategory['fit'], RetrievedFacts::POINTS),
                RetrievedFacts::fact('engagement_signal_points', $byCategory['engagement'], RetrievedFacts::POINTS),
                RetrievedFacts::fact('negative_signal_points', $byCategory['negative'], RetrievedFacts::POINTS),
                RetrievedFacts::fact('recent_activity_window', $config->recentDays, RetrievedFacts::DAYS),
                RetrievedFacts::fact('no_activity_window', $config->staleDays, RetrievedFacts::DAYS),
                RetrievedFacts::fact('fit_cap', $config->fitMax, RetrievedFacts::POINTS),
                RetrievedFacts::fact('engagement_cap', $config->engagementMax, RetrievedFacts::POINTS),
                RetrievedFacts::fact('score_range', ['min' => $config->scoreMin, 'max' => $config->scoreMax], RetrievedFacts::POINTS),
            ],
            count($config->points),
            FactScope::REFERENCE,
        );
    }
}
