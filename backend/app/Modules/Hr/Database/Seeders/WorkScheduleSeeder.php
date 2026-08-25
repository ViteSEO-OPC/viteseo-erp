<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Database\Seeder;

/**
 * Seeds a default office schedule + a field schedule (Company → Settings), and the
 * single attendance-policy row. Employees resolve to the default until HR assigns
 * them another or sets per-person overrides.
 */
class WorkScheduleSeeder extends Seeder
{
    public function run(): void
    {
        // Company default: 08:00–18:00, 1h lunch → 9 paid hours. HR-changeable.
        WorkSchedule::firstOrCreate(
            ['name' => 'Office'],
            [
                'workdays' => [1, 2, 3, 4, 5],
                'start_time' => '08:00',
                'end_time' => '18:00',
                'grace_minutes' => 15,
                'unpaid_break_minutes' => 60,
                'paid_break_minutes' => 20,
                'am_start_time' => '08:00',
                'am_end_time' => '12:00',
                'pm_start_time' => '13:00',
                'pm_end_time' => '18:00',
                'is_default' => true,
            ],
        );

        // Field: Mon–Sat, 08:00–17:00, 1h lunch → 8 paid hours.
        WorkSchedule::firstOrCreate(
            ['name' => 'Field'],
            [
                'workdays' => [1, 2, 3, 4, 5, 6],
                'start_time' => '08:00',
                'end_time' => '17:00',
                'grace_minutes' => 30,
                'unpaid_break_minutes' => 60,
                'paid_break_minutes' => 20,
                'am_start_time' => '08:00',
                'am_end_time' => '12:00',
                'pm_start_time' => '13:00',
                'pm_end_time' => '17:00',
                'is_default' => false,
            ],
        );

        // Single source of truth for the policy defaults (incl. the deduction switches,
        // which all seed to their current-behaviour value = a no-op).
        app(AttendancePolicyService::class)->get();
    }
}
