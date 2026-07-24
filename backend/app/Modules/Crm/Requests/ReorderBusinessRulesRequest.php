<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural contract for reordering Business Rules on the draft. STRUCTURE ONLY (§5.6): `order` is a
 * non-empty array of rule-key strings, in the desired display order. The business rule — that the list is a
 * permutation of exactly the draft's rule keys (no additions/omissions) — is enforced by
 * BusinessRuleService. Reordering is presentation only and never affects a computed score.
 */
class ReorderBusinessRulesRequest extends FormRequest
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
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string'],
        ];
    }
}
