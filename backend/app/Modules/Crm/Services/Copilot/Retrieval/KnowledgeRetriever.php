<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\Copilot\Knowledge\KnowledgeIndex;

/**
 * The three curated, user-facing articles that best match the question — how figures are computed,
 * what statuses mean, how the CRM works. Reference text for the model to explain from.
 */
class KnowledgeRetriever implements CopilotCapability
{
    public const KEY = 'knowledge';

    public function __construct(private readonly KnowledgeIndex $index) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return true;
    }

    public function retrieve(User $user, string $question): RetrievedFacts
    {
        $articles = $this->index->search($question);

        return new RetrievedFacts(
            self::KEY,
            "Curated CRM help articles that may be relevant. Use them to explain how the CRM works and how figures are defined; they describe rules, not this user's data.",
            array_map(
                static fn (array $a): array => RetrievedFacts::fact($a['title'], $a['text'], RetrievedFacts::TEXT),
                $articles,
            ),
            count($articles),
            FactScope::REFERENCE,
        );
    }
}
