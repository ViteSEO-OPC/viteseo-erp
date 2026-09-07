<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Services\EmployeeService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A read that only worked because the database was covering for the write.
 *
 * `hr_attendance.work_date` is a DATE column and the model casts it to `date`.
 * On the way IN, Eloquent serialises a cast date with the model's $dateFormat —
 * `Y-m-d H:i:s` — so the value handed to the driver is `2026-08-31 00:00:00`.
 * MySQL truncates that on insert, which is why production has always looked
 * right. SQLite's "date" is an affinity label, not a type: it keeps the string,
 * and `where('work_date', '2026-08-31')` then matches nothing.
 *
 * The consequence on a local database was total: 67 of 67 rows on the provider
 * org were unreachable, every attendance roster came back empty, and every
 * inclusive whereBetween() quietly lost its last day.
 *
 * THIS TEST LIVES ON SQLITE ON PURPOSE. The HR suite runs on MySQL, where the
 * engine's coercion hides the bug — a test there passes whether the model is
 * right or wrong, which is how this survived to be found by hand.
 */
class TheDayIsADayNotAMidnightTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_work_date_is_stored_as_a_day_and_can_be_found_by_one(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = app(OrgOnboardingService::class)->createOrg(
            'Bituin Agency', 'Maria', 'maria@agency.ph', 'password-123', ['hr' => 'full'],
        )['org'];

        CurrentOrg::runAs($org->id, function (): void {
            $department = Department::create(['name' => 'SEO', 'code' => 'SEO']);

            // Through the service, so the employee has the login and the membership
            // a real one does — an attendance row hangs off a person, not a fixture.
            $employee = app(EmployeeService::class)->create([
                'first_name' => 'Rico',
                'last_name' => 'Alvarez',
                'email' => 'rico@agency.ph',
                'password' => 'RicoA2026!',
                'department_id' => $department->id,
                'job_title' => 'SEO Specialist',
                'employment_status' => 'regular',
                'hire_date' => '2026-01-05',
                'base_salary' => 30000,
            ]);

            $row = Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => '2026-08-31',
                'status' => 'present',
            ]);

            // The write: what actually reached the column, read raw so no cast can
            // tidy it up on the way back out.
            $this->assertSame(
                '2026-08-31',
                DB::table('hr_attendance')->where('id', $row->id)->value('work_date'),
                'A day stored as a day. `2026-08-31 00:00:00` here is the bug.',
            );

            // The read that the write has to serve, and the one every roster makes.
            $this->assertSame(1, Attendance::query()->where('work_date', '2026-08-31')->count());

            // And the inclusive range whose last day used to fall off the end.
            $this->assertSame(
                1,
                Attendance::query()->whereBetween('work_date', ['2026-08-25', '2026-08-31'])->count(),
                'The last day of an inclusive range is inside it.',
            );
        });
    }
}
