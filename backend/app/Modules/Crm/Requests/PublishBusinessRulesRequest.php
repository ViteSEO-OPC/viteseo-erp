<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural contract for publishing the current draft. STRUCTURE ONLY (§5.6): an optional free-text
 * Reason for Change. Whether a draft exists and is complete/publishable is the BusinessRuleService's
 * responsibility (it validates and, on success, transitions atomically).
 */
class PublishBusinessRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route authorizes via permission:crm.business_rules.manage.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
