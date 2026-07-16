<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\WorkSchedule;
use Illuminate\Database\Seeder;

/**
 * Seeds a default office schedule + a field schedule (Company → Rules), and the
 * single attendance-policy row. Employees resolve to the default until HR assigns
 * them another or sets per-person overrides.
 */
class WorkScheduleSeeder extends Seeder
{
    public function run(): void
    {
        WorkSchedule::firstOrCreate(
            ['name' => 'Office'],
            [
                'workdays' => [1, 2, 3, 4, 5],
                'start_time' => '09:00',
                'end_time' => '18:00',
                'grace_minutes' => 15,
                'is_default' => true,
            ],
        );

        WorkSchedule::firstOrCreate(
            ['name' => 'Field'],
            [
                'workdays' => [1, 2, 3, 4, 5, 6],
                'start_time' => '08:00',
                'end_time' => '17:00',
                'grace_minutes' => 30,
                'is_default' => false,
            ],
        );

        AttendancePolicy::firstOrCreate([], [
            'consecutive_lates_before_absence' => 3,
            'monthly_late_reset' => true,
            'awol_days_threshold' => 3,
            'task_format' => ['require_english' => true, 'uppercase' => true],
        ]);
    }
}
