<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Company → Intern Allowance → Calculation Preview. Wraps InternAllowanceService::preview(). */
class InternAllowancePreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'settings' => $this->resource['settings'],
            'corrected_fields' => $this->resource['corrected_fields'],
            'rates' => $this->resource['rates'],
            'earnings' => $this->resource['earnings'],
            'deductions' => $this->resource['deductions'],
            'totals' => $this->resource['totals'],
            'lines' => $this->resource['lines'],
            'flags' => $this->resource['flags'],
        ];
    }
}
