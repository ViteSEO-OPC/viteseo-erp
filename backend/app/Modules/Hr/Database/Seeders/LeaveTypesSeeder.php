<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Annual Leave', 'code' => 'ANL', 'days_per_year' => 15, 'is_paid' => true, 'color' => '#4FDBD7'],
            ['name' => 'Sick Leave', 'code' => 'SCK', 'days_per_year' => 10, 'is_paid' => true, 'color' => '#22C554'],
            ['name' => 'Emergency Leave', 'code' => 'EMG', 'days_per_year' => 5, 'is_paid' => true, 'color' => '#F0A608'],
            ['name' => 'Bereavement Leave', 'code' => 'BRV', 'days_per_year' => 3, 'is_paid' => true, 'color' => '#6B7280'],
            ['name' => 'Service Incentive Leave', 'code' => 'SIL', 'days_per_year' => 5, 'is_paid' => true, 'color' => '#2563EB'],
            ['name' => 'Unpaid Leave (LWOP)', 'code' => 'LWOP', 'days_per_year' => 0, 'is_paid' => false, 'color' => '#EF4444'],
        ];

        foreach ($types as $type) {
            LeaveType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
