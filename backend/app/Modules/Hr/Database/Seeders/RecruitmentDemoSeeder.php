<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Database\Seeder;

class RecruitmentDemoSeeder extends Seeder
{
    use RefusesOutsideDemoEnvironments;

    public function run(): void
    {
        if ($this->demoSeedingBlocked()) {
            return;
        }

        if (Requisition::query()->exists()) {
            return;
        }

        $dept = fn (string $code) => Department::where('code', $code)->value('id');
        $mgr = fn (string $email) => Employee::whereHas(
            'user', fn ($q) => $q->where('email', $email)
        )->value('id');

        $backend = Requisition::create([
            'title' => 'Senior Backend Engineer', 'pipeline' => 'standard', 'employment_type' => 'full_time',
            'salary_range' => '₱140,000 - 180,000', 'location' => 'Manila', 'department_id' => $dept('ENG'),
            'hiring_manager_id' => $mgr('marco@viteseo.ph'), 'openings' => 2,
            'stage' => 'active', 'opened_at' => '2026-05-12', 'target_start_date' => '2026-08-01',
        ]);
        Requisition::create([
            'title' => 'Product Designer', 'pipeline' => 'standard', 'employment_type' => 'full_time',
            'salary_range' => '₱110,000 - 150,000', 'location' => 'Remote', 'department_id' => $dept('PRD'),
            'hiring_manager_id' => $mgr('employee@viteseo.ph'), 'openings' => 1,
            'stage' => 'active', 'opened_at' => '2026-05-28',
        ]);
        Requisition::create([
            'title' => 'People Ops Coordinator', 'pipeline' => 'standard', 'employment_type' => 'full_time',
            'salary_range' => '₱55,000 - 75,000', 'location' => 'Manila', 'department_id' => $dept('HR'),
            'hiring_manager_id' => $mgr('hr@viteseo.ph'), 'openings' => 1,
            'stage' => 'active', 'opened_at' => '2026-04-30',
        ]);
        $ojt = Requisition::create([
            'title' => 'Software Engineer Intern (OJT)', 'pipeline' => 'ojt', 'employment_type' => 'ojt',
            'allowance_amount' => 650.00, 'allowance_period' => 'per_day',
            'location' => 'Hybrid', 'department_id' => $dept('ENG'),
            'hiring_manager_id' => $mgr('marco@viteseo.ph'), 'openings' => 2,
            'stage' => 'active', 'opened_at' => '2026-06-10',
        ]);
        Requisition::create([
            'title' => 'Account Executive', 'pipeline' => 'standard', 'employment_type' => 'full_time',
            'salary_range' => '₱45,000 - 70,000 + commission', 'location' => 'Cebu', 'department_id' => $dept('SAL'),
            'hiring_manager_id' => null, 'openings' => 3,
            'stage' => 'draft', 'opened_at' => '2026-06-24',
        ]);

        // Standard pipeline board (screening → assessment).
        $standard = [
            ['Marco Velez', 7, 'referral', 'screening', null, '₱120,000 - 150,000', 'Manila'],
            ['Aisha Bello', 6, 'linkedin', 'screening', null, '₱110,000 - 140,000', 'Cebu'],
            ['Jon Park', 5, 'jobboard', 'online_interview', 4.0, '₱105,000 - 130,000', 'Remote'],
            ['Priya Nair', 7, 'agency', 'online_interview', 4.5, '₱130,000 - 160,000', 'Manila'],
            ['Tom Bauer', 9, 'referral', 'final_interview', 4.0, '₱140,000 - 175,000', 'Remote'],
            ['Sara Lund', 10, 'direct', 'final_interview', 4.5, '₱150,000 - 185,000', 'Manila'],
            ['Diego Ruiz', 6, 'linkedin', 'assessment', 4.0, '₱125,000 - 155,000', 'Remote'],
            ['Elena Sokolova', 10, 'linkedin', 'assessment', 4.8, '₱160,000 - 200,000', 'Manila'],
        ];
        foreach ($standard as [$name, $exp, $source, $stage, $rating, $salary, $loc]) {
            Candidate::create([
                'requisition_id' => $backend->id, 'name' => $name, 'source' => $source,
                'experience_years' => $exp, 'stage' => $stage, 'rating' => $rating,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
                'expected_salary' => $salary, 'location' => $loc,
            ]);
        }

        // OJT pipeline board (online interview → assignment of roles).
        $ojtCandidates = [
            ['Kim Reyes', 0, 'jobboard', 'online_interview', null, 'Manila', 'Adamson University', 486],
            ['Ben Cruz', 1, 'referral', 'moa_review', 4.0, 'Remote', 'Mapua University', 600],
            ['Lia Tan', 0, 'direct', 'role_assignment', 4.2, 'Cebu', 'University of San Carlos', 400],
        ];
        foreach ($ojtCandidates as [$name, $exp, $source, $stage, $rating, $loc, $school, $hours]) {
            Candidate::create([
                'requisition_id' => $ojt->id, 'name' => $name, 'source' => $source,
                'experience_years' => $exp, 'stage' => $stage, 'rating' => $rating,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
                'location' => $loc, 'school' => $school, 'required_hours' => $hours,
            ]);
        }
    }
}
