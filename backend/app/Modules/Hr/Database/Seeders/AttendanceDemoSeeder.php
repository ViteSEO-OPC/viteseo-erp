<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * This Manila week's attendance for everyone, aligned to the default 09:00–18:00
 * "Office" schedule so the roster shows a real early / on-time / late spread:
 * even-id employees arrive early (08:50), others on time (09:00), Leo runs late
 * (09:35 → drives the tardiness escalation), and Carlo is absent the first two
 * weekdays (→ an orange unauthorised-absence flag). Times are stored UTC.
 * Maya (employee) + Dana (HR) stay un-clocked today for the live demos (§8).
 */
class AttendanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Attendance::query()->exists()) {
            return;
        }

        $tz = config('app.timezone_local');
        $weekStart = now($tz)->startOfWeek();  // Monday
        $today = now($tz)->startOfDay();
        $absentDays = [$weekStart->toDateString(), $weekStart->copy()->addDay()->toDateString()];

        $employees = Employee::with('user')->get();

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

                // Arrival vs the 09:00 start (15-min grace): Leo late, evens early.
                if ($email === 'leo@viteseo.ph') {
                    [$h, $m] = [9, 35];
                } elseif ($emp->id % 2 === 0) {
                    [$h, $m] = [8, 50];
                } else {
                    [$h, $m] = [9, 0];
                }
                $late = ($h * 60 + $m) > (9 * 60 + 15);
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
    }
}
