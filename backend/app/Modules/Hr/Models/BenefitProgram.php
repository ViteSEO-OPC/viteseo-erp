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
        'eligibility',
        'deduction_cadence',
        'tiers',
        'details',
        'progress',
        'footer_label',
        'is_active',
    ];

    protected $casts = [
        'details' => 'array',
        'progress' => 'array',
        'tiers' => 'array',
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'hr_benefit_enrollments',
            'benefit_program_id',
            'employee_id',
        )->withPivot(['tier', 'employee_share'])->withTimestamps();
    }
}
