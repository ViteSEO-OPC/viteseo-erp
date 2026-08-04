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
            // Null for modules that have no scoring semantics (kpi): a KPI carries neither a
            // category — the sole carrier of a scoring rule's arithmetic sign — nor a point value.
            // Nullable here rather than defaulted so the UI can omit those columns entirely instead
            // of rendering a fabricated 0 or an arbitrary grouping.
            'category' => $this->category?->value,
            'pointValue' => $this->point_value,        // absolute magnitude
            'isSubtractive' => $this->category?->isSubtractive() ?? false, // UI renders a negative rule as −value
            'isEnabled' => $this->is_enabled,
            'displayOrder' => $this->display_order,
        ];
    }
}
