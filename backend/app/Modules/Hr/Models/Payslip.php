<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payslips';

    protected $fillable = [
        'payroll_run_id', 'employee_id',
        'base_salary', 'reimbursements', 'allowances', 'holiday_premium', 'gross',
        // Payslip redesign — the full period basic, the absence charge taken off it, and the
        // per-component allowance split, so the document can show the derivation.
        'period_base', 'absence_deduction', 'allowances_breakdown',
        // Positive pay lines (OT-1 / ND-1) — must be fillable or run()'s updateOrCreate drops them.
        'overtime_pay', 'overtime_minutes', 'night_diff_pay', 'night_diff_minutes',
        'rest_day_premium', 'rest_day_minutes',
        // Slice B17 — what this slip paid as 13th month, this period's contribution to the
        // ₱90,000 bucket, and the taxable pay behind it. Stored so a year can be read back from
        // what was actually paid rather than recomputed from today's configuration.
        'thirteenth_month', 'other_benefits', 'taxable_pay',
        // FIN-028 — the year-end true-up portion of withholding_tax. Signed: positive collected,
        // negative refunded.
        'tax_annualisation',
        // FIN-010 — an off-cycle corrective amount and what it is for.
        'adjustment_pay', 'adjustment_note',
        'sss', 'philhealth', 'pagibig', 'withholding_tax',
        // Slice 11a — non-statutory deductions (benefit shares) + their breakdown.
        'other_deductions', 'other_deductions_breakdown',
        'employer_sss', 'employer_philhealth', 'employer_pagibig',
        // Slice B7 — which statutory deductions came from a per-employee override, not the rates.
        'contribution_overrides',
        'total_deductions', 'deduction_carryover', 'deduction_carryover_breakdown', 'net_pay', 'status',
        // Step 4b — how base_salary was derived, so a payslip can explain itself.
        'working_days', 'daily_rate', 'unpaid_days',
        'late_minutes', 'undertime_minutes', 'late_deduction', 'undertime_deduction',
        'late_penalty_days',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'period_base' => 'decimal:2',
        'absence_deduction' => 'decimal:2',
        'allowances_breakdown' => 'array',
        'working_days' => 'integer',
        'daily_rate' => 'decimal:2',
        'unpaid_days' => 'decimal:2',
        'late_minutes' => 'integer',
        'undertime_minutes' => 'integer',
        'late_deduction' => 'decimal:2',
        'undertime_deduction' => 'decimal:2',
        'late_penalty_days' => 'decimal:2',
        'reimbursements' => 'decimal:2',
        'adjustment_pay' => 'decimal:2',
        'tax_annualisation' => 'decimal:2',
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
        'contribution_overrides' => 'array',
        'total_deductions' => 'decimal:2',
        'deduction_carryover' => 'decimal:2',
        // FIN-053 — obligation => uncollected amount. Null when nothing went short.
        'deduction_carryover_breakdown' => 'array',
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

    /**
     * The transfers raised against this payslip (FIN-015).
     *
     * Normally one. More than one is legitimate after a failed transfer is re-issued, which is why
     * this is a `hasMany` and why "has it been paid?" is a question about payment STATUS rather
     * than about the existence of a row.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
