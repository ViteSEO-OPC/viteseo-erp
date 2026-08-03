<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employees';

    protected $fillable = [
        'user_id',
        'department_id',
        'manager_id',
        'work_schedule_id',
        'employee_no',
        'photo_path',
        'job_title',
        'employment_status',
        'employment_type',
        'work_location',
        'employee_role',
        'onboarding_status',
        'separation_type',
        // The employee's last working day — bounds their final payslip (Slice 4).
        'separation_date',
        'hire_date',
        'phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'bank_name',
        'bank_account',
        'base_salary',
        'is_management',
        'pay_frequency',
        'currency',
        'bank_account_type',
        'bank_account_name',
        'sss_contribution',
        'pagibig_contribution',
        'philhealth_contribution',
        // Membership NUMBERS for the payslip header — not the peso overrides above.
        'tin',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'custom_workdays',
        'custom_start_time',
        'custom_end_time',
        'custom_grace_minutes',
        'custom_unpaid_break_minutes',
        'custom_paid_break_minutes',
        // Expanded personal record.
        'first_name',
        'middle_name',
        'last_name',
        'name_extension',
        'gender',
        'civil_status',
        'nationality',
        'date_of_birth',
        'place_of_birth',
        'current_address',
        'mothers_maiden_name',
        'fathers_name',
        'siblings',
        'number_of_siblings',
        'high_school',
        'college_course',
        'university',
        'emergency_contact_relationship',
        'height',
        'weight',
        'health_condition',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'separation_date' => 'date',
        'date_of_birth' => 'date',
        'base_salary' => 'decimal:2',
        'is_management' => 'boolean',
        // One entry per named sibling: [{ name, gender }].
        'siblings' => 'array',
        'number_of_siblings' => 'integer',
        'custom_workdays' => 'array',
        'custom_grace_minutes' => 'integer',
        'custom_unpaid_break_minutes' => 'integer',
        'custom_paid_break_minutes' => 'integer',
    ];

    /** Management staff: fixed salary + exempt from attendance. */
    public function isManagement(): bool
    {
        return (bool) $this->is_management;
    }

    /**
     * Browser-facing URL for the profile picture, or null to fall back to initials.
     *
     * Lives here rather than in a Resource because the face belongs everywhere a person
     * is listed — a dozen resources embed an identity block, and each building the URL
     * itself is a dozen chances to build it differently. Deliberately not in `$appends`:
     * every payload that carries it names it.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function reimbursements(): HasMany
    {
        return $this->hasMany(Reimbursement::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(EmployeeBenefit::class);
    }

    /** Benefit programs (HMO, retirement, …) this employee is enrolled in. */
    public function benefitPrograms(): BelongsToMany
    {
        return $this->belongsToMany(
            BenefitProgram::class,
            'hr_benefit_enrollments',
            'employee_id',
            'benefit_program_id',
        )
            // The per-employee columns on the pivot. `employee_share` is what payroll deducts
            // (Slice 11a) — without this it reads as null and nothing is ever taken. There is no
            // per-employee allowance AMOUNT: an allowance is worth what its programme says (B6).
            ->withPivot(['tier', 'employee_share'])
            ->withTimestamps();
    }

    public function salaryHistories(): HasMany
    {
        return $this->hasMany(SalaryHistory::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function employmentHistories(): HasMany
    {
        // Current role (null end date) first, then most-recent past roles.
        return $this->hasMany(EmploymentHistory::class)
            ->orderByRaw('end_date IS NULL DESC')
            ->orderByDesc('start_date');
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->latest();
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }
}
