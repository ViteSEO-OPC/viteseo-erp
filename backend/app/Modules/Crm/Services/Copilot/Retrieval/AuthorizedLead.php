<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Facades\Gate;
use JsonSerializable;
use LogicException;

/**
 * One lead the Copilot may talk about, re-authorised on the server for the authenticated user.
 *
 * The browser may name a lead it already resolved (`lead_id`), and nothing about that id is trusted.
 * {@see resolve()} is the ONLY way to obtain an instance, and it refuses unless ALL of these hold:
 *   - the lead is in the caller's org (the BelongsToOrg global scope) and not deleted;
 *   - it is an active pipeline lead (`Lead::active()` — never an unqualified prospect);
 *   - it is in the caller's `visibleTo` scope; and
 *   - LeadPolicy::view allows it — the same check Lead Details, per-lead activities and the email
 *     thread authorise through.
 *
 * The lookup selects only the allowlisted columns and the policy's inputs, and this object keeps
 * copies of the SENDABLE values alone — never the Lead model — so it cannot serialise one, and
 * {@see value()} refuses any column that is not sendable.
 */
final class AuthorizedLead implements JsonSerializable
{
    /** @param  array<string, mixed>  $values  SENDABLE columns only */
    private function __construct(
        private readonly int $id,
        private readonly array $values,
    ) {}

    public static function resolve(User $user, int $leadId): ?self
    {
        $lead = Lead::query()
            ->active()
            ->visibleTo($user)
            ->whereKey($leadId)
            ->first([...LeadFieldAllowlist::AUTHORIZATION_ONLY, ...LeadFieldAllowlist::SENDABLE]);

        if ($lead === null || ! Gate::forUser($user)->allows('view', $lead)) {
            return null;
        }

        $values = [];
        foreach (LeadFieldAllowlist::SENDABLE as $column) {
            $values[$column] = $lead->getAttribute($column);
        }

        return new self((int) $lead->getKey(), $values);
    }

    /** For server-side queries scoped to this lead. Never supplied to the model. */
    public function id(): int
    {
        return $this->id;
    }

    public function value(string $column): mixed
    {
        if (! in_array($column, LeadFieldAllowlist::SENDABLE, true)) {
            throw new LogicException("Lead column [{$column}] is not sendable to the Copilot.");
        }

        return $this->values[$column];
    }

    /** Nothing: an authorised lead is never itself part of any payload. */
    public function jsonSerialize(): object
    {
        return (object) [];
    }
}
