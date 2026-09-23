<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the conversation inbox list.
 *
 * EVERY FILTER IS AN ALLOWLIST, not free text handed to a query builder. `channel`, `provider` and
 * `status` are matched against the values the inbound pipeline can actually produce, so a crafted
 * parameter cannot widen the query or smuggle SQL into it.
 *
 * `org_id` and `tenant_id` are PROHIBITED rather than merely ignored. The tenant is decided by the
 * authenticated session through BelongsToOrg, never by a request parameter, and a request that
 * tries to choose one should be told plainly that it cannot — a silently dropped parameter is how a
 * caller comes to believe it worked.
 */
class ConversationIndexRequest extends FormRequest
{
    /** Authorization is the route's: auth:sanctum + the CRM group + permission:crm.conversations.view. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['sometimes', 'string', 'in:'.implode(',', (array) config('inbound.channels'))],
            'provider' => ['sometimes', 'string', 'in:'.implode(',', (array) config('inbound.providers'))],
            'status' => ['sometimes', 'string', 'in:open,closed,archived'],
            // "Is this thread attached to a lead yet?" — the single most useful triage question in
            // an inbox where inbound never creates a lead by itself.
            'lead' => ['sometimes', 'string', 'in:linked,unlinked'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],

            'org_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'The organisation is taken from your session and cannot be supplied.',
            'tenant_id.prohibited' => 'The organisation is taken from your session and cannot be supplied.',
        ];
    }
}
