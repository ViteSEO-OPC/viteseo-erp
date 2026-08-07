<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
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

    /**
     * The employee directory.
     *
     * `$status` — 'active' (default), 'former' or 'all'. MF-18 needs this: a separated employee is
     * soft-deleted, so before this there was no way to LIST anyone who had left, which made rehiring
     * unreachable from the UI even once the endpoint existed. Default stays 'active' so the directory
     * looks exactly as it did.
     */
    public function list(?string $search = null, string $status = 'active'): LengthAwarePaginator
    {
        return Employee::query()
            ->when($status === 'former', fn ($q) => $q->onlyTrashed())
            ->when($status === 'all', fn ($q) => $q->withTrashed())
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

            // 3) Seed this year's leave credits from the allotment defaults for this employment
            //    status. These are only the STARTING credits — HR can freely edit each person's
            //    balances afterwards. The shape depends on the company allotment mode: shared =
            //    one pool; not-shared = one row per leave type.
            //
            //    MF-18 — shared with `rehire()` rather than duplicated. A rehired employee has to
            //    get exactly what a new hire gets, and two copies of this is how the two would
            //    drift into giving different credits for the same employment status.
            $this->seedLeaveBalances($employee);

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

            // FIN-038 — the other moment an enrolment row can start being missing. Created here
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
                // FIN-007 — the date the raise ACTUALLY takes effect, not the date it was typed.
                //
                // This was hardcoded to `now()`. A raise agreed for 1 August but entered on
                // 5 September priced August at the old salary, permanently: payroll resolves the
                // salary as of the cycle end, and with no retroactive run (FIN-010) the difference
                // could never be paid. A promotion agreed for a future date could not be scheduled
                // at all. Defaults to today, so omitting it behaves exactly as before.
                $effectiveDate = $data['salary_effective_date']
                    ?? now(config('app.timezone_local'))->toDateString();

                $this->assertSalaryDateIsSafe($employee, $effectiveDate, $data);

                SalaryHistory::create([
                    'employee_id' => $employee->id,
                    'base_salary' => $newSalary,
                    'effective_date' => $effectiveDate,
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
     * End someone's employment (MF-18).
     *
     * THIS DID NOT EXIST. A separation was only ever written by `AttritionDemoSeeder`, which meant
     * `separation_date` — the column `runFinalPay()` refuses to run without, and the one attendance
     * proration and leave accrual read as the employment end — was never set by any path a user could
     * reach. Final pay was built and unreachable.
     *
     * `separation_date` AND `deleted_at` ARE KEPT IN SYNC ON PURPOSE. They answer different questions
     * (last working day vs. when the record was archived) and could legitimately differ, but the
     * attrition report buckets quarters on `deleted_at` while payroll prorates on `separation_date`.
     * Letting them drift would mean the two disagree about when somebody left, so the separation date
     * is written to both and there is one answer.
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

            $employee->delete();

            // Archive stamp = last working day, so the attrition report and payroll agree.
            Employee::withTrashed()->whereKey($employee->id)->update([
                'deleted_at' => Carbon::parse($data['separation_date'], config('app.timezone_local'))
                    ->startOfDay(),
            ]);

            $employee->load('user');
            $this->audit->log(
                'updated',
                'People',
                "Separated {$employee->user?->name} ({$data['separation_type']}), last day "
                    . $data['separation_date']
                    . (($data['reason'] ?? null) ? " — {$data['reason']}" : ''),
                $employee,
            );

            return $employee->fresh(['user', 'department', 'manager.user']);
        });
    }

    /**
     * Undo a separation that should not have happened — service is CONTINUOUS (MF-18).
     *
     * A withdrawn resignation or a reversed dismissal. The hire date stands, so tenure is unbroken;
     * leave credits stand, because they were earned under an employment relationship that is now
     * treated as never having ended; and an authorised salary deduction stays authorised, because the
     * employee's Art. 113 consent was never withdrawn — only their employment appeared to end.
     *
     * Distinct from `rehire()`, which is a new relationship. Getting these the same way round matters:
     * reinstating someone as a rehire would quietly reset their tenure and wipe leave they had earned.
     */
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

            $employee->load('user');
            $this->audit->log(
                'reopened',
                'People',
                "Reinstated {$employee->user?->name} with continuous service"
                    . ($reason ? " — {$reason}" : ''),
                $employee,
            );

            return $employee->fresh(['user', 'department', 'manager.user']);
        });
    }

    /**
     * Take someone back as a NEW employment relationship (MF-18).
     *
     * Tenure restarts from `$hireDate`; the old date moves to `previous_hire_date` so the record can
     * still say when they first joined. Three continuity decisions, each made deliberately:
     *
     * LEAVE STARTS FRESH. Balances for the new year are seeded from the allotment defaults exactly as
     * they are for any new hire, and the previous stint's rows stay untouched as history. Carrying an
     * old unused balance across a genuine break in service would be a gift nobody authorised — and if
     * a final pay ran, those days were already converted to cash (FIN-014), so carrying them would pay
     * for the same leave twice.
     *
     * OUTSTANDING LOANS ARE NOT AUTO-RESUMED. Anything still owed goes back to
     * `pending_authorization`, which is exactly what stops `collectForPeriod()` reading it. Resuming
     * collection on its own would deduct from the new relationship's wages under consent given for the
     * old one, and Art. 113 requires the employee's written authorisation for the deduction actually
     * being made. HR re-issues the authorisation request; the employee agrees again, or does not.
     *
     * THE SEPARATION IS CLEARED, NOT ERASED. `separation_type`/`separation_date` are reset because the
     * person is employed again and every payroll filter keys on them, but the audit trail keeps the
     * separation and the rehire as two events, so the break in service is still visible.
     */
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

        return DB::transaction(function () use ($employee, $hireDate, $reason) {
            $previousHire = $employee->hire_date?->toDateString();

            $employee->restore();
            $employee->update([
                'separation_type' => null,
                'separation_date' => null,
                'previous_hire_date' => $previousHire,
                'hire_date' => $hireDate,
            ]);

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

            return $employee->fresh(['user', 'department', 'manager.user']);
        });
    }

    /**
     * This year's starting leave credits from the allotment defaults, idempotently.
     *
     * Extracted from `create()` so a rehire seeds exactly what a new hire does — a second copy of this
     * logic is how the two would drift, and a rehired employee would end up with the wrong credits for
     * their employment status. `firstOrCreate` rather than `create` because a rehire in the same
     * calendar year as the separation already has rows for it.
     */
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

    public function deleteDocument(EmployeeDocument $document): void
    {
        $document->delete(); // soft delete; the stored file is kept

        $document->loadMissing('employee.user');
        $name = $document->employee?->user?->name ?? $document->employee?->name;
        $this->audit->log('removed', 'People', "Removed document '{$document->name}' from {$name}", $document);
    }

    /**
     * Refuse a back-dated salary change that reaches into finished payroll runs, unless HR says
     * so explicitly (FIN-007).
     *
     * WHY NOT JUST ALLOW IT. Payroll resolves the salary in force at the cycle end, so recording a
     * raise dated inside a completed period means the next recompute of that period pays a
     * different amount than the payslip already issued. There is no retroactive run to pay the
     * difference through (FIN-010), so the choice is between silently rewriting a finished period
     * and telling someone. This tells them, and names the periods.
     *
     * WHAT THIS DOES NOT DO, stated plainly because the field would otherwise look more precise
     * than the arithmetic behind it: pricing has NO INTRA-PERIOD SPLIT. `salariesAsOf($cycleEnd)`
     * applies one salary to the whole period, so a raise dated mid-cutoff prices the entire cutoff
     * at the new figure — including the days before it took effect. The history is now truthful
     * and the resolution is documented; genuine split-period pricing (prorating each salary across
     * the days it covered) is the remaining half and is a separate piece of work.
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
