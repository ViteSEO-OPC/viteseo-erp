<?php

declare(strict_types=1);

namespace App\Modules\Hr\Database\Seeders;

use App\Models\Org;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\HolidayService;
use App\Modules\Hr\Services\ScheduleService;
use App\Support\CurrentOrg;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon as LaravelCarbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A WHOLE MONTH of punches, so the Daily Time Record has a time record to show.
 *
 * AttendanceDemoSeeder covers this Manila week, which is the right scope for the attendance
 * board — it is a board about today. The DTR is a month-scoped report with a month picker, and
 * against a week of data it renders a page of dashes: no finished month to pick, two or three
 * logged days per employee, and a "Late by" column that almost never has anything to say.
 *
 * So this fills LAST month, complete, and leaves the current week alone for the seeder that owns it.
 *
 * ARRIVALS ARE DRAWN AGAINST THE EMPLOYEE'S OWN SCHEDULE, not against a hardcoded 08:00. An
 * offset is picked relative to their real start time and grace window, which is what makes the
 * spread mean anything: "inside grace" has to be inside THEIR grace, or the demo shows lateness
 * where the report will correctly show none.
 *
 * And the stored status is read back out of AttendanceService::arrivalCategory() rather than
 * decided here. The seeder cannot then disagree with the screens: a row marked `late` is late
 * because the same rule the report and the payslip use says it is.
 */
class AttendanceHistoryDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    /** Arbitrary but FIXED, so two reseeds produce the same month. */
    private const RNG_SEED = 20260912;

    /** Named, not guessed — see OfficeExpenseHistoryDemoSeeder for the reasoning. */
    private const PROVIDER_ORG = 'Vite SEO Digital Advertising OPC';

    /**
     * The shape of a month's days, as weights.
     *
     * Deliberately lopsided: most people are on time most days, and a column where a third of
     * the rows are late is one a reader learns to skip. About one day in five is late here, and
     * the two late bands are kept apart because they are different stories — a few minutes past
     * the gate, and a genuinely missed morning — which is what the DTR is read to tell apart.
     */
    private const SPREAD = [
        'no_clock_out' => 12,  // clocked in on time and never clocked out — the commonest real gap
        'absent' => 5,
        'early' => 33,         // before the start time
        'grace' => 32,         // past the start, inside the window — not late at all
        'slightly_late' => 12, // 1–25 minutes past the gate
        'very_late' => 6,      // 26–90 minutes past the gate
    ];

    /**
     * The same bands for the one employee the month has a pattern for.
     *
     * A SEPARATE DRAW, not a nudged one. Shifting a roll along the axis piles every overflow
     * onto whichever band sits at the end of it, which turned "often late" into a month of
     * nothing but 90-minute mornings — and, before the bands were reordered, into a month of
     * forgotten clock-outs instead, which is not the same story at all.
     */
    private const CHRONIC_SPREAD = [
        'no_clock_out' => 8,
        'absent' => 5,
        'early' => 10,
        'grace' => 27,
        'slightly_late' => 30,
        'very_late' => 20,
    ];

    /** One person the month has a visible pattern for; the DTR is how you would notice. */
    private const CHRONICALLY_LATE = 'leo@viteseo.ph';

    public function run(ScheduleService $schedules, HolidayService $holidays, AttendanceService $attendance): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        $orgId = CurrentOrg::id() ?? Org::where('name', self::PROVIDER_ORG)->value('id');

        if ($orgId === null) {
            $this->command?->warn('AttendanceHistoryDemoSeeder: no org to seed into — skipped.');

            return;
        }

        CurrentOrg::runAs($orgId, fn () => $this->seedLastMonth($schedules, $holidays, $attendance));
    }

    private function seedLastMonth(ScheduleService $schedules, HolidayService $holidays, AttendanceService $attendance): void
    {
        $tz = config('app.timezone_local');
        $month = CarbonImmutable::now($tz)->subMonthNoOverflow()->startOfMonth();
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        // This seeder owns last month. Scoped to the month rather than to the table so it runs on
        // a database that already carries this week's punches, and so a second `db:seed` does not
        // file a second month on top of the first.
        if (Attendance::whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->exists()) {
            return;
        }

        // Management is exempt from the clock — ManagementAttendanceService writes their days, and
        // inventing punches for them here would contradict the generator.
        $employees = Employee::with('user')->where('is_management', false)->get();

        if ($employees->isEmpty()) {
            return;
        }

        $rng = new Randomizer(new Mt19937(self::RNG_SEED));
        $holidayDates = array_flip($holidays->nonWorkingDatesBetween(
            $this->laravel($start),
            $this->laravel($end),
        ));
        $onLeave = $this->leaveDays($start->toDateString(), $end->toDateString());

        foreach ($employees as $employee) {
            $eff = $schedules->effectiveFor($employee);
            $startMinute = $this->minutes($eff['start_time']);
            $endMinute = $this->minutes($eff['end_time']);

            // Nothing to be early or late against. Their days would be invented rather than
            // measured, and the report would show a lateness nobody could check.
            if ($startMinute === null || $endMinute === null) {
                continue;
            }

            $workdays = array_map('intval', $eff['workdays']);
            $grace = (int) ($eff['grace_minutes'] ?? 0);
            $lunch = (int) ($eff['unpaid_break_minutes'] ?? 60);
            $break = (int) ($eff['paid_break_minutes'] ?? 0);
            $chronic = $employee->user?->email === self::CHRONICALLY_LATE;

            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $date = $day->toDateString();

                // Days nobody was expected in. Left with NO ROW so the report says what they were
                // — rest day, holiday, on leave — instead of an absence with a story attached.
                if (! in_array($day->isoWeekday(), $workdays, true)
                    || isset($holidayDates[$date])
                    || isset($onLeave["{$employee->id}|{$date}"])
                    || ! $this->employedOn($employee, $date)
                ) {
                    continue;
                }

                $this->punch($rng, $attendance, $employee, $day, $eff, $startMinute, $endMinute, $grace, $lunch, $break, $chronic);
            }
        }

        $this->seedHalfDayLateCase($employees, $schedules, $attendance, $start, $end);
    }

    /** One day of one employee's month. */
    private function punch(
        Randomizer $rng,
        AttendanceService $attendance,
        Employee $employee,
        CarbonImmutable $day,
        array $eff,
        int $startMinute,
        int $endMinute,
        int $grace,
        int $lunch,
        int $break,
        bool $chronic,
    ): void {
        $band = $this->drawBand($rng, $chronic ? self::CHRONIC_SPREAD : self::SPREAD);

        if ($band === 'absent') {
            Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $day->toDateString(),
                'status' => 'absent',
                'note' => 'No notice',
            ]);

            return;
        }

        // Offsets are relative to THEIR start and THEIR grace: "inside the window" has to be
        // inside the window the report will measure against, or the demo shows a lateness the
        // screens then correctly refuse to report.
        $offset = match ($band) {
            'early' => -$rng->getInt(1, 20),
            'slightly_late' => $grace + $rng->getInt(1, 25),
            'very_late' => $grace + $rng->getInt(26, 90),
            default => $rng->getInt(0, max(0, $grace)),
        };

        $forgotClockOut = $band === 'no_clock_out';

        $timeIn = $day->setTime(0, 0)->addMinutes($startMinute + $offset);
        // Home a few minutes either side of the end, and a late arrival does not stay later to
        // make it up — that shortfall is exactly what the undertime half of the engine prices.
        $timeOut = $forgotClockOut ? null : $day->setTime(0, 0)->addMinutes($endMinute + $rng->getInt(-5, 25));

        $category = $attendance->arrivalCategory(
            $this->laravel($timeIn),
            $eff,
            $this->laravel($day),
            null,
        )['category'];

        $worked = $timeOut === null
            ? 0.0
            : round(max(0, $timeIn->diffInMinutes($timeOut) - $lunch - $break) / 60, 2);

        Attendance::create([
            'employee_id' => $employee->id,
            'work_date' => $day->toDateString(),
            'time_in' => $timeIn->utc(),
            'time_out' => $timeOut?->utc(),
            'lunch_minutes' => $timeOut === null ? 0 : $lunch,
            'break_minutes' => $timeOut === null ? 0 : $break,
            'hours_worked' => $worked,
            // Read back from the same rule the report and the payslip measure with, never decided
            // here — a seeded `late` that the screens compute as on-time is a bug in the demo that
            // reads as a bug in the product.
            'status' => $category === 'late' ? 'late' : 'present',
            'note' => $timeOut === null ? 'Forgot to clock out' : null,
        ]);
    }

    /**
     * One band, by weight.
     *
     * @param  array<string, int>  $spread
     */
    private function drawBand(Randomizer $rng, array $spread): string
    {
        $roll = $rng->getInt(1, array_sum($spread));

        foreach ($spread as $band => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $band;
            }
        }

        return array_key_last($spread);
    }

    /**
     * The case the "Late by" column exists to get right: an approved AM half day, and a punch
     * that is late for the PM half it was measured against.
     *
     * Naively — clock-in minus the schedule's start — this reads as five and a half hours late.
     * Against the worked half it is half an hour, which is the number the payslip charges.
     */
    private function seedHalfDayLateCase(
        $employees,
        ScheduleService $schedules,
        AttendanceService $attendance,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        $employee = $employees->last();
        $eff = $schedules->effectiveFor($employee);
        $pmStart = $this->minutes($eff['pm_start_time']);

        if ($pmStart === null) {
            return; // No afternoon window configured — there is no half day to be late for.
        }

        // A weekday late in the month that actually has a row to overwrite.
        for ($day = $end; $day->gte($start); $day = $day->subDay()) {
            $row = Attendance::where('employee_id', $employee->id)
                ->where('work_date', $day->toDateString())
                ->whereNotNull('time_in')
                ->first();

            if (! $row) {
                continue;
            }

            $timeIn = $day->setTime(0, 0)->addMinutes($pmStart + 30);
            $timeOut = $day->setTime(0, 0)->addMinutes($this->minutes($eff['end_time']) ?? 18 * 60);

            HalfDayRequest::firstOrCreate(
                ['employee_id' => $employee->id, 'work_date' => $day->toDateString()],
                ['period' => 'AM', 'reason' => 'Medical appointment', 'status' => 'approved', 'reviewed_at' => now()],
            );

            $row->update([
                'time_in' => $timeIn->utc(),
                'time_out' => $timeOut->utc(),
                'lunch_minutes' => 0,
                'break_minutes' => 0,
                'hours_worked' => round(max(0, $timeIn->diffInMinutes($timeOut)) / 60, 2),
                'status' => $attendance->arrivalCategory($this->laravel($timeIn), $eff, $this->laravel($day), 'AM')['category'] === 'late'
                    ? 'late'
                    : 'present',
                'note' => 'Half-day (AM off) + late for the PM half',
            ]);

            return;
        }
    }

    /**
     * Approved leave in the window as "employeeId|date" keys.
     *
     * @return array<string, true>
     */
    private function leaveDays(string $start, string $end): array
    {
        $days = [];

        $requests = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get(['employee_id', 'start_date', 'end_date']);

        foreach ($requests as $request) {
            $from = CarbonImmutable::parse($request->start_date);
            $to = CarbonImmutable::parse($request->end_date);

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $days["{$request->employee_id}|{$day->toDateString()}"] = true;
            }
        }

        return $days;
    }

    /** Days before someone was hired, or after they left, are not days they missed. */
    private function employedOn(Employee $employee, string $date): bool
    {
        $hire = $employee->hire_date?->toDateString();
        $separation = $employee->separation_date?->toDateString();

        return ($hire === null || $date >= $hire) && ($separation === null || $date <= $separation);
    }

    /**
     * The framework's Carbon, which is what the services type-hint.
     *
     * `Illuminate\Support\Carbon` EXTENDS `Carbon\Carbon`, so the substitution only works in
     * this direction — handing a plain Carbon to a parameter typed as Laravel's is a TypeError,
     * which is how this was found.
     */
    private function laravel(CarbonImmutable $moment): LaravelCarbon
    {
        return LaravelCarbon::instance($moment->toMutable());
    }

    /** "08:00" → 480. Null stays null: an unknown time is not midnight. */
    private function minutes(?string $hm): ?int
    {
        if ($hm === null || ! str_contains($hm, ':')) {
            return null;
        }

        [$h, $m] = explode(':', $hm);

        return ((int) $h) * 60 + (int) $m;
    }
}
