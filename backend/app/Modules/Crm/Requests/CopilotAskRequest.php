<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A question the rule-based Copilot could not answer, offered to its optional AI fallback.
 *
 * The question is the ONLY input. CRM context is built on the server for the caller; a client can
 * never supply it.
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
        ];
    }
}
