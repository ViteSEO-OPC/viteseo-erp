<?php

namespace App\Modules\Hr\Services;

use App\Models\Org;
use App\Models\User;
use App\Support\CurrentOrg;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use App\Modules\Hr\Models\EmployeeDocument;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\SharedLeaveAllotment;
use App\Modules\Hr\Models\SharedLeaveBalance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
    /** Directory page size when the caller doesn't say — matches the card grid's 12. */
    private const DEFAULT_PER_PAGE = 12;

    /** Ceiling on `per_page`, so the directory endpoint can never be asked for the whole table. */
    private const MAX_PER_PAGE = 100;

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
        'phone',
        // The two addresses. The bare `address` / `current_address` are the pre-split
        // free text, still writable so an old change request can still be approved.
        'address', 'current_address',
        'address_house_no', 'address_street', 'address_barangay', 'address_city', 'address_province', 'address_zip',
        'current_address_house_no', 'current_address_street', 'current_address_barangay', 'current_address_city', 'current_address_province', 'current_address_zip',
        'mothers_maiden_name', 'fathers_name', 'siblings', 'number_of_siblings',
        'high_school', 'college_course', 'university',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
        'bank_name', 'bank_account', 'height', 'weight', 'health_condition',
        'pay_frequency', 'currency', 'bank_account_type', 'bank_account_name',
        // The three allowance columns are gone from here. Payroll stopped reading them
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

    /** Job columns written explicitly rather than as part of the profile blob. */
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

        // Same non-nullable-boolean treatment. Clearing the flag clears the reason with
        // it: a stale "part-time" note sitting on someone who is no longer exempt reads as a live
        // justification, which is worse than no note at all.
        if (array_key_exists('minimum_wage_exempt', $data)) {
            $exempt = ! empty($data['minimum_wage_exempt']);
            $out['minimum_wage_exempt'] = $exempt;
            $out['minimum_wage_exempt_reason'] = $exempt
                ? ($data['minimum_wage_exempt_reason'] ?? null)
                : null;
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

    /** Close the open role on the employment-history timeline when the employment itself ends. */
    private function closeTimelineOnSeparation(Employee $employee, string $lastDay, ?string $reason): void
    {
        $current = $employee->employmentHistories()
            ->whereNull('end_date')
            ->orderByDesc('start_date')
            ->first();

        if (! $current) {
            return; // nothing open — a record that predates the timeline, or already closed
        }

        // A backdated last day can land before the current role started (the title changed after the
        // date now being recorded as the exit). A row that ends before it begins is worse than an
        // imprecise one, so the role collapses to its start date rather than inverting.
        $end = Carbon::parse($lastDay, config('app.timezone_local'))->startOfDay();
        if ($current->start_date && $end->lt($current->start_date)) {
            $end = $current->start_date->copy();
        }

        $current->update([
            'end_date' => $end->toDateString(),
            'status' => 'separated',
            'reason' => $reason,
            'changed_by' => Auth::id(),
        ]);
    }

    /**
     * Undo that close — reinstatement's whole claim is that the ending never happened, so the role it
     * closed goes back to being the current one rather than the timeline keeping a phantom exit.
     */
    private function reopenTimelineOnReinstatement(Employee $employee): void
    {
        $last = $employee->employmentHistories()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if ($last?->status !== 'separated') {
            return;
        }

        $last->update([
            'end_date' => null,
            'status' => 'current',
            'changed_by' => Auth::id(),
        ]);
    }

    /**
     * Open a fresh role for a rehire — a new employment relationship is a new entry, not a resumption
     * of the old one, which is what keeps the break in service visible on the timeline.
     *
     * @param  string|Carbon|null  $previousLastDay
     */
    private function openTimelineOnRehire(Employee $employee, string $hireDate, $previousLastDay, ?string $reason): void
    {
        // Records separated before separations closed the timeline still have an open row. Close it at
        // the old last day first, or the two stints overlap and both claim to be current.
        if ($previousLastDay) {
            $this->closeTimelineOnSeparation(
                $employee,
                Carbon::parse($previousLastDay, config('app.timezone_local'))->toDateString(),
                null,
            );
        }

        EmploymentHistory::create([
            'employee_id' => $employee->id,
            'title' => $employee->job_title,
            'start_date' => $hireDate,
            'end_date' => null,
            'status' => 'current',
            'reason' => $reason,
            'changed_by' => Auth::id(),
        ]);
    }

    /** The employee directory. */
    /**
     * One page of the directory.
     *
     * Every filter is applied HERE rather than in the browser. The screen shows twelve cards; it
     * used to receive the first hundred employees and do the searching, the department filter and
     * the paging itself — which meant the hundred-and-first person was unreachable, and a search
     * for them answered "no employees match your filters" rather than admitting it had never
     * looked. Filtering in the query is also what makes the count in the header true: the total
     * below counts everyone who matches, not everyone who was downloaded.
     *
     * @param  array<string, mixed>  $filters  search, status (roster), department,
     *                                         employment_status, per_page
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? 'active';
        $department = $filters['department'] ?? null;
        $employmentStatus = $filters['employment_status'] ?? null;

        // Bounded both ways: a page is a page. Without the ceiling `?per_page=100000` turns the
        // directory back into the whole-table read this exists to stop.
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));

        return Employee::query()
            ->when($status === 'former', fn ($q) => $q->onlyTrashed())
            ->when($status === 'all', fn ($q) => $q->withTrashed())
            // Department and employment status are exact matches, so they need no grouping —
            // unlike the search below, they AND with everything else exactly as intended.
            ->when($department, fn ($q) => $q->where('department_id', (int) $department))
            ->when(
                $employmentStatus && in_array($employmentStatus, self::STATUSES, true),
                fn ($q) => $q->where('employment_status', $employmentStatus),
            )
            // Only what the directory projection reads. `user.roles` and `manager.user` went with
            // `is_hr_officer` and `manager` when the list stopped shipping the full record —
            // roles is a many-to-many join, paid for every row, for a field nothing rendered.
            ->with(['user', 'department'])
            // The search branches are OR'd, so they need their own group. Ungrouped they sit at the
            // same level as the roster filter and AND binds tighter than OR, which leaves
            // `deleted_at is not null` guarding only the FIRST branch — a job-title match then
            // pulls active employees into the "former" roster. The `active` roster hid this
            // because its soft-delete global scope is applied last and Laravel groups for it.
            ->when($search, function ($q) use ($search) {
                $q->where(function ($group) use ($search) {
                    $group->whereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })->orWhere('job_title', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Every employee as a PICKER OPTION — id and name, plus the two fields the eligibility
     * picker filters on. Nothing else.
     *
     * Separate from list() on purpose. The dropdowns ("who is the manager?", "who heads this
     * department?") need the whole roster and cannot page, so they were reading the directory
     * endpoint and silently getting its first hundred people — employee 101 could not be chosen
     * as anyone's manager. They also paid for the full directory projection to render a label:
     * this returns four columns instead of thirteen, and no relations at all.
     *
     * `toBase()` because these are option rows, not models — nothing here is going to be saved.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int, name:string, employment_status:string, is_management:bool}>
     */
    public function options(): \Illuminate\Support\Collection
    {
        return Employee::query()
            ->join('users', 'users.id', '=', 'hr_employees.user_id')
            ->orderBy('users.name')
            ->toBase()
            ->get([
                'hr_employees.id',
                'users.name',
                'hr_employees.employment_status',
                'hr_employees.is_management',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'employment_status' => (string) $row->employment_status,
                'is_management' => (bool) $row->is_management,
            ]);
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
                // SECURITY: no guessable default. When no password is supplied the
                // account gets an unusable random one — the employee sets their own
                // via the forgot-password flow. (Demo logins come from seeders,
                // which pass an explicit password.)
                'password' => Hash::make($data['password'] ?? \Illuminate\Support\Str::random(40)),
            ]);
            $user->assignRole('hr_employee');

            // 1a) Membership is what makes them a person in this org, and without it
            // the account is not merely empty -- it cannot sign in at all. The login
            // audit row carries org_id NOT NULL, resolved from membership, so the
            // first sign-in died on a constraint violation before this line existed.
            // Employees added through People had no way in; only the Team screen,
            // which attaches separately, produced a usable account.
            if (($orgId = CurrentOrg::id()) !== null) {
                Org::find($orgId)?->users()->attach($user->id, ['is_default' => true]);
            }

            // 2) The HR record hangs off that user. employee_no derives from the
            // (unique) user id, so it's guaranteed unique too.
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
                // A new hire flagged management is exempt from their first day, not from the
                // day someone typed the record.
                ...(empty($data['is_management']) ? [] : ['management_since' => $data['hire_date']]),
            ]);

            // 3) Seed this year's leave credits from the allotment defaults for this employment
            // status. These are only the STARTING credits — HR can freely edit each person's
            // balances afterwards. The shape depends on the company allotment mode: shared =
            // one pool; not-shared = one row per leave type.
            $this->seedLeaveBalances($employee);

            // 4) Seed the contribution checklist — enrolled in every active
            // government contribution by default; HR can opt individuals out.
            foreach (ContributionSetting::where('is_active', true)->get() as $setting) {
                EmployeeBenefit::create([
                    'employee_id' => $employee->id,
                    'contribution_type' => $setting->type,
                    'enrolled' => true,
                ]);
            }

            // 4b) No allowance enrolment here any more: an allowance now says who it is FOR
            // (everyone / these statuses / management / these named people), and payroll
            // evaluates that rule per run. A new hire therefore picks up everything they
            // qualify for the moment they exist, and stops needing to be remembered.

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

            // The other moment an enrolment row can start being missing. Created here
            // rather than by the next payroll run, which used to write these rows from inside its
            // own pricing transaction. Not load-bearing for pay: a missing row still reads as
            // enrolled, so this gives the enrolment checklist something to show, nothing more.
            app(ContributionService::class)->backfillEnrolments($employee);

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
    /**
     * Keep the attendance exemption's paper trail in step with the flag. Called on every save;
     * a no-op unless the flag actually moved.
     */
    private function syncManagementExemption(Employee $employee, bool $wasManagement): void
    {
        $isManagement = (bool) $employee->is_management;

        if ($isManagement === $wasManagement) {
            return;
        }

        $today = now(config('app.timezone_local'))->toDateString();
        $employee->loadMissing('user');
        $name = $employee->user?->name ?? 'This employee';

        if ($isManagement) {
            // Exempt from TODAY, never retroactively: the days before the promotion are a
            // record the employee actually built by clocking in, and back-dating the exemption
            // would erase the lates and absences HR may already have acted on.
            $employee->update(['management_since' => $today]);
            $this->audit->log('updated', 'People', "{$name} is now management — exempt from the time clock from {$today}", $employee);

            return;
        }

        // Back on the clock. Their generated history STAYS — it is the record of the days they
        // were exempt, and deleting it would turn every one of those days into an absence. Only
        // today and anything later goes: nobody punched those rows, and one sitting on today
        // reads to the time clock as already-clocked-in.
        Attendance::where('employee_id', $employee->id)
            ->where('source', Attendance::SOURCE_SYSTEM)
            ->where('work_date', '>=', $today)
            ->delete();

        $employee->update(['management_since' => null]);
        $this->audit->log('updated', 'People', "{$name} is no longer management — back on the time clock from {$today}", $employee);
    }

    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $oldSalary = (float) $employee->base_salary;
            $oldTitle = $employee->job_title;
            $wasManagement = (bool) $employee->is_management;

            $this->updateIdentity($employee, $data);

            $employee->update([
                ...$this->coreData($data),
                ...$this->profileData($data),
            ]);

            $this->syncManagementExemption($employee, $wasManagement);

            $newSalary = (float) $employee->base_salary;

            // A salary change appends to the history (never rewrites it).
            if ($newSalary !== $oldSalary) {
                // The date the raise ACTUALLY takes effect, not the date it was typed.
                $effectiveDate = $data['salary_effective_date']
                    ?? now(config('app.timezone_local'))->toDateString();

                $this->assertSalaryDateIsSafe($employee, $effectiveDate, $data);

                $history = SalaryHistory::create([
                    'employee_id' => $employee->id,
                    'base_salary' => $newSalary,
                    'effective_date' => $effectiveDate,
                    'reason' => $data['salary_change_reason'] ?? 'Salary adjustment',
                    'change_type' => $data['salary_change_type'] ?? 'adjustment',
                    'changed_by' => Auth::id(),
                ]);

                /** A BACK-DATED RAISE INTO A SETTLED PERIOD IS AN OUTSTANDING DIFFERENCE. */
                app(RetroFlagService::class)->flagIfSettled(
                    $history,
                    $employee->id,
                    $effectiveDate,
                    null,
                    RetroFlag::OWED,
                    'Salary raised to ' . number_format((float) $newSalary, 2)
                        . ' effective ' . $effectiveDate
                        . ' — the days already paid were priced at the old figure',
                );
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
            'photo_path' => $photo->store("hr/employee-photos/{$employee->id}", 'local'),
        ]);

        if ($previous) {
            Storage::disk('local')->delete($previous);
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
            Storage::disk('local')->delete($previous);

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

    /** The separation causes the attrition report already groups by. */
    public const SEPARATION_TYPES = ['voluntary', 'involuntary'];

    /**
     * Refuse the account behind an employee. Idempotent.
     *
     * Writes one column on `users` and nothing else — deliberately not a role
     * change, a token revocation or a membership delete, all of which would have to
     * be remembered and undone. The membership row in particular STAYS, because
     * OrgUserRule validates every person-picker against it: deleting it would unpick
     * the person from the work they actually did.
     */
    private function closeAccount(Employee $employee): void
    {
        $employee->user?->forceFill(['status' => User::STATUS_INACTIVE])->save();
    }

    /** Open it again. The mirror of closeAccount(), and just as small. */
    private function openAccount(Employee $employee): void
    {
        $employee->user?->forceFill(['status' => User::STATUS_ACTIVE])->save();
    }

    /**
     * End someone's employment.
     *
     * @param  array{separation_type:string, separation_date:string, reason?:string|null}  $data
     */
    public function separate(Employee $employee, array $data): Employee
    {
        if ($employee->separation_date !== null || $employee->trashed()) {
            throw ValidationException::withMessages([
                'separation_date' => ['This employee has already been separated.'],
            ]);
        }

        return DB::transaction(function () use ($employee, $data) {
            $employee->update([
                'separation_type' => $data['separation_type'],
                'separation_date' => $data['separation_date'],
            ]);

            $this->closeTimelineOnSeparation(
                $employee,
                $data['separation_date'],
                $data['reason'] ?? null,
            );

            $employee->delete();

            // Archive stamp = last working day, so the attrition report and payroll agree.
            Employee::withTrashed()->whereKey($employee->id)->update([
                'deleted_at' => Carbon::parse($data['separation_date'], config('app.timezone_local'))
                    ->startOfDay(),
            ]);

            $employee->load('user');

            /*
             * Close the account, or leave it for the sweep to close on the day.
             *
             * A termination ends access now — that is the point of it, and a notice
             * period is not something an involuntary separation grants. A resignation
             * is worked out: the person is still doing the job until their last day,
             * so `hr:apply-separations` closes theirs when the date arrives.
             *
             * Nothing else is touched. No role is removed, no token revoked, no task
             * reassigned — the account is refused, not dismantled, so reinstatement
             * has nothing to put back. See EnsureAccountIsActive.
             */
            if ($data['separation_type'] === 'involuntary') {
                $this->closeAccount($employee);
            }

            $this->audit->log(
                'updated',
                'People',
                "Separated {$employee->user?->name} ({$data['separation_type']}), last day "
                    . $data['separation_date']
                    . (($data['reason'] ?? null) ? " — {$data['reason']}" : ''),
                $employee,
            );

            // The timeline moves on all three of these, so the response the record page re-reads
            // has to carry it — an unloaded relation serialises as an empty history.
            return $employee->fresh(['user', 'department', 'manager.user', 'employmentHistories']);
        });
    }

    /** Undo a separation that should not have happened — service is CONTINUOUS. */
    public function reinstate(Employee $employee, ?string $reason = null): Employee
    {
        if (! $employee->trashed() && $employee->separation_date === null) {
            throw ValidationException::withMessages([
                'employee' => ['This employee is already active — there is no separation to undo.'],
            ]);
        }

        return DB::transaction(function () use ($employee, $reason) {
            $employee->restore();
            $employee->update([
                'separation_type' => null,
                'separation_date' => null,
            ]);

            $this->reopenTimelineOnReinstatement($employee);

            $employee->load('user');
            // Reinstatement's claim is that the ending never happened, so the account
            // opens again on the token the person was already holding.
            $this->openAccount($employee);
            $this->audit->log(
                'reopened',
                'People',
                "Reinstated {$employee->user?->name} with continuous service"
                    . ($reason ? " — {$reason}" : ''),
                $employee,
            );

            // The timeline moves on all three of these, so the response the record page re-reads
            // has to carry it — an unloaded relation serialises as an empty history.
            return $employee->fresh(['user', 'department', 'manager.user', 'employmentHistories']);
        });
    }

    /** Take someone back as a NEW employment relationship. */
    public function rehire(Employee $employee, string $hireDate, ?string $reason = null): Employee
    {
        if (! $employee->trashed() && $employee->separation_date === null) {
            throw ValidationException::withMessages([
                'employee' => ['This employee is already active — use the employee record to update them.'],
            ]);
        }

        $tz = config('app.timezone_local');
        $lastDay = $employee->separation_date ?? $employee->deleted_at;

        // A rehire that starts before the previous last day is not a rehire, it is a correction —
        // and reinstatement is the verb for that.
        if ($lastDay && Carbon::parse($hireDate, $tz)->startOfDay()->lte(Carbon::parse($lastDay, $tz)->startOfDay())) {
            throw ValidationException::withMessages([
                'hire_date' => [
                    'The rehire date must be after the previous last day ('
                        . Carbon::parse($lastDay, $tz)->toDateString()
                        . '). To undo a separation instead, reinstate them.',
                ],
            ]);
        }

        return DB::transaction(function () use ($employee, $hireDate, $reason, $lastDay) {
            $previousHire = $employee->hire_date?->toDateString();

            $employee->restore();
            $this->openAccount($employee);
            $employee->update([
                'separation_type' => null,
                'separation_date' => null,
                'previous_hire_date' => $previousHire,
                'hire_date' => $hireDate,
            ]);

            $this->openTimelineOnRehire($employee, $hireDate, $lastDay, $reason);

            $this->seedLeaveBalances($employee);

            // Fresh Art. 113 consent required — see the docblock.
            $reauthorised = EmployeeLoan::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->get()
                ->filter(fn (EmployeeLoan $loan) => $loan->outstanding() > 0);

            foreach ($reauthorised as $loan) {
                $loan->update(['status' => 'pending_authorization', 'authorized_at' => null]);
            }

            $employee->load('user');
            $this->audit->log(
                'created',
                'People',
                "Rehired {$employee->user?->name} from {$hireDate}"
                    . ($previousHire ? " (previously from {$previousHire})" : '')
                    . ($reauthorised->isNotEmpty()
                        ? ' — ' . $reauthorised->count() . ' outstanding loan(s) need fresh authorisation'
                        : '')
                    . ($reason ? " — {$reason}" : ''),
                $employee,
            );

            // The timeline moves on all three of these, so the response the record page re-reads
            // has to carry it — an unloaded relation serialises as an empty history.
            return $employee->fresh(['user', 'department', 'manager.user', 'employmentHistories']);
        });
    }

    /** This year's starting leave credits from the allotment defaults, idempotently. */
    private function seedLeaveBalances(Employee $employee): void
    {
        $year = now(config('app.timezone_local'))->year;

        if (LeaveSetting::current()->allotment_mode === LeaveSetting::MODE_SHARED) {
            $poolDays = SharedLeaveAllotment::where('employment_status', $employee->employment_status)
                ->value('days_per_year');

            SharedLeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'year' => $year],
                ['entitled_days' => (float) ($poolDays ?? 0), 'used_days' => 0],
            );

            return;
        }

        $allotments = LeaveTypeAllotment::where('employment_status', $employee->employment_status)
            ->pluck('days_per_year', 'leave_type_id');

        foreach (LeaveType::all() as $type) {
            LeaveBalance::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                [
                    'entitled_days' => (float) ($allotments[$type->id] ?? $type->days_per_year),
                    'used_days' => 0,
                ],
            );
        }
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
        $path = $file->store("hr/employee-documents/{$employee->id}", 'local');

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

    /**
     * Rename a document — the LABEL on the record, not the file on disk.
     *
     * The stored path never changes, so nothing that already points at the file breaks. The
     * extension is carried over when the new name drops it: `name` is what the browser saves the
     * download as, and "Contract" with no `.pdf` downloads as a file the OS cannot open.
     */
    public function renameDocument(EmployeeDocument $document, string $name): EmployeeDocument
    {
        $before = $document->name;
        $name = trim($name);

        $extension = pathinfo((string) $before, PATHINFO_EXTENSION);

        if ($extension !== '' && strcasecmp(pathinfo($name, PATHINFO_EXTENSION), $extension) !== 0) {
            $name .= '.'.$extension;
        }

        if ($name === $before) {
            return $document;
        }

        $document->update(['name' => $name]);

        $document->loadMissing('employee.user');
        $employee = $document->employee?->user?->name ?? $document->employee?->name;

        $this->audit->log(
            'updated',
            'People',
            "Renamed document '{$before}' to '{$name}' on {$employee}",
            $document,
            ['name' => $before],
            ['name' => $name],
        );

        return $document;
    }

    public function deleteDocument(EmployeeDocument $document): void
    {
        $document->delete(); // soft delete; the stored file is kept

        $document->loadMissing('employee.user');
        $name = $document->employee?->user?->name ?? $document->employee?->name;
        $this->audit->log('removed', 'People', "Removed document '{$document->name}' from {$name}", $document);
    }

    /**
     * Refuse a back-dated salary change that reaches into finished payroll runs, unless HR says
     * so explicitly.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSalaryDateIsSafe(Employee $employee, string $effectiveDate, array $data): void
    {
        if ((bool) ($data['acknowledge_repricing'] ?? false)) {
            return;
        }

        $affected = app(PayrollFreezeService::class)
            ->completedRunsRepricedBy($employee->id, $effectiveDate);

        if ($affected->isEmpty()) {
            return;
        }

        $periods = $affected
            ->map(fn ($r) => $r->cycle_start->format('M j') . '–' . $r->cycle_end->format('M j, Y'))
            ->implode(', ');

        throw ValidationException::withMessages([
            'salary_effective_date' => [
                'This date falls inside ' . $affected->count() . ' completed payroll run'
                    . ($affected->count() === 1 ? '' : 's') . " ({$periods}). Those periods were "
                    . 'already paid at the old salary and there is no way to pay the difference '
                    . 'yet, so re-running them would rewrite what they paid. Confirm to record it '
                    . 'anyway.',
            ],
        ]);
    }
}
