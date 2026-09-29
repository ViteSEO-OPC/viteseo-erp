<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use InvalidArgumentException;

/**
 * THE CLOSED SET of data capabilities the Copilot may supply to the model. Seven classes, listed
 * here and nowhere else; nothing can register a capability at runtime, and neither the browser nor
 * the model can name one that is not in this list. Phase 1 is aggregates, configuration and curated
 * knowledge only — no per-lead lookup, no activities, no notes, no emails.
 */
class CapabilityRegistry
{
    /** @var array<string, CopilotCapability> */
    private readonly array $capabilities;

    public function __construct(
        LeadPortfolioRetriever $portfolio,
        DashboardKpiRetriever $dashboard,
        PerformanceRetriever $performance,
        FollowUpRetriever $followUps,
        ScoringRulesRetriever $scoring,
        KnowledgeRetriever $knowledge,
        RoleExplainerRetriever $roles,
    ) {
        $all = [];
        foreach ([$portfolio, $dashboard, $performance, $followUps, $scoring, $knowledge, $roles] as $capability) {
            $all[$capability->key()] = $capability;
        }
        $this->capabilities = $all;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->capabilities);
    }

    public function get(string $key): CopilotCapability
    {
        return $this->capabilities[$key] ?? throw new InvalidArgumentException("Unknown Copilot capability: {$key}");
    }
}
