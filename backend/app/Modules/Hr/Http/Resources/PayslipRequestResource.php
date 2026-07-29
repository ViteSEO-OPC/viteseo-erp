<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'payslip_id' => $this->payslip_id,
            'payslip_cycle' => $this->payslipCycle(),
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee->photo_url),
            ],
            'created_at' => $this->created_at,
        ];
    }

    private function payslipCycle(): ?string
    {
        $run = $this->payslip?->payrollRun;

        return $run ? "{$run->cycle_start->format('Y-m-d')} – {$run->cycle_end->format('Y-m-d')}" : null;
    }
}
