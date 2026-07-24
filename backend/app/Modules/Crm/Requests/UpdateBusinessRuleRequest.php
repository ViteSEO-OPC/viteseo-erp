<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural contract for editing a Business Rule on the draft. STRUCTURE ONLY (§5.6): shape, type, and
 * presence. The business rules — non-negative magnitude, immutable rule identity, valid rule key — are the
 * BusinessRuleService's responsibility and are enforced there. At least one editable field must be present.
 *
 * `point_value` is a non-negative integer here (a structural bound that mirrors the §1.2 magnitude
 * invariant; the service is the authority and re-checks it). There is deliberately NO upper bound — the
 * Fit/Engagement/Overall caps are computation caps applied by the scoring engine, not per-rule ceilings.
 */
class UpdateBusinessRuleRequest extends FormRequest
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
            'point_value' => ['required_without:description', 'integer', 'min:0'],
            'description' => ['required_without:point_value', 'string', 'min:1', 'max:2000'],
        ];
    }
}
