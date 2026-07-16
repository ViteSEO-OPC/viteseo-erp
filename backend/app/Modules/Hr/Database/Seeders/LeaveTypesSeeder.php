<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypesSeeder extends Seeder
{
    public function run(): void
    {
        // Unplanned leave (sick / emergency / bereavement) is commonly filed after the
        // fact, so those allow backdating within a 14-day window. Planned leave (annual,
        // SIL) and LWOP must be filed for today/future.
        $types = [
            ['name' => 'Annual Leave', 'code' => 'ANL', 'days_per_year' => 15, 'is_paid' => true, 'color' => '#4FDBD7', 'allow_backdated' => false, 'backdate_limit_days' => null],
            ['name' => 'Sick Leave', 'code' => 'SCK', 'days_per_year' => 10, 'is_paid' => true, 'color' => '#22C554', 'allow_backdated' => true, 'backdate_limit_days' => 14],
            ['name' => 'Emergency Leave', 'code' => 'EMG', 'days_per_year' => 5, 'is_paid' => true, 'color' => '#F0A608', 'allow_backdated' => true, 'backdate_limit_days' => 14],
            ['name' => 'Bereavement Leave', 'code' => 'BRV', 'days_per_year' => 3, 'is_paid' => true, 'color' => '#6B7280', 'allow_backdated' => true, 'backdate_limit_days' => 14],
            ['name' => 'Service Incentive Leave', 'code' => 'SIL', 'days_per_year' => 5, 'is_paid' => true, 'color' => '#2563EB', 'allow_backdated' => false, 'backdate_limit_days' => null],
            ['name' => 'Unpaid Leave (LWOP)', 'code' => 'LWOP', 'days_per_year' => 0, 'is_paid' => false, 'color' => '#EF4444', 'allow_backdated' => false, 'backdate_limit_days' => null],
        ];

        foreach ($types as $type) {
            LeaveType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
