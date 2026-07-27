<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayslipResource extends JsonResource
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
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->user?->name,
            ],
            'base_salary' => (float) $this->base_salary,
            'reimbursements' => (float) $this->reimbursements,
            'allowances' => (float) $this->allowances,
            'holiday_premium' => (float) $this->holiday_premium,
            'overtime_pay' => (float) $this->overtime_pay,
            'overtime_minutes' => (int) $this->overtime_minutes,
            'gross' => (float) $this->gross,
            'sss' => (float) $this->sss,
            'philhealth' => (float) $this->philhealth,
            'pagibig' => (float) $this->pagibig,
            'withholding_tax' => (float) $this->withholding_tax,
            'total_deductions' => (float) $this->total_deductions,
            'net_pay' => (float) $this->net_pay,
            'status' => $this->status,
            // The HR officer who ran this payroll — for the payslip signature line.
            'processed_by' => $this->payrollRun?->runner?->name,
            // Attendance basis (step 4b/7): how base_salary was derived, so the payslip
            // explains itself. All zero on payslips generated before this shipped.
            'working_days' => (int) $this->working_days,
            'daily_rate' => (float) $this->daily_rate,
            'unpaid_days' => (float) $this->unpaid_days,
            'late_minutes' => (int) $this->late_minutes,
            'undertime_minutes' => (int) $this->undertime_minutes,
            'late_deduction' => (float) $this->late_deduction,
            'undertime_deduction' => (float) $this->undertime_deduction,
        ];
    }
}
