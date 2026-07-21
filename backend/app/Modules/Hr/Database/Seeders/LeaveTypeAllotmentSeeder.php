<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Services\EmployeeService;
use Illuminate\Database\Seeder;

/**
 * Default leave credits per employment status. Regular staff get the full
 * entitlement; probationary/contract/part-time get a reduced default. These are
 * only the STARTING credits used at hire — HR edits each person's balances after.
 */
class LeaveTypeAllotmentSeeder extends Seeder
{
    public function run(): void
    {
        // Fraction of the leave type's base days each status gets by default.
        $factors = [
            'regular' => 1.0,
            'probationary' => 0.5,
            'contract' => 0.75,
            'part_time' => 0.5,
        ];

        foreach (LeaveType::all() as $type) {
            foreach (EmployeeService::STATUSES as $status) {
                $factor = $factors[$status] ?? 1.0;
                // Allotments are whole days only — round to the nearest day.
                $days = (int) round((float) $type->days_per_year * $factor);

                LeaveTypeAllotment::updateOrCreate(
                    ['leave_type_id' => $type->id, 'employment_status' => $status],
                    ['days_per_year' => $days],
                );
            }
        }
    }
}
