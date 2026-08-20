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

    /** How a programme decides who it covers. */
    public const ELIGIBILITY_MODES = ['all', 'status', 'management', 'custom'];

    /** What a programme DOES to a payslip — the only three behaviours there are. */
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
        // Does this allowance count toward the SSS / Pag-IBIG contribution base?
        // Paid in full either way; this decides only whether it is remuneration for contributions.
        'contributable',
        // loan-policy config (kind = loan_policy).
        'loan_max_principal',
        'loan_term_periods',
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
        'contributable' => 'boolean',
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
