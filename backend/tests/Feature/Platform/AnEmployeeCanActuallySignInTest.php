<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Services\EmployeeService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * There are two doors into an org's staff list, and only one of them worked.
 *
 * The Team screen attaches org membership as it creates the account. People —
 * the door an HR officer actually uses to hire someone — created the user, the
 * hr_employees row and the leave credits, and no membership at all.
 *
 * That was not a lesser account. The login audit row carries `org_id NOT NULL`,
 * resolved from membership, so the very first sign-in attempt died on a
 * constraint violation: every employee hired through People was locked out of
 * the system they had just been hired into, and the failure surfaced as a 500
 * at the login screen rather than anywhere near the cause.
 */
class AnEmployeeCanActuallySignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_hired_through_people_can_sign_in(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['hr' => 'full'],
        );
        $org = $result['org'];

        $departmentId = CurrentOrg::runAs($org->id, fn (): int => Department::create([
            'name' => 'Operations', 'code' => 'OPS',
        ])->id);

        Sanctum::actingAs($result['admin']);

        $this->postJson('/api/v1/hr/employees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@bituin.ph',
            'department_id' => $departmentId,
            'job_title' => 'Staff',
            'employment_status' => 'regular',
            'hire_date' => '2026-01-05',
            'base_salary' => 25000,
        ])->assertCreated();

        $juan = User::where('email', 'juan@bituin.ph')->firstOrFail();

        // Membership is the thing that was missing, and it must name THIS org.
        $this->assertSame(
            $org->id,
            DB::table('org_user')->where('user_id', $juan->id)->value('org_id'),
            'A new hire belongs to the org that hired them.',
        );

    }

    public function test_and_the_sign_in_that_used_to_die_on_a_constraint(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['hr' => 'full'],
        )['org'];

        // Straight through the service, with no acting-as: swapping the default
        // guard for Sanctum's would leave Auth::attempt() unavailable, and the
        // login attempt IS what this test exists to make.
        CurrentOrg::runAs($org->id, function () {
            $department = Department::create(['name' => 'Operations', 'code' => 'OPS']);

            app(EmployeeService::class)->create([
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'email' => 'juan@bituin.ph',
                'password' => 'JuanDC2026!',
                'department_id' => $department->id,
                'job_title' => 'Staff',
                'employment_status' => 'regular',
                'hire_date' => '2026-01-05',
                'base_salary' => 25000,
            ]);
        });

        // The suite runs in ONE process, so the pin runAs() left behind would still
        // be standing when the login request arrives and would resolve the org for
        // it -- masking the very failure this test exists to catch. A real login
        // arrives in a fresh process with nothing pinned. Clear it, or this test
        // passes whether the fix is present or not.
        CurrentOrg::clear();

        // Before the fix this was a 500: the login audit row carries org_id NOT
        // NULL and there was no membership to resolve it from.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'juan@bituin.ph',
            'password' => 'JuanDC2026!',
        ])->assertOk()->assertJsonPath('data.user.email', 'juan@bituin.ph');
    }

    public function test_the_same_held_for_an_account_made_in_zerem(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        $this->postJson('/api/v1/crm/users', [
            'name' => 'Ben Santos',
            'email' => 'ben@bituin.ph',
            'role' => 'crm_bd',
            'password' => 'BenS2026!',
        ])->assertCreated();

        $ben = User::where('email', 'ben@bituin.ph')->firstOrFail();

        $this->assertSame(
            $result['org']->id,
            DB::table('org_user')->where('user_id', $ben->id)->value('org_id'),
            'Zerem\'s Create Account is the third door into an org and was the last one still open.',
        );
    }
}
