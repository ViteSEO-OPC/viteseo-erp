<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_start' => $this->cycle_start?->format('Y-m-d'),
            'cycle_end' => $this->cycle_end?->format('Y-m-d'),
            'frequency' => $this->frequency,
            'status' => $this->status,
            'total_gross' => (float) $this->total_gross,
            'total_deductions' => (float) $this->total_deductions,
            'total_net' => (float) $this->total_net,
            'employee_count' => $this->employee_count,
            'run_at' => $this->run_at,
            'run_by' => $this->runner?->name,
            // Per-employee breakdown (only when payslips are eager-loaded).
            'breakdown' => $this->whenLoaded('payslips', fn () => $this->payslips->map(fn ($p) => [
                'id' => $p->id,
                'employee' => $p->employee->user?->name,
                'department' => $p->employee->department?->name,
                'gross' => (float) $p->gross,
                'deductions' => (float) $p->total_deductions,
                'net' => (float) $p->net_pay,
                'status' => $p->status,
            ])),
        ];
    }
}
