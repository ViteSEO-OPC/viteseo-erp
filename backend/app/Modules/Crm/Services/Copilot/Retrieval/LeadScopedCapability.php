<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;

/**
 * One read-only data capability about ONE lead the caller asked about.
 *
 * The contract every implementation keeps, on top of {@see CopilotCapability}'s:
 *   - The lead arrives as an {@see AuthorizedLead}, which only exists once the server has re-run
 *     LeadPolicy::view for this user. A capability never takes a raw id and never looks a lead up.
 *   - It reads lead columns only through {@see AuthorizedLead::value()} (the allowlist), and related
 *     records only through queries scoped to `$lead->id()` and the existing visibility scopes.
 *   - It returns facts with scope {@see FactScope::LEAD}, and never a contact field, an address, a
 *     link, a user id, raw free text from notes/emails/activities, or the lead id itself.
 */
interface LeadScopedCapability
{
    public function key(): string;

    public function permits(User $user): bool;

    public function retrieve(User $user, AuthorizedLead $lead, string $question): RetrievedFacts;
}
