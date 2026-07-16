<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// HR management view of a benefit program — includes the enrolled employee ids.
class BenefitProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'kind' => $this->kind, // 'allowance' | 'company'
            'amount' => $this->amount,
            'description' => $this->description,
            'color' => $this->color,
            // Allowance config.
            'frequency' => $this->frequency,
            'tax_treatment' => $this->tax_treatment,
            'eligibility' => $this->eligibility,
            // HMO / company-plan config.
            'deduction_cadence' => $this->deduction_cadence,
            'tiers' => $this->tiers ?? [],
            'status' => $this->status,
            'details' => $this->details ?? [],
            'progress' => $this->progress,
            'footer_label' => $this->footer_label,
            'is_active' => $this->is_active,
            'employee_ids' => $this->whenLoaded('employees', fn () => $this->employees->pluck('id')),
            'created_at' => $this->created_at,
        ];
    }
}
