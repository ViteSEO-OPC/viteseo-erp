<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToOrg;
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
        // The employee's last working day — bounds their final payslip.
        'separation_date',
        'previous_hire_date',
        'hire_date',
        'phone',
        // The two addresses, as parts. `address` / `current_address` are the
        // pre-split free-text values, kept read-only for what was recorded before.
        'address',
        'address_house_no',
        'address_street',
        'address_barangay',
        'address_city',
        'address_province',
        'address_zip',
        'current_address_house_no',
        'current_address_street',
        'current_address_barangay',
        'current_address_city',
        'current_address_province',
        'current_address_zip',
        'emergency_contact_name',
        'emergency_contact_phone',
        'bank_name',
        'bank_account',
        'base_salary',
        'is_management',
        // WHEN the attendance exemption began — see the migration. Never accepted from a
        // request; EmployeeService stamps it when the flag is switched on.
        'management_since',
        // Excluded from the minimum-wage check, with the reason on the record.
        'minimum_wage_exempt',
        'minimum_wage_exempt_reason',
        'pay_frequency',
        'currency',
        'bank_account_type',
        'bank_account_name',
        // Membership NUMBERS for the payslip header. The peso *_contribution overrides that
        // used to sit here are gone — contributions follow the company rates for everyone.
        'tin',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'custom_workdays',
        'custom_start_time',
        'custom_end_time',
        'custom_am_start_time',
        'custom_am_end_time',
        'custom_pm_start_time',
        'custom_pm_end_time',
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
        'previous_hire_date' => 'date', // The prior stint's start, kept across a rehire

        'date_of_birth' => 'date',
        'base_salary' => 'decimal:2',
        'is_management' => 'boolean',
        'management_since' => 'date',
        'minimum_wage_exempt' => 'boolean',
        // One entry per named sibling: [{ name, gender }].
        'siblings' => 'array',
        'number_of_siblings' => 'integer',
        'custom_workdays' => 'array',
        'custom_grace_minutes' => 'integer',
        'custom_unpaid_break_minutes' => 'integer',
        'custom_paid_break_minutes' => 'integer',
        // Sensitive personal information under the Data Privacy Act, encrypted at rest.
        // These columns are TEXT, not varchar(255): the envelope is ~228 chars for a 17-char
        // account number, so varchar would fit today and silently truncate — destroying the value,
        // because a truncated ciphertext cannot be decrypted.
        'bank_account' => 'encrypted',
        'bank_account_name' => 'encrypted',
        'tin' => 'encrypted',
        'sss_number' => 'encrypted',
        'philhealth_number' => 'encrypted',
        'pagibig_number' => 'encrypted',
    ];

    /**
     * The sensitive fields, named once.
     *
     * Used by the casts above, the masking below and the reveal endpoint, so the three cannot drift
     * apart — a field added to the casts but not to the masking would be returned in full.
     */
    public const SENSITIVE_FIELDS = [
        'bank_account', 'bank_account_name', 'tin',
        'sss_number', 'philhealth_number', 'pagibig_number',
    ];

    /** A sensitive value reduced to its last four characters. */
    public static function maskSensitive(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Already masked — demo data stores placeholder values in this shape, and re-masking a mask
        // would quietly change it.
        if (str_starts_with($value, '••••')) {
            return $value;
        }

        return mb_strlen($value) <= 4 ? '••••' : '••••' . mb_substr($value, -4);
    }

    /**
     * This employee's sensitive fields, masked — what every ordinary payload carries.
     *
     * @return array<string, string|null>
     */
    /**
     * The name as a roster lists it: "Dela Cruz Jr., Juan S.".
     *
     * A suffix belongs to the surname, not to the given names — "Dela Cruz, Juan Jr." files the
     * Jr. under Juan and reads as a second given name. Records from before the name was split have
     * no `last_name` to file under, so they keep the composed name rather than being guessed at.
     *
     * The middle name is carried as an INITIAL. Someone with none is listed exactly as before —
     * surname, then first name — because there is nothing to abbreviate.
     */
    public function listingName(): ?string
    {
        $last = trim((string) $this->last_name);

        if ($last === '') {
            return $this->user?->name;
        }

        $suffix = trim((string) $this->name_extension);
        $surname = $suffix === '' ? $last : "{$last} {$suffix}";

        $given = implode(' ', array_filter(
            [trim((string) $this->first_name), $this->middleInitial()],
            fn (?string $part) => $part !== null && $part !== '',
        ));

        return $given === '' ? $surname : "{$surname}, {$given}";
    }

    /**
     * The middle name as an initial — "Reyes" becomes "R." — or null when there is none.
     *
     * A middle name on a Philippine record is the mother's maiden surname, and a roster carries
     * it as the M.I.: it is there to tell two Juan Dela Cruzes apart, not to be read. A compound
     * one ("Dela Cruz") gives its first letter only, which is what the forms this mirrors do.
     *
     * `mb_` throughout — a name is the last place to assume one byte is one letter, and the
     * first letter of "Ñ" truncated mid-sequence is not a letter at all.
     */
    private function middleInitial(): ?string
    {
        $middle = trim((string) $this->middle_name);

        if ($middle === '') {
            return null;
        }

        // A record already holding just "R" or "R." must not come back as "R..".
        return mb_strtoupper(mb_substr($middle, 0, 1)) . '.';
    }

    /**
     * One of the two addresses as a single line, composed from its parts.
     *
     * Falls back to the pre-split free-text column when no part has been filled in yet, so a record
     * nobody has re-entered since the split still reads as an address rather than as nothing.
     *
     * @param  string  $prefix  'address' (home) or 'current_address'
     */
    public function addressLine(string $prefix = 'address'): ?string
    {
        $part = fn (string $name) => trim((string) $this->{"{$prefix}_{$name}"});

        $street = trim($part('house_no').' '.$part('street'));

        $pieces = array_values(array_filter(
            [$street, $part('barangay'), $part('city'), $part('province'), $part('zip')],
            fn (string $piece) => $piece !== '',
        ));

        return $pieces === [] ? $this->{$prefix} : implode(', ', $pieces);
    }

    public function maskedSensitive(): array
    {
        $out = [];

        foreach (self::SENSITIVE_FIELDS as $field) {
            $out[$field] = self::maskSensitive($this->{$field});
        }

        return $out;
    }

    /** Management staff: fixed salary + exempt from attendance. */
    public function isManagement(): bool
    {
        return (bool) $this->is_management;
    }

    /**
     * Was the attendance exemption in force on this Manila date? Two things decide it:
     *
     *  - the COMPANY rule (`management_auto_attendance`, Company → Settings). Off, nobody is
     *    exempt: management uses the time clock like everyone else. Passed in rather than read
     *    here so each caller resolves the policy once instead of once per employee per day.
     *  - WHEN this person became management. `is_management` answers only "today", which is the
     *    wrong question for anything that scans history: a blanket skip let a promotion erase
     *    the lates and absences someone accrued before it.
     *
     * Their PAY is not part of this: management is salaried on a fixed basis whatever the rule
     * says, so payroll keeps asking isManagement() instead.
     */
    public function managementExemptOn(string $date, bool $autoAttendanceEnabled): bool
    {
        if (! $autoAttendanceEnabled || ! $this->isManagement()) {
            return false;
        }

        // A flagged employee with no start date predates the column — exempt throughout, exactly
        // as they behaved before it existed.
        $since = $this->management_since?->toDateString() ?? $this->hire_date?->toDateString();

        return $since === null || $date >= $since;
    }

    /** Browser-facing URL for the profile picture, or null to fall back to initials. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn () => PrivateFileService::url($this->photo_path),
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
            // Without this it reads as null and nothing is ever taken.
            ->withPivot([
                'tier', 'employee_share', 'amount', 'amount_set_by', 'amount_set_at',
                'enrolled_at', 'ended_at',
            ])
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
