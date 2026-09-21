<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_payslips';

    protected $fillable = [
        'payroll_run_id', 'employee_id',
        // An intern's slip: an allowance in the salary columns, priced under Company → Intern
        // Allowance. Snapshot, so a converted intern's old slips still read as intern slips.
        'is_intern', 'intern_basis',
        'base_salary', 'reimbursements', 'allowances', 'holiday_premium', 'holiday_lines', 'gross',
        // Payslip redesign — the full period basic, the absence charge taken off it, and the
        // per-component allowance split, so the document can show the derivation.
        'period_base', 'absence_deduction', 'allowances_breakdown',
        // Positive pay lines — must be fillable or run()'s updateOrCreate drops them.
        'overtime_pay', 'overtime_minutes', 'night_diff_pay', 'night_diff_minutes',
        'rest_day_premium', 'rest_day_minutes',
        // What this slip paid as 13th month, this period's contribution to the
        // ₱90,000 bucket, and the taxable pay behind it. Stored so a year can be read back from
        // what was actually paid rather than recomputed from today's configuration.
        'thirteenth_month', 'other_benefits', 'taxable_pay',
        // The year-end true-up portion of withholding_tax. Signed: positive collected,
        // negative refunded.
        'tax_annualisation',
        // The year's taxable pay, tax due and prior withholding behind that adjustment.
        'tax_annualisation_basis',
        // An off-cycle corrective amount and what it is for.
        'adjustment_pay', 'adjustment_note',
        // The terminal amounts on a final-pay payslip.
        'separation_pay', 'separation_pay_taxable',
        'leave_conversion', 'leave_conversion_days', 'loan_settlement',
        'sss', 'philhealth', 'pagibig', 'withholding_tax',
        // non-statutory deductions (benefit shares) + their breakdown.
        'other_deductions', 'other_deductions_breakdown',
        // Interest collected on loans this period. Its own field so the payslip can show
        // the cost of borrowing separately from the repayment, and it must be fillable or run()'s
        // updateOrCreate silently drops it.
        'loan_interest',
        'employer_sss', 'employer_philhealth', 'employer_pagibig',
        // Which statutory deductions came from a per-employee override, not the rates.
        'contribution_overrides',
        // The salary figure each contribution was actually charged on. Not derivable
        // after the fact once a contribution moves onto the earnings basis.
        'contribution_bases',
        'total_deductions', 'deduction_carryover', 'deduction_carryover_breakdown',
        // Arrears from earlier periods recovered on this payslip.
        'arrears_recovered', 'arrears_recovered_breakdown',
        'net_pay', 'status',
        // Who declared this paid, when, and the request key that did it.
        'disbursed_at', 'disbursed_by', 'disbursement_key',
        // What actually left the company, frozen at disbursement. See the amendments migration:
        // amending a paid payslip raises its net, and without this the difference would read as
        // money already transferred.
        'disbursed_amount',
        // Step 4b — how base_salary was derived, so a payslip can explain itself.
        'working_days', 'daily_rate', 'unpaid_days',
        'late_minutes', 'undertime_minutes', 'late_deduction', 'undertime_deduction',
        'late_penalty_days',
        // Why those figures are what they are: the per-date charge ledger, and the withholding
        // derivation. Snapshots — see the migration for why neither is derived at read time.
        'attendance_lines', 'tax_basis',
        // The engine's own figures for this period, before any correction HR made to a line.
        // Written by run(); the baseline every override is measured against and restored to.
        'engine_snapshot',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'period_base' => 'decimal:2',
        'absence_deduction' => 'decimal:2',
        'allowances_breakdown' => 'array',
        'holiday_lines' => 'array',
        'working_days' => 'integer',
        'daily_rate' => 'decimal:2',
        'unpaid_days' => 'decimal:2',
        'late_minutes' => 'integer',
        'undertime_minutes' => 'integer',
        'late_deduction' => 'decimal:2',
        'undertime_deduction' => 'decimal:2',
        'late_penalty_days' => 'decimal:2',
        'attendance_lines' => 'array',
        'is_intern' => 'boolean',
        'intern_basis' => 'array',
        'tax_basis' => 'array',
        'engine_snapshot' => 'array',
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
        // Contribution type => the monthly salary figure it was charged on.
        'contribution_bases' => 'array',
        // How the year-end tax settlement was arrived at.
        'tax_annualisation_basis' => 'array',
        'total_deductions' => 'decimal:2',
        'deduction_carryover' => 'decimal:2',
        // Obligation => uncollected amount. Null when nothing went short.
        'deduction_carryover_breakdown' => 'array',
        'arrears_recovered' => 'decimal:2',
        'arrears_recovered_breakdown' => 'array',
        'net_pay' => 'decimal:2',
        'disbursed_amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    /** WHAT IS STILL OWED ON THIS PAYSLIP — its net less what has actually been paid against it. */
    public function amountOutstanding(): float
    {
        return round((float) $this->net_pay - (float) ($this->disbursed_amount ?? 0), 2);
    }

    /** Has money been paid against this payslip and more is now due? */
    public function isPartiallyPaid(): bool
    {
        return $this->disbursed_amount !== null && $this->amountOutstanding() > 0;
    }

    /**
     * THE THREE HEADLINE FIGURES — gross, everything taken off it, and what was paid.
     *
     * @return array{gross: float, deductions: float, net: float}
     */
    public function registerTotals(): array
    {
        $gross = round((float) $this->gross + (float) $this->absence_deduction, 2);
        $net = round((float) $this->net_pay, 2);

        return [
            'gross' => $gross,
            'deductions' => round($gross - $net, 2),
            'net' => $net,
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** The transfers raised against this payslip. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The corrections added to this payslip after it was computed, oldest first.
     *
     * The ledger `adjustment_pay` totals — and what a re-run of this period re-applies, so
     * recomputing from attendance cannot erase a correction. See PayslipAmendment.
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(PayslipAmendment::class)->orderBy('issued_on')->orderBy('id');
    }
}
