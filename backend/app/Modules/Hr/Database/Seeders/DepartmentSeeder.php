<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Engineering', 'code' => 'ENG'],
            ['name' => 'Product', 'code' => 'PRD'],
            ['name' => 'Human Resources', 'code' => 'HR'],
            ['name' => 'Sales', 'code' => 'SAL'],
            ['name' => 'Finance', 'code' => 'FIN'],
        ];

        foreach ($departments as $data) {
            Department::firstOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'is_active' => true],
            );
        }
    }
}
