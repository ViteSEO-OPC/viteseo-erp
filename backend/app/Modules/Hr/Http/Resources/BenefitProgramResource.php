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
            'kind' => $this->kind, // allowance (adds to gross) | plan (deducts per period) | loan_policy
            'amount' => $this->amount,
            'description' => $this->description,
            'color' => $this->color,
            // Allowance config.
            'frequency' => $this->frequency,
            'tax_treatment' => $this->tax_treatment,
            // Slice B16 — this benefit's own de-minimis ceiling and the period it is stated in.
            'deminimis_ceiling' => $this->deminimis_ceiling,
            'ceiling_period' => $this->ceiling_period,
            // Slice B15 — loan-policy config.
            'loan_max_principal' => $this->loan_max_principal,
            'loan_term_periods' => $this->loan_term_periods,
            'loan_interest_rate' => $this->loan_interest_rate,
            'eligibility' => $this->eligibility,
            'eligibility_statuses' => $this->eligibility_statuses ?? [],
            // How many people the rule covers right now — the honest version of "who is on this".
            'covered_count' => app(\App\Modules\Hr\Services\BenefitProgramService::class)
                ->eligibleCount($this->resource),
            // Slice B14 — of those, how many an edit to this amount would actually reach. The
            // dialog claimed "14 enrolled employees will be affected" when the true answer was
            // nought (B6); it has to be able to say "12 following · 2 with their own amount".
            'reach' => app(\App\Modules\Hr\Services\BenefitProgramService::class)
                ->reachOf($this->resource),
            // HMO / company-plan config.
            'deduction_cadence' => $this->deduction_cadence,
            'tiers' => $this->tiers ?? [],
            'status' => $this->status,
            'details' => $this->details ?? [],
            'progress' => $this->progress,
            'footer_label' => $this->footer_label,
            'employee_ids' => $this->whenLoaded('employees', fn () => $this->employees->pluck('id')),
            'created_at' => $this->created_at,
        ];
    }
}
