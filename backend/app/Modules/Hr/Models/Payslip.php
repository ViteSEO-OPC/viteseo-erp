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
        // FIN-054 — the year's taxable pay, tax due and prior withholding behind that adjustment.
        'tax_annualisation_basis',
        // FIN-010 — an off-cycle corrective amount and what it is for.
        'adjustment_pay', 'adjustment_note',
        // FIN-014 — the terminal amounts on a final-pay payslip.
        'separation_pay', 'separation_pay_taxable',
        'leave_conversion', 'leave_conversion_days', 'loan_settlement',
        'sss', 'philhealth', 'pagibig', 'withholding_tax',
        // Slice 11a — non-statutory deductions (benefit shares) + their breakdown.
        'other_deductions', 'other_deductions_breakdown',
        // FIN-032 — interest collected on loans this period. Its own field so the payslip can show
        // the cost of borrowing separately from the repayment, and it must be fillable or run()'s
        // updateOrCreate silently drops it.
        'loan_interest',
        'employer_sss', 'employer_philhealth', 'employer_pagibig',
        // Slice B7 — which statutory deductions came from a per-employee override, not the rates.
        'contribution_overrides',
        // Slice B19 — the salary figure each contribution was actually charged on. Not derivable
        // after the fact once a contribution moves onto the earnings basis.
        'contribution_bases',
        'total_deductions', 'deduction_carryover', 'deduction_carryover_breakdown',
        // FIN-005 — arrears from earlier periods recovered on this payslip.
        'arrears_recovered', 'arrears_recovered_breakdown',
        'net_pay', 'status',
        // FIN-033 — who declared this paid, when, and the request key that did it.
        'disbursed_at', 'disbursed_by', 'disbursement_key',
        // Step 4b — how base_salary was derived, so a payslip can explain itself.
        'working_days', 'daily_rate', 'unpaid_days',
        'late_minutes', 'undertime_minutes', 'late_deduction', 'undertime_deduction',
        'late_penalty_days',
        // Why those figures are what they are: the per-date charge ledger, and the withholding
        // derivation. Snapshots — see the migration for why neither is derived at read time.
        'attendance_lines', 'tax_basis',
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
        'attendance_lines' => 'array',
        'tax_basis' => 'array',
        'reimbursements' => 'decimal:2',
        'adjustment_pay' => 'decimal:2',
        'separation_pay' => 'decimal:2',
        'separation_pay_taxable' => 'boolean',
        'leave_conversion' => 'decimal:2',
        'leave_conversion_days' => 'decimal:2',
        'loan_settlement' => 'decimal:2',
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
        'loan_interest' => 'decimal:2',
        'other_deductions_breakdown' => 'array',
        'employer_sss' => 'decimal:2',
        'employer_philhealth' => 'decimal:2',
        'employer_pagibig' => 'decimal:2',
        'contribution_overrides' => 'array',
        // Slice B19 — contribution type => the monthly salary figure it was charged on.
        'contribution_bases' => 'array',
        // FIN-054 — how the year-end tax settlement was arrived at.
        'tax_annualisation_basis' => 'array',
        'total_deductions' => 'decimal:2',
        'deduction_carryover' => 'decimal:2',
        // FIN-053 — obligation => uncollected amount. Null when nothing went short.
        'deduction_carryover_breakdown' => 'array',
        'arrears_recovered' => 'decimal:2',
        'arrears_recovered_breakdown' => 'array',
        'net_pay' => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /**
     * The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM.
     *
     * Without it the default scope resolves this to null the moment somebody leaves, and every
     * Resource that reads a name through it throws, taking the whole list endpoint down with it —
     * one 500 for the entire page because one row belonged to a leaver. Their history does not
     * stop being theirs when they go, and a record that cannot say whose it is is worse than one
     * that names someone who has left.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
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
