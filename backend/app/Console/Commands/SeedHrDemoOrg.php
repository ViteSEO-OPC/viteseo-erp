<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\DepartmentService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\LeaveService;
use App\Support\CurrentOrg;
use App\Support\Seats;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Fills ONE tenant org with believable HR demo data, so the screens a USER MANUAL is
 * screenshotted from show a working small agency instead of empty tables.
 *
 * The org is the *customer-facing demo tenant* (org 5, "test") — deliberately NOT org 1
 * (the provider's own seeded company) and NOT org 4 (a QA fixture). The shape of the
 * company mirrors Vite SEO Digital Advertising OPC's own line of work: a small Philippine
 * digital-marketing / SEO agency — SEO & Content, Client Servicing, Operations.
 *
 * WHY A COMMAND AND NOT A ONE-OFF SCRIPT
 * A manual gets regenerated, and the data behind its screenshots has to be reproducible.
 * This also means every write goes through the module's own services — EmployeeService,
 * DepartmentService, AttendanceService, LeaveService — so the rows carry the same
 * side-effects the app itself would have created (leave balances, salary + employment
 * history, contribution enrolment, audit trail). Data written around the app's rules is
 * data the app cannot render, and a manual full of broken screens is worse than an empty one.
 *
 * TENANCY
 * Everything runs inside CurrentOrg::runAs($orgId, ...) — the BelongsToOrg `creating`
 * stamp reads CurrentOrg::id(), and a console command has no authenticated user to
 * resolve one from. Nothing here uses insert()/upsert(), whose model events never fire.
 *
 * RE-RUNNABLE
 * Every step is guarded by an existence check (department code, user email, attendance is
 * firstOrNew per employee+date, a leave request is matched on employee+type+start date),
 * so running it twice adjusts rather than duplicates.
 */
class SeedHrDemoOrg extends Command
{
    protected $signature = 'hr:seed-demo-org
        {--org=5 : The tenant org id to fill}
        {--password=password : Password given to every demo account created here}
        {--reset-existing : Also reset the PRE-EXISTING members of that org to the same password, so every login in the manual is usable}
        {--refresh-attendance : Rewrite the attendance window from scratch instead of leaving days that already have a row}';

    protected $description = 'Fill the HR demo tenant (default org 5) with an agency-shaped roster, attendance and leave, for user-manual screenshots';

    /** Orgs whose counts are printed before/after as a "did we leak?" check. */
    private const WITNESS_ORGS = [1, 4];

    /** Leave at least this many seats free, so the manual can show adding somebody. */
    private const SEATS_TO_LEAVE_FREE = 1;

    /** The two departments added beside the org's existing Operations. */
    private const DEPARTMENTS = [
        ['code' => 'SEO', 'name' => 'SEO & Content', 'description' => 'Organic search: audits, on-page, link building and the editorial output that feeds it.'],
        ['code' => 'CS', 'name' => 'Client Servicing', 'description' => 'Account management, monthly client reporting and new business.'],
    ];

    /**
     * New hires. `manager` is another row's email, resolved after everyone exists.
     * Salaries are a plausible small-agency spread in PHP and deliberately all differ.
     */
    private const PEOPLE = [
        [
            'email' => 'rico@test.ph', 'first' => 'Rico', 'middle' => 'Bautista', 'last' => 'Alvarez',
            'dept' => 'SEO', 'title' => 'SEO Manager', 'status' => 'regular',
            'hire' => '2024-10-07', 'salary' => 58000, 'manager' => null,
            'phone' => '+63 917 412 7710', 'city' => 'Mandaluyong City',
        ],
        [
            'email' => 'kristine@test.ph', 'first' => 'Kristine', 'middle' => 'Lim', 'last' => 'Bautista',
            'dept' => 'SEO', 'title' => 'SEO Specialist', 'status' => 'regular',
            'hire' => '2025-03-03', 'salary' => 34000, 'manager' => 'rico@test.ph',
            'phone' => '+63 918 220 4483', 'city' => 'Pasig City',
        ],
        [
            'email' => 'angeline@test.ph', 'first' => 'Angeline', 'middle' => 'Cruz', 'last' => 'Soriano',
            'dept' => 'SEO', 'title' => 'Content Writer', 'status' => 'regular',
            'hire' => '2025-11-04', 'salary' => 28500, 'manager' => 'rico@test.ph',
            'phone' => '+63 916 703 1152', 'city' => 'Quezon City',
        ],
        [
            'email' => 'miguel@test.ph', 'first' => 'Miguel', 'middle' => 'Reyes', 'last' => 'Panganiban',
            'dept' => 'SEO', 'title' => 'Link Builder', 'status' => 'probationary',
            'hire' => '2026-06-15', 'salary' => 24500, 'manager' => 'rico@test.ph',
            'phone' => '+63 995 118 6604', 'city' => 'Caloocan City',
        ],
        [
            'email' => 'joshua@test.ph', 'first' => 'Joshua', 'middle' => 'Dizon', 'last' => 'Villanueva',
            'dept' => 'OPS', 'title' => 'Web Developer', 'status' => 'regular',
            'hire' => '2024-11-25', 'salary' => 45000, 'manager' => null,
            'phone' => '+63 917 889 3021', 'city' => 'Marikina City',
        ],
        [
            'email' => 'patricia@test.ph', 'first' => 'Patricia', 'middle' => 'Ong', 'last' => 'Gonzales',
            'dept' => 'CS', 'title' => 'Account Manager', 'status' => 'regular',
            'hire' => '2025-06-02', 'salary' => 39000, 'manager' => null,
            'phone' => '+63 919 335 7748', 'city' => 'Makati City',
        ],
    ];

    /** Department code → the employee (by email) shown as its head on the org chart. */
    private const DEPARTMENT_HEADS = [
        'SEO' => 'rico@test.ph',
        'CS' => 'patricia@test.ph',
        'OPS' => 'joshua@test.ph',
    ];

    /**
     * The org shipped with two people whose job title was the placeholder "Staff", which
     * reads as unfinished setup in a screenshot. Retitled in place (a "correction", so the
     * employment-history timeline is fixed rather than given a fake promotion) and pointed
     * at the Operations head.
     */
    private const EXISTING_PEOPLE = [
        'employee@test.ph' => ['title' => 'Graphic Designer', 'manager' => 'joshua@test.ph'],
        'ana@test.ph' => ['title' => 'Admin & Accounts Assistant', 'manager' => 'joshua@test.ph'],
    ];

    /** First working day of the two-week attendance window (a Monday). */
    private const ATTENDANCE_FROM = '2026-08-24';

    /** Last day written. Today is left alone so the live clock-in demo still has a blank day. */
    private const ATTENDANCE_TO = '2026-09-03';

    /**
     * The days that give the attendance screens their shape. Everything not listed here is
     * an ordinary on-time day. Keyed "<email>|<Y-m-d>".
     */
    private const ATTENDANCE_EXCEPTIONS = [
        // One unexcused absence — the roster's orange flag.
        'angeline@test.ph|2026-08-26' => ['status' => 'absent', 'note' => 'No notice, no call'],
        // Two lates on the newest hire, one on a tenured specialist: past the 15-minute grace.
        'miguel@test.ph|2026-08-25' => ['time_in' => '08:34', 'time_out' => '18:05', 'note' => 'Heavy traffic on EDSA'],
        'miguel@test.ph|2026-09-02' => ['time_in' => '08:41', 'time_out' => '18:10', 'note' => null],
        'kristine@test.ph|2026-08-27' => ['time_in' => '08:29', 'time_out' => '17:58', 'note' => null],
        // Covered by the approved sick leave below, so the day reads as leave, not absence.
        'kristine@test.ph|2026-08-28' => ['status' => 'on_leave', 'note' => 'Approved sick leave'],
    ];

    /** Arrival / departure rotations, so no two people (and no two days) show identical hours. */
    private const TIME_IN_ROTATION = ['07:48', '07:55', '08:00', '08:05', '07:52', '08:02', '07:58', '08:08'];

    private const TIME_OUT_ROTATION = ['17:58', '18:05', '18:00', '18:12', '17:52', '18:20', '18:02', '17:55'];

    /**
     * Leave requests, in a mix of states: something waiting on the approver screen,
     * something already granted (so balances show used days), and one refusal.
     * `decision` is one of approve / reject / null (leave pending).
     */
    private const LEAVE = [
        [
            'email' => 'kristine@test.ph', 'type' => 'SCK',
            'start' => '2026-08-28', 'end' => '2026-08-28',
            'reason' => 'Fever and sore throat — medical certificate attached.',
            'decision' => 'approve', 'note' => null,
        ],
        [
            'email' => 'joshua@test.ph', 'type' => 'ANL',
            'start' => '2026-09-21', 'end' => '2026-09-23',
            'reason' => 'Family trip to Baguio, booked since June.',
            'decision' => 'approve', 'note' => null,
        ],
        [
            'email' => 'patricia@test.ph', 'type' => 'ANL',
            'start' => '2026-10-05', 'end' => '2026-10-07',
            'reason' => 'Wedding of a close friend in Iloilo.',
            'decision' => null, 'note' => null,
        ],
        [
            'email' => 'miguel@test.ph', 'type' => 'SCK',
            'start' => '2026-09-08', 'end' => '2026-09-08',
            'reason' => 'Scheduled dental procedure.',
            'decision' => null, 'note' => null,
        ],
        [
            'email' => 'employee@test.ph', 'type' => 'ANL',
            'start' => '2026-09-28', 'end' => '2026-09-30',
            'reason' => 'Long weekend out of town.',
            'decision' => 'reject',
            'note' => 'Peak client-reporting week — please refile for the second half of October.',
        ],
    ];

    public function handle(
        EmployeeService $employees,
        DepartmentService $departments,
        AttendanceService $attendance,
        LeaveService $leave,
    ): int {
        // Demo data is demo data. Nothing here should ever be able to run against production.
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refusing to run outside local/testing — this writes demo data.');

            return self::FAILURE;
        }

        $orgId = (int) $this->option('org');
        $password = (string) $this->option('password');

        $witnessOrgs = array_values(array_unique([...self::WITNESS_ORGS, $orgId]));
        $before = $this->snapshot($witnessOrgs);

        $skipped = [];

        CurrentOrg::runAs($orgId, function () use (
            $orgId, $password, $employees, $departments, $attendance, $leave, &$skipped
        ) {
            // Act as the org's HR officer so reviewed_by / the audit trail name a real
            // person rather than "system". CurrentOrg's override wins over the resolver,
            // so signing in cannot move the tenant out from under us.
            $actor = $this->hrActorFor($orgId);
            if ($actor) {
                // setUser(), not login(): a console command has no session for the web guard
                // to write to, and all these writes need is Auth::id() to resolve.
                Auth::setUser($actor);
            }

            $this->seedDepartments($departments);
            $created = $this->seedEmployees($employees, $orgId, $password, $skipped);
            $this->linkManagersAndHeads($employees, $departments);
            $this->seedAttendance($attendance, $orgId, $skipped);
            $this->seedLeave($leave, $actor, $skipped);
            $this->resetExistingPasswords($orgId, $password);

            $this->newLine();
            $this->line("  Created {$created} new employee account(s).");

            Auth::forgetGuards();
        });

        $after = $this->snapshot($witnessOrgs);

        $this->reportCounts($before, $after, $witnessOrgs, $orgId);
        $this->reportInsideTenant($orgId);
        $this->reportLogins($orgId, $password);

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('The app refused these:');
            foreach ($skipped as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }

    /** The user the writes are attributed to: an HR officer in the org, else its first member. */
    private function hrActorFor(int $orgId): ?User
    {
        $memberIds = DB::table('org_user')->where('org_id', $orgId)->pluck('user_id');

        return User::whereIn('id', $memberIds)->role('hr_officer')->first()
            ?? User::whereIn('id', $memberIds)->orderBy('id')->first();
    }

    private function seedDepartments(DepartmentService $service): void
    {
        foreach (self::DEPARTMENTS as $dept) {
            if (Department::where('code', $dept['code'])->exists()) {
                continue;
            }

            $service->create([
                'name' => $dept['name'],
                'code' => $dept['code'],
                'description' => $dept['description'],
                'is_active' => true,
            ]);

            $this->line("  + department {$dept['name']} ({$dept['code']})");
        }
    }

    /**
     * @param  array<int, string>  $skipped
     */
    private function seedEmployees(EmployeeService $service, int $orgId, string $password, array &$skipped): int
    {
        $created = 0;

        foreach (self::PEOPLE as $person) {
            if (User::where('email', $person['email'])->exists()) {
                continue; // already seeded — a re-run must not duplicate the account
            }

            // Seats are org-level and finite. Stop BEFORE filling the last one, so the
            // manual still has a free seat to demonstrate "add an employee" with.
            $used = Seats::used($orgId);
            $limit = Seats::limitFor($orgId);
            if ($used >= $limit - self::SEATS_TO_LEAVE_FREE) {
                $skipped[] = "{$person['email']} — seat cap ({$used}/{$limit}, keeping "
                    . self::SEATS_TO_LEAVE_FREE . ' free)';

                continue;
            }

            $departmentId = Department::where('code', $person['dept'])->value('id');
            if (! $departmentId) {
                $skipped[] = "{$person['email']} — no department with code {$person['dept']}";

                continue;
            }

            try {
                $service->create([
                    'email' => $person['email'],
                    'password' => $password,
                    'first_name' => $person['first'],
                    'middle_name' => $person['middle'],
                    'last_name' => $person['last'],
                    'department_id' => $departmentId,
                    'job_title' => $person['title'],
                    'employment_status' => $person['status'],
                    'hire_date' => $person['hire'],
                    'base_salary' => $person['salary'],
                    'phone' => $person['phone'],
                    'address' => "{$person['city']}, Metro Manila",
                    'nationality' => 'Filipino',
                    'pay_frequency' => 'semi_monthly',
                    'currency' => 'PHP',
                ]);
                $created++;
                $this->line("  + {$person['first']} {$person['last']} — {$person['title']}");
            } catch (ValidationException $e) {
                $skipped[] = "{$person['email']} — " . implode('; ', $e->validator->errors()->all());
            }
        }

        return $created;
    }

    /** Reporting lines + department heads, once everybody exists to point at. */
    private function linkManagersAndHeads(EmployeeService $employees, DepartmentService $departments): void
    {
        $byEmail = fn (string $email) => Employee::whereHas('user', fn ($q) => $q->where('email', $email))->first();

        foreach (self::PEOPLE as $person) {
            if (! $person['manager']) {
                continue;
            }
            $employee = $byEmail($person['email']);
            $manager = $byEmail($person['manager']);
            if ($employee && $manager && (int) $employee->manager_id !== (int) $manager->id) {
                $employees->update($employee, ['manager_id' => $manager->id]);
            }
        }

        // The org's two pre-existing "Staff" records, retitled and given a reporting line.
        foreach (self::EXISTING_PEOPLE as $email => $fix) {
            $employee = $byEmail($email);
            $manager = $byEmail($fix['manager']);
            if (! $employee) {
                continue;
            }

            $changes = [];
            if ($employee->job_title !== $fix['title']) {
                $changes['job_title'] = $fix['title'];
                // A correction fixes the CURRENT role in place — this is a placeholder title
                // being finished, not a promotion, and inventing one would be a lie in the
                // employment history the manual is about to photograph.
                $changes['role_change_type'] = 'correction';
                $changes['role_change_reason'] = 'Placeholder job title completed during setup';
            }
            if ($manager && (int) $employee->manager_id !== (int) $manager->id) {
                $changes['manager_id'] = $manager->id;
            }

            if ($changes !== []) {
                $employees->update($employee, $changes);
                $this->line("  ~ {$email} — {$fix['title']}");
            }
        }

        foreach (self::DEPARTMENT_HEADS as $code => $email) {
            $department = Department::where('code', $code)->first();
            $head = $byEmail($email);
            if (! $department || ! $head || (int) $department->head_id === (int) $head->id) {
                continue;
            }

            $departments->update($department, [
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
                'head_id' => $head->id,
            ]);
        }
    }

    /**
     * Two working weeks of attendance for everyone on the clock, written through
     * AttendanceService::adjust() — the same path HR uses to enter a day by hand, so the
     * status is DERIVED against each employee's schedule and grace period rather than
     * asserted, and every row lands in the audit trail.
     *
     * @param  array<int, string>  $skipped
     */
    private function seedAttendance(AttendanceService $service, int $orgId, array &$skipped): void
    {
        $tz = config('app.timezone_local');
        $from = Carbon::parse(self::ATTENDANCE_FROM, $tz)->startOfDay();
        $to = Carbon::parse(self::ATTENDANCE_TO, $tz)->startOfDay();

        // Regular holidays are not working days — writing punches on one would contradict
        // the holiday calendar the same manual documents.
        $holidays = Holiday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($day->isWeekend() || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }
            $days[] = $day->toDateString();
        }

        // Management is exempt from the time clock — adjust() refuses those days on purpose,
        // because the nightly generator owns them.
        $employees = Employee::with('user')->where('is_management', false)->orderBy('id')->get();

        $rows = 0;
        $kept = 0;
        foreach ($employees as $index => $employee) {
            $email = $employee->user?->email ?? '';

            foreach ($days as $dayIndex => $date) {
                $exception = self::ATTENDANCE_EXCEPTIONS["{$email}|{$date}"] ?? null;

                $payload = $exception ?? [
                    'time_in' => self::TIME_IN_ROTATION[($index + $dayIndex) % count(self::TIME_IN_ROTATION)],
                    'time_out' => self::TIME_OUT_ROTATION[($index * 3 + $dayIndex) % count(self::TIME_OUT_ROTATION)],
                    'note' => null,
                ];

                // RE-RUN GUARD, and it is not decorative. adjust() finds the day with
                // firstOrNew(['work_date' => '2026-08-24']), but the `date` cast persists the
                // column through the default datetime format — so the stored value is
                // '2026-08-24 00:00:00' and that equality never matches again on SQLite. A
                // second run therefore tried to INSERT a duplicate and died on the
                // (employee_id, work_date) unique index. Matching with whereDate() is the
                // comparison that actually holds, so a re-run leaves finished days alone.
                $existing = Attendance::where('employee_id', $employee->id)
                    ->whereDate('work_date', $date)
                    ->first();

                if ($existing) {
                    if (! $this->option('refresh-attendance')) {
                        $kept++;

                        continue;
                    }

                    // forceDelete, not delete: the row soft-deletes, and a soft-deleted row
                    // still occupies the unique index the replacement needs.
                    $existing->forceDelete();
                }

                try {
                    $row = $service->adjust($employee, $date, $payload);
                    $this->applyBreakBookkeeping($row);
                    $rows++;
                } catch (ValidationException $e) {
                    $skipped[] = "attendance {$email} {$date} — " . implode('; ', $e->validator->errors()->all());
                }
            }
        }

        $this->line("  + {$rows} attendance day(s) across " . count($employees) . ' employee(s)'
            . ($kept > 0 ? " ({$kept} day(s) already present, left as they were)" : ''));

        $this->normaliseWorkDates($orgId);
    }

    /**
     * adjust() is HR's correction path and only accepts the two punches, so it prices the
     * day gross — the unpaid lunch is not one of the fields it takes. Every other demo
     * attendance in this platform carries the standard 60-minute lunch + 15-minute break
     * (see AttendanceDemoSeeder), and a roster where this org alone reports 10-hour days
     * would misrepresent the product. Written as a model update so the tenant stamp and
     * the row's own events still apply.
     */
    private function applyBreakBookkeeping(Attendance $row): void
    {
        if (! $row->time_in || ! $row->time_out) {
            return;
        }

        $gross = ($row->time_out->getTimestamp() - $row->time_in->getTimestamp()) / 60;
        $row->update([
            'lunch_minutes' => 60,
            'break_minutes' => 15,
            'hours_worked' => round(max(0, $gross - 60) / 60, 2),
        ]);
    }

    /**
     * Store work_date as a bare Y-m-d, so the attendance screens can actually find these rows.
     *
     * VERIFIED BY LOOKING, which is the only reason this exists. GET /api/v1/hr/attendance
     * came back with all eight people "absent" and every punch null, on a day that had just
     * been written. The roster reads `Attendance::where('work_date', $date)` against a bare
     * 'Y-m-d', while the model's `date` cast SERIALISES through the default 'Y-m-d H:i:s'.
     * On MySQL the DATE column truncates on the way in and the equality holds; SQLite is
     * dynamically typed and keeps '2026-09-02 00:00:00' verbatim, so it matches nothing. The
     * same mismatch also drops the last day of every inclusive whereBetween() range.
     *
     * So this is a storage-format normalisation for the local SQLite dev database, not a
     * business write: no column but the date's own formatting changes, and it is pinned to
     * one org_id explicitly (DB::table bypasses the tenant scope — which is exactly why the
     * org is named here rather than assumed).
     */
    private function normaliseWorkDates(int $orgId): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $fixed = DB::table('hr_attendance')
            ->where('org_id', $orgId)
            ->whereRaw('work_date <> date(work_date)')
            ->update(['work_date' => DB::raw('date(work_date)')]);

        if ($fixed > 0) {
            $this->line("  ~ normalised {$fixed} work_date value(s) to Y-m-d so the roster can read them");
        }
    }

    /**
     * @param  array<int, string>  $skipped
     */
    private function seedLeave(LeaveService $service, ?User $actor, array &$skipped): void
    {
        foreach (self::LEAVE as $item) {
            $employee = Employee::whereHas('user', fn ($q) => $q->where('email', $item['email']))->first();
            $type = LeaveType::where('code', $item['type'])->first();

            if (! $employee || ! $type) {
                $skipped[] = "leave for {$item['email']} ({$item['type']}) — employee or leave type missing";

                continue;
            }

            // Re-run guard: one request per person / type / start date.
            $existing = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->whereDate('start_date', $item['start'])
                ->first();

            if ($existing) {
                continue;
            }

            try {
                $request = $service->create($employee, [
                    'leave_type_id' => $type->id,
                    'start_date' => $item['start'],
                    'end_date' => $item['end'],
                    'reason' => $item['reason'],
                ]);
            } catch (ValidationException $e) {
                $skipped[] = "leave for {$item['email']} ({$item['type']} {$item['start']}) — "
                    . implode('; ', $e->validator->errors()->all());

                continue;
            }

            if ($item['decision'] === 'approve' && $actor) {
                $service->approve($request, $actor);
            } elseif ($item['decision'] === 'reject' && $actor) {
                $service->reject($request, $actor, $item['note']);
            }

            $state = match ($item['decision']) {
                'approve' => 'approved',
                'reject' => 'rejected',
                default => 'pending',
            };
            $this->line("  + leave {$item['email']} {$item['type']} {$item['start']} ({$state})");
        }
    }

    /**
     * Opt-in: give the org's PRE-EXISTING members the same known password.
     *
     * The accounts that were already in this tenant were created by hand, and their
     * passwords are not recoverable from a hash — including the HR officer's, which is the
     * one account every HR screenshot has to be taken from. Off by default, because
     * overwriting a password nobody asked you to overwrite is not a thing to do quietly.
     */
    private function resetExistingPasswords(int $orgId, string $password): void
    {
        if (! $this->option('reset-existing')) {
            return;
        }

        $seeded = array_column(self::PEOPLE, 'email');
        $memberIds = DB::table('org_user')->where('org_id', $orgId)->pluck('user_id');

        foreach (User::whereIn('id', $memberIds)->get() as $user) {
            if (in_array($user->email, $seeded, true)) {
                continue; // created here — already on the known password
            }

            $user->forceFill(['password' => Hash::make($password)])->save();
            $this->line("  ~ password reset: {$user->email}");
        }
    }

    /**
     * Row counts per org, read WITHOUT the tenant scope and filtered on org_id directly.
     * Deliberately not through the models: the point of this snapshot is to prove nothing
     * leaked into a neighbouring tenant, and asking the scope that did the writing to
     * audit itself would prove nothing.
     *
     * @param  array<int, int>  $orgIds
     * @return array<int, array<string, int>>
     */
    private function snapshot(array $orgIds): array
    {
        $tables = [
            'members' => 'org_user',
            'departments' => 'hr_departments',
            'employees' => 'hr_employees',
            'attendance' => 'hr_attendance',
            'leave_requests' => 'hr_leave_requests',
            'leave_balances' => 'hr_leave_balances',
            'holidays' => 'hr_holidays',
        ];

        $out = [];
        foreach ($orgIds as $orgId) {
            foreach ($tables as $label => $table) {
                $out[$orgId][$label] = DB::table($table)->where('org_id', $orgId)->count();
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, int>>  $before
     * @param  array<int, array<string, int>>  $after
     * @param  array<int, int>  $orgIds
     */
    private function reportCounts(array $before, array $after, array $orgIds, int $targetOrg): void
    {
        $rows = [];
        foreach ($orgIds as $orgId) {
            foreach ($before[$orgId] as $label => $wasCount) {
                $now = $after[$orgId][$label];
                $delta = $now - $wasCount;
                $rows[] = [
                    $orgId . ($orgId === $targetOrg ? ' (target)' : ' (untouched)'),
                    $label,
                    $wasCount,
                    $now,
                    $delta === 0 ? '—' : sprintf('%+d', $delta),
                ];
            }
        }

        $this->newLine();
        $this->table(['org', 'table', 'before', 'after', 'delta'], $rows);

        $leaked = array_filter(
            $orgIds,
            fn (int $id) => $id !== $targetOrg && $before[$id] !== $after[$id],
        );

        if ($leaked !== []) {
            $this->error('LEAK: counts changed in org(s) ' . implode(', ', $leaked));

            return;
        }

        $this->info('Neighbouring orgs unchanged.');
    }

    /** The same counts read the way the app reads them — through the tenant scope. */
    private function reportInsideTenant(int $orgId): void
    {
        CurrentOrg::runAs($orgId, function () use ($orgId) {
            $this->newLine();
            $this->line("Inside CurrentOrg::runAs({$orgId}) — what the app itself sees:");
            $this->table(['metric', 'count'], [
                ['departments', Department::count()],
                ['employees', Employee::count()],
                ['  with a manager', Employee::whereNotNull('manager_id')->count()],
                ['attendance rows', Attendance::count()],
                ['  present', Attendance::where('status', 'present')->count()],
                ['  late', Attendance::where('status', 'late')->count()],
                ['  absent', Attendance::where('status', 'absent')->count()],
                ['  on leave', Attendance::where('status', 'on_leave')->count()],
                ['leave requests', LeaveRequest::count()],
                ['  pending', LeaveRequest::where('status', 'pending')->count()],
                ['  approved', LeaveRequest::where('status', 'approved')->count()],
                ['  rejected', LeaveRequest::where('status', 'rejected')->count()],
                ['leave balances', LeaveBalance::count()],
                ['holidays', Holiday::count()],
                ['seats used / limit', Seats::used($orgId) . ' / ' . Seats::limitFor($orgId)],
            ]);
        });
    }

    private function reportLogins(int $orgId, string $password): void
    {
        $memberIds = DB::table('org_user')->where('org_id', $orgId)->pluck('user_id');
        $seeded = array_column(self::PEOPLE, 'email');

        $rows = User::whereIn('id', $memberIds)->orderBy('id')->get()->map(fn (User $u) => [
            $u->email,
            $u->name,
            implode(', ', $u->getRoleNames()->all()),
            in_array($u->email, $seeded, true) || $this->option('reset-existing')
                ? $password
                : '(pre-existing — not set by this command)',
        ])->all();

        $this->newLine();
        $this->line("Demo logins for org {$orgId}:");
        $this->table(['email', 'name', 'roles', 'password'], $rows);
    }
}
