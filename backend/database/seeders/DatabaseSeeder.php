<?php

namespace Database\Seeders;

use App\Modules\Hr\Database\Seeders\AttendanceDemoSeeder;
use App\Modules\Hr\Database\Seeders\AttritionDemoSeeder;
use App\Modules\Hr\Database\Seeders\AuditDemoSeeder;
use App\Modules\Hr\Database\Seeders\BenefitProgramSeeder;
use App\Modules\Hr\Database\Seeders\ConcernsDemoSeeder;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Database\Seeders\FaqDemoSeeder;
use App\Modules\Hr\Database\Seeders\HeadcountMovementDemoSeeder;
use App\Modules\Hr\Database\Seeders\HealthSafetyDemoSeeder;
use App\Modules\Hr\Database\Seeders\HolidayDemoSeeder;
use App\Modules\Hr\Database\Seeders\OfficeExpenseDemoSeeder;
use App\Modules\Hr\Database\Seeders\DepartmentSeeder;
use App\Modules\Hr\Database\Seeders\DiversityDemoSeeder;
use App\Modules\Hr\Database\Seeders\EmployeeDemoSeeder;
use App\Modules\Hr\Database\Seeders\LeaveDemoSeeder;
use App\Modules\Hr\Database\Seeders\LeaveReportDemoSeeder;
use App\Modules\Hr\Database\Seeders\LeaveTypeAllotmentSeeder;
use App\Modules\Hr\Database\Seeders\LeaveTypesSeeder;
use App\Modules\Hr\Database\Seeders\PayrollDemoSeeder;
use App\Modules\Hr\Database\Seeders\RecruitmentDemoSeeder;
use App\Modules\Hr\Database\Seeders\ReimbursementDemoSeeder;
use App\Modules\Hr\Database\Seeders\RequestsDemoSeeder;
use App\Modules\Hr\Database\Seeders\WorkScheduleSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Order matters: roles/permissions → departments + leave types (referenced
     * by employees) → demo employees (which create users + balances).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DepartmentSeeder::class,
            LeaveTypesSeeder::class,
            // Config defaults must exist before employees are created — new hires
            // seed their balances/benefits from these (EmployeeService::create).
            LeaveTypeAllotmentSeeder::class,
            ContributionSettingSeeder::class,
            WorkScheduleSeeder::class,
            EmployeeDemoSeeder::class,
            // Real joiner/leaver records so the Headcount & Movement report has data.
            HeadcountMovementDemoSeeder::class,
            // Typed departures across quarters for the Attrition & Tenure report.
            AttritionDemoSeeder::class,
            // Gender values for the Diversity & Pay Equity representation chart.
            DiversityDemoSeeder::class,
            // Benefit programs enrol every employee, so seed after employees exist.
            BenefitProgramSeeder::class,
            LeaveDemoSeeder::class,
            // Approved leave history across months for the Absences & Leaves report.
            LeaveReportDemoSeeder::class,
            AttendanceDemoSeeder::class,
            ReimbursementDemoSeeder::class,
            PayrollDemoSeeder::class,
            RecruitmentDemoSeeder::class,
            RequestsDemoSeeder::class,
            ConcernsDemoSeeder::class,
            // Safety concerns across months for the Health & Safety report.
            HealthSafetyDemoSeeder::class,
            OfficeExpenseDemoSeeder::class,
            FaqDemoSeeder::class,
            HolidayDemoSeeder::class,
            // Backfill representative audit-trail history for the Audit report.
            AuditDemoSeeder::class,
        ]);
    }
}
