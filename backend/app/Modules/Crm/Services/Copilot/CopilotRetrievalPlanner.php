<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Modules\Crm\Services\Copilot\Retrieval\DashboardKpiRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\FollowUpRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\KnowledgeRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\LeadPortfolioRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\PerformanceRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\RoleExplainerRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\ScoringRulesRetriever;

/**
 * Which DATA capabilities run for a question. DATA SELECTION ONLY: this returns capability keys and
 * nothing else. It never produces, shapes or chooses answer text — the model writes every answer.
 *
 * A baseline of cheap aggregates always runs, so the model can reason about the pipeline in any
 * phrasing. The heavier or more sensitive capabilities run only when the question is about them,
 * which keeps the context minimal. Whether a capability then actually runs is still decided by its
 * own permission check — selection here grants nothing.
 */
class CopilotRetrievalPlanner
{
    /** Always selected. */
    private const BASELINE = [
        LeadPortfolioRetriever::KEY,
        DashboardKpiRetriever::KEY,
        FollowUpRetriever::KEY,
        KnowledgeRetriever::KEY,
    ];

    /** Selected only when the question mentions them (English and Tagalog). */
    private const TRIGGERED = [
        PerformanceRetriever::KEY => '/\b(target|goal|quota|performance|perform|conversion|convert\w*|revenue|pipeline value|sales|benta|kita|kpi|engagement|interest rate|progress|how am i doing|kumusta|won|deals?|missed|activity volume|focus|unahin|uunahin|prioriti[sz]e)\b/u',
        ScoringRulesRetriever::KEY => '/\b(score\w*|scoring|points?|puntos|signals?|weights?|rules?)\b/u',
        RoleExplainerRetriever::KEY => '/\b(roles?|permissions?|access|allowed|admin|super admin|bd|business development|manager|makita|pwede|bawal|can i|why can\'?t i)\b/u',
    ];

    /** @return list<string> */
    public function plan(string $question): array
    {
        $text = mb_strtolower($question);
        $keys = self::BASELINE;
        foreach (self::TRIGGERED as $key => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
