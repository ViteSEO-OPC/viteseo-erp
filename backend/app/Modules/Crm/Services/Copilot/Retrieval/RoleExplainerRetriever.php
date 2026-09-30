<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Services\Copilot\Knowledge\KnowledgeIndex;

/**
 * The caller's OWN roles and CRM permissions, read on the server, plus the existing user-facing role
 * articles. Never another user's roles, never a user list, never suspension data. No new RBAC text is
 * written for the model — only articles already in the verified Copilot knowledge.
 */
class RoleExplainerRetriever implements CopilotCapability
{
    public const KEY = 'role_explainer';

    /** The verified role articles already in `knowledge.json`. */
    private const ARTICLES = ['roles', 'rbac-model', 'team-and-roles', 'reports-scope', 'lead-ownership'];

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
        $roles = $user->getRoleNames()->filter(fn (string $r): bool => in_array($r, ['super_admin', 'crm_admin', 'crm_bd'], true))->values()->all();
        $permissions = $user->getAllPermissions()->pluck('name')->filter(fn (string $p): bool => str_starts_with($p, 'crm.'))->sort()->values()->all();

        $facts = [
            RetrievedFacts::fact('your_roles', $roles, RetrievedFacts::LABEL),
            RetrievedFacts::fact('your_crm_permissions', $permissions, RetrievedFacts::LABEL),
        ];
        foreach ($this->index->get(self::ARTICLES) as $article) {
            $facts[] = RetrievedFacts::fact($article['title'], $article['text'], RetrievedFacts::TEXT);
        }

        return new RetrievedFacts(
            self::KEY,
            "This user's own CRM roles and permissions (super_admin = Super Admin, crm_admin = Admin, crm_bd = Business Development Manager), and the CRM's help articles on roles and visibility.",
            $facts,
            count($facts),
            FactScope::REFERENCE,
        );
    }
}
