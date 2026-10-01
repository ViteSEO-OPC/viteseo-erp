<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;

/**
 * Which lead the question is about: its business name, and nothing else.
 *
 * Supplied only for a lead the caller already passed LeadPolicy::view on ({@see AuthorizedLead}).
 * The name is scrubbed of anything contact-shaped ({@see LeadFieldAllowlist::scrub()}) and, like all
 * data, is capped and cleaned again by the context builder.
 */
class LeadSubjectRetriever implements LeadScopedCapability
{
    public const KEY = 'lead_subject';

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
        return new RetrievedFacts(
            self::KEY,
            'The one lead this question is about, which this user may view. This source gives its business name; the lead_profile source, when supplied, gives its status, priority, website status, stored lead score, last-activity date, owner label and follow-up counts. Nothing else about this lead is known.',
            [RetrievedFacts::fact('business_name', LeadFieldAllowlist::scrub((string) $lead->value('business_name')), RetrievedFacts::LABEL)],
            1,
            FactScope::LEAD,
        );
    }
}
