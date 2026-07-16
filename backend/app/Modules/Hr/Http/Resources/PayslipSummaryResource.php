<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employee's payslip HISTORY row — pay period + request state only, NO amounts.
 * Figures stay hidden until an approved payslip request unlocks the full payslip
 * (served by PayslipResource via showPayslip). `request_status` / `unlocked` are
 * derived attributes set in PayrollService::myPayslips().
 */
class PayslipSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_start' => $this->payrollRun?->cycle_start?->format('Y-m-d'),
            'cycle_end' => $this->payrollRun?->cycle_end?->format('Y-m-d'),
            'status' => $this->status,
            // 'none' | 'pending' | 'approved' | 'rejected' — drives Request/Pending/View.
            'request_status' => $this->request_status ?? 'none',
            'unlocked' => (bool) ($this->unlocked ?? false),
        ];
    }
}
