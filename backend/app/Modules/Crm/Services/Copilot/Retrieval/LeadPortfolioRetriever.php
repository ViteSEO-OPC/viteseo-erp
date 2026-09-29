<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Copilot\Knowledge\LeadVocabulary;

/**
 * The caller's lead counts: total, and by status, priority and website status.
 *
 * Scope: `Lead::active()->visibleTo($user)` inside the org global scope — the dashboard's population.
 * Output: counts only. A stored value outside {@see LeadVocabulary} is counted under `other`, so free
 * text in a category column can never reach the model.
 */
class LeadPortfolioRetriever implements CopilotCapability
{
    public const KEY = 'lead_portfolio';

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return $user->can('crm.leads.view');
    }

    public function retrieve(User $user, string $question): RetrievedFacts
    {
        $total = Lead::query()->active()->visibleTo($user)->count();

        $facts = [RetrievedFacts::fact('total_leads', $total, RetrievedFacts::COUNT)];
        foreach (['status' => 'leads_by_status', 'priority' => 'leads_by_priority', 'website_status' => 'leads_by_website_status'] as $column => $name) {
            $facts[] = RetrievedFacts::fact($name, $this->countBy($user, $column), RetrievedFacts::COUNT);
        }

        return new RetrievedFacts(
            self::KEY,
            'Lead counts over the leads this user may see (a Business Development Manager sees only their own leads; Admins see the whole CRM). Archived leads are excluded.',
            $facts,
            $total,
            FactScope::forViewer($user),
        );
    }

    /** @return array<string, int> */
    private function countBy(User $user, string $column): array
    {
        $rows = Lead::query()
            ->active()
            ->visibleTo($user)
            ->selectRaw("{$column} as bucket, COUNT(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', 'bucket');

        $counts = [];
        foreach ($rows as $bucket => $count) {
            $key = LeadVocabulary::normalize($column, $bucket);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $count;
        }
        ksort($counts);

        return $counts;
    }
}
