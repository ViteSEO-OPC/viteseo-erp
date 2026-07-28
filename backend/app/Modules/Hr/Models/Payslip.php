<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payslips';

    protected $fillable = [
        'payroll_run_id', 'employee_id',
        'base_salary', 'reimbursements', 'allowances', 'holiday_premium', 'gross',
        // Positive pay lines (OT-1 / ND-1) — must be fillable or run()'s updateOrCreate drops them.
        'overtime_pay', 'overtime_minutes', 'night_diff_pay', 'night_diff_minutes',
        'rest_day_premium', 'rest_day_minutes',
        'sss', 'philhealth', 'pagibig', 'withholding_tax',
        // Slice 11a — non-statutory deductions (benefit shares) + their breakdown.
        'other_deductions', 'other_deductions_breakdown',
        'employer_sss', 'employer_philhealth', 'employer_pagibig',
        'total_deductions', 'deduction_carryover', 'net_pay', 'status',
        // Step 4b — how base_salary was derived, so a payslip can explain itself.
        'working_days', 'daily_rate', 'unpaid_days',
        'late_minutes', 'undertime_minutes', 'late_deduction', 'undertime_deduction',
        'late_penalty_days',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'working_days' => 'integer',
        'daily_rate' => 'decimal:2',
        'unpaid_days' => 'decimal:2',
        'late_minutes' => 'integer',
        'undertime_minutes' => 'integer',
        'late_deduction' => 'decimal:2',
        'undertime_deduction' => 'decimal:2',
        'late_penalty_days' => 'decimal:2',
        'reimbursements' => 'decimal:2',
        'allowances' => 'decimal:2',
        'holiday_premium' => 'decimal:2',
        'overtime_pay' => 'decimal:2',
        'overtime_minutes' => 'integer',
        'night_diff_pay' => 'decimal:2',
        'night_diff_minutes' => 'integer',
        'rest_day_premium' => 'decimal:2',
        'rest_day_minutes' => 'integer',
        'gross' => 'decimal:2',
        'sss' => 'decimal:2',
        'philhealth' => 'decimal:2',
        'pagibig' => 'decimal:2',
        'withholding_tax' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'other_deductions_breakdown' => 'array',
        'employer_sss' => 'decimal:2',
        'employer_philhealth' => 'decimal:2',
        'employer_pagibig' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'deduction_carryover' => 'decimal:2',
        'net_pay' => 'decimal:2',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
