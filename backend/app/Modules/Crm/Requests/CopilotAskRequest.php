<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Copilot\Retrieval\AuthorizedLead;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A question the rule-based Copilot could not answer, offered to its optional AI fallback.
 *
 * The question is the only free input. CRM context is built on the server for the caller; a client can
 * never supply it. The client may also name ONE lead it already resolved (`lead_id`, a strict positive
 * integer). That id is only a request: the server re-authorises it with LeadPolicy::view before
 * anything is read ({@see AuthorizedLead}).
 */
class CopilotAskRequest extends FormRequest
{
    public const MAX_QUESTION = 500;

    public function authorize(): bool
    {
        return true; // Route-level CRM auth group + role middleware authorize.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.self::MAX_QUESTION],
            'lead_id' => ['nullable', 'integer:strict', 'min:1'],
        ];
    }
}
