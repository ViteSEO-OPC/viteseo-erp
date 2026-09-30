<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;

/**
 * WHOSE figures a set of facts is — travels with every fact so the model can word it correctly and
 * the validator can refuse an answer that attaches a figure to the wrong subject.
 *
 *   own          — the caller's own leads/work (a BD's view).
 *   organization — the whole CRM (an Admin/Super Admin's view). Never "your leads".
 *   reference    — rules and help text; describes no one's data.
 */
final class FactScope
{
    public const OWN = 'own';

    public const ORGANIZATION = 'organization';

    public const REFERENCE = 'reference';

    /**
     * The scope of every `visibleTo`-scoped figure for this viewer. Mirrors the role check in
     * Lead::scopeVisibleTo and PlannerEvent::scopeVisibleTo (Admin/SA: the whole CRM; anyone else:
     * their own); RetrieverAuthorizationTest pins the two together.
     */
    public static function forViewer(User $user): string
    {
        return $user->hasAnyRole(['crm_admin', 'super_admin']) ? self::ORGANIZATION : self::OWN;
    }
}
