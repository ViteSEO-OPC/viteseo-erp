<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'days_per_year' => (float) $this->days_per_year,
            // FIN-034 — accrual settings. 'none' means this type stays hand-managed.
            'accrual_mode' => $this->accrual_mode,
            'prorate_first_year' => (bool) $this->prorate_first_year,
            'carry_over_cap_days' => $this->carry_over_cap_days === null
                ? null
                : (float) $this->carry_over_cap_days,
            'carry_over_expiry_months' => $this->carry_over_expiry_months === null
                ? null
                : (int) $this->carry_over_expiry_months,
            'is_paid' => (bool) $this->is_paid,
            'is_unlimited' => (bool) $this->is_unlimited,
            'color' => $this->color,
            'description' => $this->description,
            'allow_backdated' => (bool) $this->allow_backdated,
            'backdate_limit_days' => $this->backdate_limit_days,
        ];
    }
}
