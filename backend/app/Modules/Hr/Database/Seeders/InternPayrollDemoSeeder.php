<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\InternAllowanceVersion;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\InternAllowanceService;
use App\Modules\Hr\Support\InternAllowancePolicy;
use App\Support\CurrentOrg;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Interns to run payroll on: three of them across the 16–31 Aug and 1–15 Sep cutoffs, each
 * exercising a different part of Company → Intern Allowance.
 *
 *   Kim Santos       company default ₱500/day — an absence, a late arrival, a half day marked by HR,
 *                    a day of leave, and the National Heroes Day holiday off.
 *   Ben Villanueva   own allowance ₱650/day — worked the regular holiday, forgot a clock-out, left two
 *                    hours early, a day below the minimum attendance, and a Saturday worked.
 *   Lia Mendoza      converted to probationary staff on Tue 8 Sep — the 1–15 Sep slip pays salary from
 *                    the 8th and her internship days before it as an allowance line.
 *
 * Idempotent: does nothing once Kim's account exists. Logins use the password "password".
 */
class InternPayrollDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    private const FIRST_DAY = '2026-08-03';

    private const LAST_DAY = '2026-09-14';

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        // Run on its own (`db:seed --class=…`) there is no request and no user to name the tenant,
        // so it names the provider org the way DatabaseSeeder does.
        $orgId = CurrentOrg::id() ?? DB::table('orgs')->orderBy('id')->value('id');

        CurrentOrg::runAs($orgId, fn () => $this->seedInterns());
    }

    private function seedInterns(): void
    {
        if (User::where('email', 'intern.kim@viteseo.ph')->exists()) {
            $this->command?->info('Intern payroll demo already seeded — skipped.');

            return;
        }

        $this->allowanceRules();

        $dept = Department::where('code', 'ENG')->value('id') ?? Department::query()->value('id');

        $kim = $this->intern('Kim', 'Santos', 'intern.kim@viteseo.ph', $dept, null, 'Adamson University');
        $ben = $this->intern('Ben', 'Villanueva', 'intern.ben@viteseo.ph', $dept, 650, 'Mapúa University');
        $lia = $this->intern('Lia', 'Mendoza', 'intern.lia@viteseo.ph', $dept, null, 'University of Santo Tomas');

        foreach ($this->workdays() as $date) {
            match ($date) {
                '2026-08-26' => null,                                          // absent, no record
                '2026-08-31' => null,                                          // regular holiday, not worked
                '2026-09-03' => $this->punch($kim, $date, '08:45', '18:00', 'late'),
                '2026-09-09' => $this->halfDayMarkedByHr($kim, $date),
                '2026-09-11' => null,                                          // on leave, below
                default => $this->punch($kim, $date),
            };

            match ($date) {
                '2026-09-02' => $this->punch($ben, $date, '08:00', null),       // forgot to clock out
                '2026-09-04' => $this->punch($ben, $date, '08:00', '16:00'),    // two hours short
                '2026-09-10' => $this->punch($ben, $date, '13:00', '16:00'),    // below 50% of the day
                default => $this->punch($ben, $date),                         // incl. the holiday, worked
            };

            if ($date !== '2026-08-31') {
                $this->punch($lia, $date);
            }
        }

        // Ben worked Saturday 5 Sep, a rest day.
        $this->punch($ben, '2026-09-05', '09:00', '14:00', 'present', 0);

        $this->leave($kim, '2026-09-11');

        // Converted inside the 1–15 Sep cutoff.
        app(EmployeeService::class)->convertIntern($lia->fresh(), [
            'employment_status' => 'probationary',
            'effective_date' => '2026-09-08',
            'base_salary' => 25000,
            'job_title' => 'Junior Software Engineer',
            'acknowledge_repricing' => true,
        ]);

        $this->command?->info('Seeded interns: intern.kim@, intern.ben@, intern.lia@viteseo.ph (password: password).');
    }

    /**
     * The rules the demo runs under — only if nobody has configured the page yet. Filed as the
     * opening version: a company that has always paid interns this way, so the cutoffs already in
     * the demo data price under them too.
     */
    private function allowanceRules(): void
    {
        $service = app(InternAllowanceService::class);

        if (InternAllowanceVersion::count() > 1
            || (float) $service->asOf(InternAllowanceService::OPENING_DATE)->get('daily_amount') > 0) {
            return;
        }

        $service->update(array_merge(InternAllowancePolicy::DEFAULTS, [
            'daily_amount' => 500,
            'required_hours_per_day' => 8,
            'late_rule' => 'missed_hours',
            'undertime_rule' => 'missed_hours',
            'half_day_rule' => 'pay_50',
            'regular_holiday_not_worked' => 'allowance',
            'regular_holiday_worked' => 'allowance_with_premium',
            'rest_day_worked' => 'allowance',
            'min_attendance_pct' => 50,
            'below_min_attendance' => 'treat_as_half_day',
            'leave_pay_rule' => 'unpaid',
            'effective_from' => InternAllowanceService::OPENING_DATE,
        ]));
    }

    private function intern(string $first, string $last, string $email, int $dept, ?float $allowance, string $school): Employee
    {
        return app(EmployeeService::class)->create([
            'first_name' => $first,
            'last_name' => $last,
            'name' => "{$first} {$last}",
            'email' => $email,
            'password' => 'password',
            'department_id' => $dept,
            'job_title' => 'Software Engineer Intern',
            'employment_status' => 'ojt',
            'hire_date' => self::FIRST_DAY,
            'base_salary' => 0,
            'pay_frequency' => 'semi_monthly',
            'allowance_amount' => $allowance,
            'university' => $school,
            'ojt_required_hours' => 486,
        ]);
    }

    /** @return list<string> Mon–Fri from the first day to the last. */
    private function workdays(): array
    {
        $tz = config('app.timezone_local');
        $days = [];

        for ($d = Carbon::parse(self::FIRST_DAY, $tz); $d->lte(Carbon::parse(self::LAST_DAY, $tz)); $d->addDay()) {
            if ($d->isoWeekday() <= 5) {
                $days[] = $d->toDateString();
            }
        }

        return $days;
    }

    private function punch(Employee $employee, string $date, string $in = '08:00', ?string $out = '18:00', string $status = 'present', int $lunch = 60): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", $tz)->utc(),
            'time_out' => $out === null ? null : Carbon::parse("{$date} {$out}", $tz)->utc(),
            'status' => $status,
            'lunch_minutes' => $lunch,
            'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function halfDayMarkedByHr(Employee $employee, string $date): void
    {
        $tz = config('app.timezone_local');

        (new Attendance)->forceFill([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} 12:00", $tz)->utc(),
            'status' => 'half_day',
            'status_override' => true,
            'lunch_minutes' => 0,
            'break_minutes' => 0,
            'source' => Attendance::SOURCE_MANUAL,
        ])->save();
    }

    /** An approved day of leave, filed for the record — an intern's leave draws no credits. */
    private function leave(Employee $employee, string $date): void
    {
        (new LeaveRequest)->forceFill([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', 'ANL')->value('id') ?? LeaveType::query()->value('id'),
            'start_date' => $date,
            'end_date' => $date,
            'is_half_day' => false,
            'days' => 1,
            'reason' => 'Thesis defense at school',
            'status' => 'approved',
            'reviewed_by' => User::where('email', 'hr@viteseo.ph')->value('id'),
            'reviewed_at' => now(),
            'deducted_from' => 'none',
        ])->save();
    }
}
