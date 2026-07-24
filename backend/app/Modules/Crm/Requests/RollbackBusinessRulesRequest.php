<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural contract for a forward-only rollback. STRUCTURE ONLY (§5.6): `target_version` is the required
 * version number to restore, plus an optional Reason for Change. Eligibility (target must be a previously
 * published version, not a draft, not the already-active one, and must itself validate) is enforced by
 * BusinessRuleService.
 */
class RollbackBusinessRulesRequest extends FormRequest
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
            'target_version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
