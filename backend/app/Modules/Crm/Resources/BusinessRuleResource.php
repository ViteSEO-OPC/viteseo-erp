<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\BusinessRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a single configurable Business Rule. `pointValue` is the absolute MAGNITUDE (§1.2) — the
 * sign is NOT encoded here; `isSubtractive` tells the UI to render a `negative` rule as a subtraction
 * (e.g. −15). Emitting the magnitude keeps the API faithful to storage and prevents double-negation.
 * `displayOrder` is presentation only and never affects any computed score.
 *
 * @mixin BusinessRule
 */
class BusinessRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'ruleKey' => $this->rule_key,
            'description' => $this->description,
            'category' => $this->category->value,
            'pointValue' => $this->point_value,        // absolute magnitude
            'isSubtractive' => $this->category->isSubtractive(), // UI renders a negative rule as −value
            'isEnabled' => $this->is_enabled,
            'displayOrder' => $this->display_order,
        ];
    }
}
