<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Company → Intern Allowance: the rules in force today, every version, and the choices each
 * setting accepts. Wraps InternAllowanceService::overview(), which is already in response shape.
 */
class InternAllowanceSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'settings' => $this->resource['settings'],
            'effective_from' => $this->resource['effective_from'],
            'saved_effective_from' => $this->resource['saved_effective_from'],
            'corrected_fields' => $this->resource['corrected_fields'],
            'options' => $this->resource['options'],
            'versions' => $this->resource['versions'],
        ];
    }
}
