<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A benefit program in the Company → Benefits catalogue (HMO, retirement plan,
 * wellness allowance, open-enrolment window, …). Employees are enrolled per
 * program via the hr_benefit_enrollments pivot.
 */
class BenefitProgram extends Model
{
    use SoftDeletes;

    /**
     * How a programme decides who it covers.
     *
     *   all         — everyone
     *   status      — the employment statuses in `eligibility_statuses` (multi-select)
     *   management  — management staff only
     *   custom      — the named employees on the enrolment pivot
     *
     * The first three are RULES, evaluated when payroll runs, so a status change is followed
     * automatically. Only `custom` is a stored list, because a list of names is the rule.
     */
    public const ELIGIBILITY_MODES = ['all', 'status', 'management', 'custom'];

    /**
     * What a programme DOES to a payslip — the only three behaviours there are (B2, 2026-08-03).
     *
     *   allowance    adds to gross
     *   plan         deducts a fixed amount each period (HMO, insurance, retirement)
     *   loan_policy  the product a loan is issued under; the debt itself is `hr_employee_loans`,
     *                because a balance that decrements to zero cannot be a programme without one
     *                programme per borrower (B15)
     *
     * `company` was the old catch-all for the last two. One kind meant one loose validation shape
     * covering two things with nothing in common.
     */
    public const KINDS = ['allowance', 'plan', 'loan_policy'];

    protected $table = 'hr_benefit_programs';

    protected $fillable = [
        'name',
        'category',
        'kind',
        'amount',
        'description',
        'color',
        'status',
        'frequency',
        'tax_treatment',
        'deminimis_ceiling',
        'ceiling_period',
        // Slice B15 — loan-policy config (kind = loan_policy).
        'loan_max_principal',
        'loan_term_periods',
        'loan_interest_rate',
        'eligibility',
        'eligibility_statuses',
        'deduction_cadence',
        'tiers',
        'details',
        'progress',
        'footer_label',
    ];

    protected $casts = [
        'details' => 'array',
        'progress' => 'array',
        'tiers' => 'array',
        'eligibility_statuses' => 'array',
        'amount' => 'decimal:2',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'hr_benefit_enrollments',
            'benefit_program_id',
            'employee_id',
        )->withPivot([
            'tier', 'employee_share', 'amount', 'amount_set_by', 'amount_set_at',
            // See Employee::benefitPrograms() — omitting these made every dated enrolment read
            // as "enrolled forever".
            'enrolled_at', 'ended_at',
        ])->withTimestamps();
    }
}
