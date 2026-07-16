<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employee's payslip HISTORY row — pay period + state only, NO amounts. A payslip
 * is `unlocked` (viewable) once its payroll run is finalized; the full figures come
 * from PayslipResource via showPayslip. `hardcopy_status` is the state of any signed
 * hard-copy request for the period. Both are derived in PayrollService::myPayslips().
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
            // Viewable once the run is finalized — no request needed.
            'unlocked' => (bool) ($this->unlocked ?? false),
            // 'none' | 'pending' | 'approved' | 'rejected' — signed hard-copy request state.
            'hardcopy_status' => $this->hardcopy_status ?? 'none',
        ];
    }
}
