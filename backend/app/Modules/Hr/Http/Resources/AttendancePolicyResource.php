<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendancePolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consecutive_lates_before_absence' => (int) $this->consecutive_lates_before_absence,
            'monthly_late_reset' => (bool) $this->monthly_late_reset,
            'awol_days_threshold' => (int) $this->awol_days_threshold,
            'late_deduction_mode' => $this->late_deduction_mode,
            'lates_per_deduction' => (int) $this->lates_per_deduction,
            'task_format' => $this->task_format,
        ];
    }
}
