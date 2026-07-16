<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'base_salary' => (float) $this->base_salary,
            'effective_date' => $this->effective_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'change_type' => $this->change_type,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy?->name),
        ];
    }
}
