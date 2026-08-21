<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Services\ManagementAttendanceService;
use Illuminate\Database\Seeder;

/**
 * This Manila week's attendance for everyone, aligned to the default 08:00–18:00
 * "Office" schedule (1h lunch → 9 paid hours) so the roster shows a real early /
 * on-time / late spread: even-id employees arrive early (07:50), others on time
 * (08:00), Leo runs late (08:35 → drives the tardiness escalation), and Carlo is
 * absent the first two weekdays (→ an orange unauthorised-absence flag). Times are
 * stored UTC. Maya (employee) + Dana (HR) stay un-clocked today for the live demos (§8).
 */
class AttendanceDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (Attendance::query()->exists()) {
            return;
        }

        $tz = config('app.timezone_local');
        $weekStart = now($tz)->startOfWeek();  // Monday
        $today = now($tz)->startOfDay();
        $absentDays = [$weekStart->toDateString(), $weekStart->copy()->addDay()->toDateString()];

        // Management is exempt from the clock, so they get no punches — the generator writes
        // their days instead, below.
        $employees = Employee::with('user')->where('is_management', false)->get();

        for ($day = $weekStart->copy(); $day->lte($today); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $isToday = $day->isSameDay($today);

            foreach ($employees as $emp) {
                $email = $emp->user?->email;

                if ($isToday && in_array($email, ['employee@viteseo.ph', 'hr@viteseo.ph'], true)) {
                    continue;
                }

                // Carlo is explicitly absent the first two weekdays.
                if ($email === 'carlo@viteseo.ph' && in_array($day->toDateString(), $absentDays, true)) {
                    Attendance::create([
                        'employee_id' => $emp->id,
                        'work_date' => $day->toDateString(),
                        'status' => 'absent',
                        'note' => 'No notice',
                    ]);

                    continue;
                }

                // Arrival vs the 08:00 start (15-min grace): Leo late, evens early.
                if ($email === 'leo@viteseo.ph') {
                    [$h, $m] = [8, 35];
                } elseif ($emp->id % 2 === 0) {
                    [$h, $m] = [7, 50];
                } else {
                    [$h, $m] = [8, 0];
                }
                $late = ($h * 60 + $m) > (8 * 60 + 15);
                $timeIn = $day->copy()->setTime($h, $m)->utc(); // Manila → stored UTC

                $row = [
                    'employee_id' => $emp->id,
                    'work_date' => $day->toDateString(),
                    'time_in' => $timeIn,
                    'status' => $late ? 'late' : 'present',
                ];

                if (! $isToday) {
                    $timeOut = $day->copy()->setTime(18, 0)->utc();
                    $net = $timeIn->diffInMinutes($timeOut) - 60 - 15; // lunch + break
                    $row += [
                        'time_out' => $timeOut,
                        'lunch_minutes' => 60,
                        'break_minutes' => 15,
                        'hours_worked' => round($net / 60, 2),
                    ];
                }

                Attendance::create($row);
            }
        }

        $this->seedEdgeCases($employees, $weekStart, $today);

        // The management side of the week: generated rows up to YESTERDAY. Today is left
        // alone on purpose — the nightly job never invents presence for a day still in
        // progress, and the roster derives 'present' for them without a row anyway.
        if ($today->gt($weekStart)) {
            app(ManagementAttendanceService::class)->generate(
                $weekStart->toDateString(),
                $today->copy()->subDay()->toDateString(),
            );
        }
    }

    /**
     * Overwrite a few past weekdays with the awkward cases the deduction engine has to
     * survive. Applied to employees OTHER than the demo logins and the two who already
     * carry a story (Carlo = absences, Leo = tardiness), so no case masks another.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function seedEdgeCases($employees, $weekStart, $today): void
    {
        $reserved = ['employee@viteseo.ph', 'hr@viteseo.ph', 'carlo@viteseo.ph', 'leo@viteseo.ph'];
        $subjects = $employees
            ->filter(fn ($e) => ! in_array($e->user?->email, $reserved, true))
            ->values();

        // Past weekdays only — an edge case on a future/today row would not be scanned.
        $days = [];
        for ($d = $weekStart->copy(); $d->lt($today); $d->addDay()) {
            if (! $d->isWeekend()) {
                $days[] = $d->copy();
            }
        }

        if ($subjects->isEmpty() || $days === []) {
            return; // early in the week (or a tiny roster) — nothing to plant onto
        }

        // [label, mutator] — each returns the column changes for that day's row.
        $cases = [
            //  / 2a: clocked in, never clocked out. THE most common real case (20% of rows).
            ['Forgot to clock out', fn ($day) => [
                'time_out' => null,
                'lunch_minutes' => 0,
                'break_minutes' => 0,
                'hours_worked' => 0,
                'note' => 'Forgot to clock out',
            ]],
            // Break well over the 20-min paid allowance — flagged, never deducted.
            ['40-minute break', fn ($day) => [
                'break_minutes' => 40,
                'note' => 'Long coffee break',
            ]],
            // Lunch over the 60-min allowance — flagged, never deducted.
            ['90-minute lunch', fn ($day) => [
                'lunch_minutes' => 90,
                'note' => 'Long lunch',
            ]],
            // A break started and never ended (auto-closed at clock-out → garbage minutes).
            ['Open break punch', fn ($day) => [
                'break_started_at' => $day->copy()->setTime(15, 0)->utc(),
                'break_minutes' => 180,
                'note' => 'Break never ended — suspected missing punch',
            ]],
            // Left early with no early-out request — clock-derived undertime.
            ['Early leave (undertime)', fn ($day) => [
                'time_out' => $day->copy()->setTime(15, 0)->utc(),
                'hours_worked' => 6.0,
                'note' => 'Left early, no request filed',
            ]],
        ];

        foreach ($cases as $i => [$label, $mutate]) {
            $emp = $subjects[$i % $subjects->count()];
            $day = $days[$i % count($days)];

            $row = Attendance::where('employee_id', $emp->id)
                ->where('work_date', $day->toDateString())
                ->first();

            if ($row) {
                $row->update($mutate($day));
            }
        }

        // An approved half-day AND a late arrival on the same day — the case where
        // "what does a minute cost?" is undefined until the half-day windows are read.
        $halfDaySubject = $subjects->last();
        $halfDay = $days[count($days) - 1];
        $row = Attendance::where('employee_id', $halfDaySubject->id)
            ->where('work_date', $halfDay->toDateString())
            ->first();

        if ($row) {
            $row->update([
                'time_in' => $halfDay->copy()->setTime(13, 30)->utc(), // late for the PM half
                'time_out' => $halfDay->copy()->setTime(18, 0)->utc(),
                'status' => 'late',
                'lunch_minutes' => 0,
                'break_minutes' => 0,
                'hours_worked' => 4.5,
                'note' => 'Half-day (AM off) + late for the PM half',
            ]);

            HalfDayRequest::firstOrCreate(
                [
                    'employee_id' => $halfDaySubject->id,
                    'work_date' => $halfDay->toDateString(),
                ],
                [
                    'period' => 'AM',
                    'reason' => 'Medical appointment',
                    'status' => 'approved',
                    'reviewed_at' => now(),
                ],
            );
        }
    }
}
