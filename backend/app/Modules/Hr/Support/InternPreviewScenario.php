<?php

namespace App\Modules\Hr\Support;

/**
 * The invented month the Calculation Preview prices: counts of each kind of day, turned into the
 * same InternDay list a real month produces, so the preview runs the real pricer and cannot drift
 * from what payroll pays.
 *
 * Days are laid out in a fixed order — absences first, then the holidays straight after them —
 * so a company that forfeits a regular holiday after an absence sees that rule in the preview.
 */
final class InternPreviewScenario
{
    /** The counters that each consume scheduled workdays, in the order they are laid out. */
    public const DAY_COUNTS = [
        'absent_days', 'regular_holidays_off', 'special_holidays_off', 'regular_holidays_worked',
        'special_holidays_worked', 'suspensions_off', 'leave_days', 'half_days',
    ];

    /**
     * @param  array<string, mixed>  $sample  validated preview sample
     * @return list<InternDay>
     */
    public static function days(array $sample): array
    {
        $scheduled = (int) round((float) ($sample['hours_per_day'] ?? 8) * 60);
        $count = static fn (string $key): int => max(0, (int) ($sample[$key] ?? 0));
        $days = [];
        $n = 0;

        $add = function (array $facts, int $times = 1) use (&$days, &$n, $scheduled): void {
            for ($i = 0; $i < $times; $i++) {
                $days[] = new InternDay(...array_merge([
                    'date' => 'Day ' . (++$n),
                    'isWorkday' => true,
                    'isPast' => true,
                    'scheduledMinutes' => $scheduled,
                ], $facts));
            }
        };

        $add(['kind' => InternDay::ABSENT, 'detail' => 'Absent'], $count('absent_days'));
        $add(['kind' => InternDay::HOLIDAY, 'holidayType' => 'regular', 'holidayName' => 'Regular holiday'], $count('regular_holidays_off'));
        $add(['kind' => InternDay::HOLIDAY, 'holidayType' => 'special_non_working', 'holidayName' => 'Special holiday'], $count('special_holidays_off'));
        $add(['kind' => InternDay::HOLIDAY, 'holidayType' => 'regular', 'holidayName' => 'Regular holiday', 'worked' => true, 'workedMinutes' => $scheduled], $count('regular_holidays_worked'));
        $add(['kind' => InternDay::HOLIDAY, 'holidayType' => 'special_non_working', 'holidayName' => 'Special holiday', 'worked' => true, 'workedMinutes' => $scheduled], $count('special_holidays_worked'));
        $add(['kind' => InternDay::SUSPENSION, 'suspensionPaid' => (bool) ($sample['suspensions_paid'] ?? false), 'detail' => 'Work suspended'], $count('suspensions_off'));
        $add(['kind' => InternDay::LEAVE, 'isLeave' => true, 'leavePaid' => (bool) ($sample['leave_type_paid'] ?? false), 'detail' => 'Leave'], $count('leave_days'));
        $add(['kind' => InternDay::HALF_DAY, 'halves' => 1, 'worked' => true, 'workedMinutes' => intdiv($scheduled, 2), 'detail' => 'Approved half day'], $count('half_days'));

        $used = array_sum(array_map($count, self::DAY_COUNTS));
        $present = max(0, (int) ($sample['scheduled_days'] ?? 0) - $used);

        for ($i = 0; $i < $present; $i++) {
            $late = (int) ($sample['late_minutes'][$i] ?? 0);
            $undertime = (int) ($sample['undertime_minutes'][$i] ?? 0);
            $rendered = isset($sample['hours_rendered'][$i])
                ? (int) round((float) $sample['hours_rendered'][$i] * 60)
                : max(0, $scheduled - $late - $undertime);

            $add([
                'kind' => InternDay::WORKED,
                'worked' => true,
                'workedMinutes' => min($rendered, $scheduled),
                'lateMinutes' => $late,
                'lateChargeMinutes' => $late,
                'undertimeMinutes' => $undertime,
                'undertimeChargeMinutes' => $undertime,
                // The sample's overtime and night minutes sit on the first day actually worked.
                'overtimeMinutes' => $i === 0 ? (int) ($sample['overtime_minutes'] ?? 0) : 0,
                'nightMinutes' => $i === 0 ? (int) ($sample['night_minutes'] ?? 0) : 0,
            ]);
        }

        $add(['kind' => InternDay::REST_DAY, 'isWorkday' => false, 'worked' => true, 'workedMinutes' => $scheduled], $count('rest_days_worked'));

        return $days;
    }
}
