<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use App\Modules\Hr\Models\EmployeeDocument;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\SharedLeaveAllotment;
use App\Modules\Hr\Models\SharedLeaveBalance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Allowed employment statuses (also enforced in the Form Requests). Leave
     * allotments are keyed on these — adding one here means every leave type needs
     * an allotment row for it (see AddProjectBasedAndOjtLeaveAllotments migration).
     */
    public const STATUSES = ['regular', 'probationary', 'contract', 'part_time', 'project_based', 'ojt'];

    /**
     * Accepted values for the record's picklist fields. These mirror the option lists
     * the UI offers (frontend/src/modules/hr/types/employee.ts) — keep both in step.
     */
    public const GENDERS = ['Male', 'Female', 'Other', 'Prefer not to say'];

    public const CIVIL_STATUSES = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'];

    public const BANK_ACCOUNT_TYPES = ['Savings', 'Checking', 'Payroll'];

    public const PAY_FREQUENCIES = ['monthly', 'semi_monthly', 'weekly', 'bi_weekly'];

    /** The most siblings a record may name; the count field allows more than are named. */
    public const MAX_SIBLINGS = 20;

    /**
     * Editable profile columns on hr_employees (everything except the identity
     * name/email — which live on users — and the job/salary fields set explicitly).
     */
    public const PROFILE_COLUMNS = [
        'employment_type', 'work_location', 'employee_role', 'is_management',
        'first_name', 'middle_name', 'last_name', 'name_extension',
        'gender', 'civil_status', 'nationality', 'date_of_birth', 'place_of_birth',
        'phone', 'address', 'current_address',
        'mothers_maiden_name', 'fathers_name', 'siblings', 'number_of_siblings',
        'high_school', 'college_course', 'university',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
        'bank_name', 'bank_account', 'height', 'weight', 'health_condition',
        'pay_frequency', 'currency', 'bank_account_type', 'bank_account_name',
        'sss_contribution', 'pagibig_contribution', 'philhealth_contribution',
        // Slice B9 — the three allowance columns are gone from here. Payroll stopped reading them
        // in B1 and Company → Benefits is the catalogue now, so writing them only produced a
        // number on the profile that no payslip agreed with.
        'tin', 'sss_number', 'philhealth_number', 'pagibig_number',
    ];

    /** The users.name is composed from the split-name fields, falling back to `name`. */
    private function displayName(array $data): string
    {
        $parts = array_filter([
            $data['first_name'] ?? null, $data['middle_name'] ?? null,
            $data['last_name'] ?? null, $data['name_extension'] ?? null,
        ]);

        return $parts ? trim(implode(' ', $parts)) : ($data['name'] ?? '');
    }

    /**
     * Job columns written explicitly rather than as part of the profile blob.
     */
    private const CORE_COLUMNS = [
        'department_id', 'manager_id', 'job_title',
        'employment_status', 'onboarding_status', 'hire_date', 'base_salary',
    ];

    /**
     * Pull the profile columns out of a request payload. Only keys the caller
     * actually sent come back, so a section-scoped save (each inline editor on the
     * record posts just its own fields) leaves every other column untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileData(array $data): array
    {
        $out = [];
        foreach (self::PROFILE_COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $out[$col] = $data[$col];
            }
        }
        // Non-nullable boolean: when the caller sends the field at all, a null or
        // absent-but-falsy value means "not management".
        if (array_key_exists('is_management', $data)) {
            $out['is_management'] = ! empty($data['is_management']);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function coreData(array $data): array
    {
        $out = [];
        foreach (self::CORE_COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $out[$col] = $data[$col];
            }
        }

        return $out;
    }

    /**
     * Identity lives on the users row. Only touch it when the payload carries it —
     * a compensation-only save must not blank out the person's name or email.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateIdentity(Employee $employee, array $data): void
    {
        $changes = [];

        if (array_key_exists('email', $data)) {
            $changes['email'] = $data['email'];
        }

        $nameParts = array_flip(['first_name', 'middle_name', 'last_name', 'name_extension']);
        $sent = array_intersect_key($data, $nameParts);

        if ($sent !== []) {
            // Merge over the stored parts so a partial payload still composes a full name.
            $changes['name'] = $this->displayName([
                ...array_intersect_key($employee->only(array_keys($nameParts)), $nameParts),
                ...$sent,
            ]);
        }

        if ($changes !== []) {
            $employee->user->update($changes);
        }
    }

    /**
     * Move the employment-history timeline when the job title changes. A
     * "correction" fixes the current role's title in place (no new entry);
     * anything else closes the current role (stamped with the transition type)
     * and opens a fresh "current" one.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordRoleChange(Employee $employee, string $newTitle, array $data): void
    {
        $type = $data['role_change_type'] ?? null;
        $reason = $data['role_change_reason'] ?? null;
        $current = $employee->employmentHistories()->whereNull('end_date')->orderByDesc('start_date')->first();

        if ($type === 'correction') {
            $current?->update(['title' => $newTitle]);

            return;
        }

        $today = now(config('app.timezone_local'))->toDateString();
        $statusMap = ['promotion' => 'promoted', 'transfer' => 'transferred', 'lateral' => 'lateral'];

        $current?->update([
            'end_date' => $today,
            'status' => $statusMap[$type] ?? 'role_change',
            'reason' => $reason,
            'changed_by' => Auth::id(),
        ]);

        EmploymentHistory::create([
            'employee_id' => $employee->id,
            'title' => $newTitle,
            'start_date' => $today,
            'end_date' => null,
            'status' => 'current',
            'reason' => $reason,
            'changed_by' => Auth::id(),
        ]);
    }

    public function list(?string $search = null): LengthAwarePaginator
    {
        return Employee::query()
            ->with(['user.roles', 'department', 'manager.user'])
            ->when($search, function ($q) use ($search) {
                $q->whereHas('user', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('job_title', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%");
            })
            ->orderByDesc('id')
            ->paginate(100);
    }

    /**
     * Create an employee = a User (login + role) + an hr_employees row +
     * a current-year leave balance for every leave type. All-or-nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            // 1) The login identity lives in the shared users table.
            $user = User::create([
                'name' => $this->displayName($data),
                'email' => $data['email'],
                // MVP: a known default so demo logins work; real onboarding would
                // email a set-password link.
                'password' => Hash::make($data['password'] ?? 'password'),
            ]);
            $user->assignRole('hr_employee');

            // 2) The HR record hangs off that user. employee_no derives from the
            //    (unique) user id, so it's guaranteed unique too.
            $employee = Employee::create([
                'user_id' => $user->id,
                'department_id' => $data['department_id'],
                'manager_id' => $data['manager_id'] ?? null,
                'employee_no' => 'EMP' . str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                'job_title' => $data['job_title'],
                'employment_status' => $data['employment_status'] ?? 'regular',
                'onboarding_status' => $data['onboarding_status'] ?? null,
                'hire_date' => $data['hire_date'],
                'base_salary' => $data['base_salary'] ?? 0,
                ...$this->profileData($data),
            ]);

            // 3) Seed this year's leave credits from the allotment defaults for
            //    this employment status. These are only the STARTING credits — HR
            //    can freely edit each person's balances afterwards (per-person
            //    override). The shape depends on the company allotment mode:
            //    shared = one pool; not-shared = one row per leave type.
            $year = now(config('app.timezone_local'))->year;
            if (LeaveSetting::current()->allotment_mode === LeaveSetting::MODE_SHARED) {
                $poolDays = SharedLeaveAllotment::where('employment_status', $employee->employment_status)
                    ->value('days_per_year');
                SharedLeaveBalance::create([
                    'employee_id' => $employee->id,
                    'year' => $year,
                    'entitled_days' => (int) ($poolDays ?? 0),
                    'used_days' => 0,
                ]);
            } else {
                $allotments = LeaveTypeAllotment::where('employment_status', $employee->employment_status)
                    ->pluck('days_per_year', 'leave_type_id');
                foreach (LeaveType::all() as $type) {
                    LeaveBalance::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $year,
                        'entitled_days' => $allotments[$type->id] ?? $type->days_per_year,
                        'used_days' => 0,
                    ]);
                }
            }

            // 4) Seed the contribution checklist — enrolled in every active
            //    government contribution by default; HR can opt individuals out.
            foreach (ContributionSetting::where('is_active', true)->get() as $setting) {
                EmployeeBenefit::create([
                    'employee_id' => $employee->id,
                    'contribution_type' => $setting->type,
                    'enrolled' => true,
                ]);
            }

            // 4b) No allowance enrolment here any more: an allowance now says who it is FOR
            //     (everyone / these statuses / management / these named people), and payroll
            //     evaluates that rule per run. A new hire therefore picks up everything they
            //     qualify for the moment they exist, and stops needing to be remembered.

            // 5) Open the salary history with the starting salary.
            SalaryHistory::create([
                'employee_id' => $employee->id,
                'base_salary' => $employee->base_salary,
                'effective_date' => $employee->hire_date,
                'reason' => 'Initial salary',
                'change_type' => 'hire',
                'changed_by' => Auth::id(),
            ]);

            // 6) Open the employment-history timeline with the starting role.
            EmploymentHistory::create([
                'employee_id' => $employee->id,
                'title' => $employee->job_title,
                'start_date' => $employee->hire_date,
                'end_date' => null,
                'status' => 'current',
                'changed_by' => Auth::id(),
            ]);

            $employee->load('user');
            $this->audit->log('created', 'People', "Added employee {$employee->user?->name}", $employee);

            return $employee->load(['user', 'department', 'manager.user']);
        });
    }

    /**
     * Update the employee record (and the linked user's name/email). The payload may
     * be partial — only the fields it carries are written.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $oldSalary = (float) $employee->base_salary;
            $oldTitle = $employee->job_title;

            $this->updateIdentity($employee, $data);

            $employee->update([
                ...$this->coreData($data),
                ...$this->profileData($data),
            ]);

            $newSalary = (float) $employee->base_salary;

            // A salary change appends to the history (never rewrites it).
            if ($newSalary !== $oldSalary) {
                SalaryHistory::create([
                    'employee_id' => $employee->id,
                    'base_salary' => $newSalary,
                    'effective_date' => now(config('app.timezone_local'))->toDateString(),
                    'reason' => $data['salary_change_reason'] ?? 'Salary adjustment',
                    'change_type' => $data['salary_change_type'] ?? 'adjustment',
                    'changed_by' => Auth::id(),
                ]);
            }

            // A job-title change moves the employment-history timeline forward.
            if ($employee->job_title !== $oldTitle) {
                $this->recordRoleChange($employee, $employee->job_title, $data);
            }

            $employee->load('user');
            $this->audit->log('updated', 'People', "Updated {$employee->user?->name}'s record", $employee);

            return $employee->load(['user', 'department', 'manager.user']);
        });
    }

    /**
     * Replace the employee's profile picture. The previous file is discarded — photos
     * are overwritten in place, so there is no history worth keeping.
     */
    public function setPhoto(Employee $employee, UploadedFile $photo): Employee
    {
        $previous = $employee->photo_path;

        $employee->update([
            'photo_path' => $photo->store("hr/employee-photos/{$employee->id}", 'public'),
        ]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        $employee->load('user');
        $this->audit->log('updated', 'People', "Updated {$employee->user?->name}'s profile photo", $employee);

        return $employee->load(['user.roles', 'department', 'manager.user', 'employmentHistories']);
    }

    /** Drop the profile picture, falling the avatar back to initials. */
    public function removePhoto(Employee $employee): Employee
    {
        $previous = $employee->photo_path;

        if ($previous) {
            $employee->update(['photo_path' => null]);
            Storage::disk('public')->delete($previous);

            $employee->load('user');
            $this->audit->log('removed', 'People', "Removed {$employee->user?->name}'s profile photo", $employee);
        }

        return $employee->load(['user.roles', 'department', 'manager.user', 'employmentHistories']);
    }

    /** The employee's base-salary history, newest effective date first. */
    public function salaryHistory(Employee $employee): Collection
    {
        return $employee->salaryHistories()->with('changedBy')->get();
    }

    /** Mark onboarding complete (drops the "Onboarding" tag). */
    public function activate(Employee $employee): Employee
    {
        $employee->update(['onboarding_status' => 'complete']);

        $employee->load('user');
        $this->audit->log('updated', 'People', "Marked {$employee->user?->name}'s onboarding complete", $employee);

        return $employee->load(['user', 'department', 'manager.user']);
    }

    /** Undo the above — puts the "Onboarding" tag back. */
    public function reopenOnboarding(Employee $employee, ?string $reason = null): Employee
    {
        if ($employee->onboarding_status !== 'complete') {
            throw ValidationException::withMessages([
                'onboarding_status' => ['This employee is still onboarding — there is nothing to undo.'],
            ]);
        }

        $employee->update(['onboarding_status' => 'pending']);

        $employee->load('user');
        $this->audit->log(
            'reopened',
            'People',
            "Reopened {$employee->user?->name}'s onboarding" . ($reason ? " — {$reason}" : ''),
            $employee,
        );

        return $employee->load(['user', 'department', 'manager.user']);
    }

    /**
     * Grant or revoke HR-officer access for this employee's user account. HR officers
     * stay employees too, so this only adds/removes the extra `hr_officer` keychain
     * (never touches their base `employee` role). Guard: you can't revoke your own
     * access, which would lock you out of the HR side mid-session.
     */
    public function setHrRole(Employee $employee, bool $makeHr): Employee
    {
        $user = $employee->user;

        if (! $makeHr && $user->id === Auth::id()) {
            abort(422, 'You cannot revoke your own HR officer access.');
        }

        $makeHr ? $user->assignRole('hr_officer') : $user->removeRole('hr_officer');

        $this->audit->log(
            'updated',
            'People',
            ($makeHr ? 'Granted' : 'Revoked')." HR officer access for {$user->name}",
            $employee,
        );

        return $employee->load(['user.roles', 'department', 'manager.user', 'employmentHistories']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, EmployeeDocument> */
    public function documents(Employee $employee): Collection
    {
        return $employee->documents()->get();
    }

    public function addDocument(
        Employee $employee,
        \Illuminate\Http\UploadedFile $file,
        ?string $name = null,
        string $label = 'Uploaded',
    ): EmployeeDocument {
        $path = $file->store("hr/employee-documents/{$employee->id}", 'public');

        $document = $employee->documents()->create([
            'name' => $name ?: $file->getClientOriginalName(),
            'file_path' => $path,
            'size_bytes' => $file->getSize(),
            'label' => $label,
            'uploaded_by' => Auth::id(),
        ]);

        $employee->load('user');
        $this->audit->log('updated', 'People', "Uploaded document '{$document->name}' to {$employee->user?->name}", $document);

        return $document;
    }

    public function deleteDocument(EmployeeDocument $document): void
    {
        $document->delete(); // soft delete; the stored file is kept

        $document->loadMissing('employee.user');
        $name = $document->employee?->user?->name ?? $document->employee?->name;
        $this->audit->log('removed', 'People', "Removed document '{$document->name}' from {$name}", $document);
    }
}
