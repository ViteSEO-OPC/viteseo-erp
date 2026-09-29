<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;

/**
 * One read-only data capability the Copilot may supply to the model.
 *
 * The contract every implementation keeps:
 *   - The user is the AUTHENTICATED SERVER USER, passed in by the service — never a value from the
 *     request body. There is no other input: no ids, no filters, nothing the model or browser chose.
 *   - `permits()` mirrors the permission the equivalent screen/endpoint already requires, so the
 *     Copilot can never show something the user could not already see.
 *   - `retrieve()` only READS, through the existing ownership scopes, and returns aggregates or
 *     configuration — never a lead row, lead id, contact field or user id.
 */
interface CopilotCapability
{
    public function key(): string;

    public function permits(User $user): bool;

    public function retrieve(User $user, string $question): RetrievedFacts;
}
