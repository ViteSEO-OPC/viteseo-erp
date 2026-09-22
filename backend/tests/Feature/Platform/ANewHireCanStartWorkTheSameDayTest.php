<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hiring someone gives them an HR account and nothing else.
 *
 * `EmployeeService::create()` assigns `hr_employee` -- self-service, eleven
 * permissions -- and stops. The department is recorded and never read by an
 * access check, so a new hire who is there to work on projects cannot open
 * Projects. Someone has to notice, go to a second screen in another module, and
 * grant it by hand; until they do, the person has a login and nowhere to go.
 *
 * The fix is the smaller half of what it looks like: the grant moves INTO the
 * form HR is already filling in, rather than becoming a per-department mapping
 * every tenant would first have to configure. One screen, no setup.
 *
 * Two rules hold it steady. A trainee's tier is not a choice -- `ojt`,
 * `part_time` and `project_based` may only ever hold the narrowest role their
 * module offers, whatever the form asks for. And the grant only ever ADDS:
 * taking access away stays in each module's own Team screen, where the admin
 * doing it can see what they are removing.
 */
class ANewHireCanStartWorkTheSameDayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{org: \App\Models\Org, admin: User, departmentId: int} */
    private function orgWith(array $systems): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', $systems,
        );

        $departmentId = CurrentOrg::runAs($result['org']->id, fn (): int => Department::create([
            'name' => 'Delivery', 'code' => 'DEL',
        ])->id);

        return ['org' => $result['org'], 'admin' => $result['admin'], 'departmentId' => $departmentId];
    }

    /** @return array<string, mixed> */
    private function hire(int $departmentId, array $extra = []): array
    {
        return [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@bituin.ph',
            'department_id' => $departmentId,
            'job_title' => 'Staff',
            'employment_status' => 'regular',
            'hire_date' => '2026-01-05',
            'base_salary' => 25000,
            ...$extra,
        ];
    }

    public function test_a_new_hire_can_be_given_projects_access_as_they_are_hired(): void
    {
        ['admin' => $admin, 'departmentId' => $dept] = $this->orgWith(['hr' => 'full', 'pms' => 'full']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/hr/employees', $this->hire($dept, [
            'grant_roles' => ['pm_member'],
        ]))->assertCreated();

        $juan = User::where('email', 'juan@bituin.ph')->firstOrFail();

        $this->assertTrue($juan->hasRole('pm_member'), 'They were hired to work on projects.');
        $this->assertTrue($juan->hasRole('hr_employee'), 'And they are still an employee.');
    }

    public function test_hiring_without_asking_for_anything_is_unchanged(): void
    {
        ['admin' => $admin, 'departmentId' => $dept] = $this->orgWith(['hr' => 'full', 'pms' => 'full']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/hr/employees', $this->hire($dept))->assertCreated();

        $juan = User::where('email', 'juan@bituin.ph')->firstOrFail();

        $this->assertSame(['hr_employee'], $juan->getRoleNames()->all());
    }

    public function test_a_trainee_cannot_be_handed_the_manager_tier(): void
    {
        ['admin' => $admin, 'departmentId' => $dept] = $this->orgWith(['hr' => 'full', 'pms' => 'full']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/hr/employees', $this->hire($dept, [
            'employment_status' => 'ojt',
            'grant_roles' => ['pm_apm'],
        ]))->assertStatus(422)->assertJsonValidationErrors('grant_roles.0');

        $this->assertDatabaseMissing('users', ['email' => 'juan@bituin.ph']);
    }

    public function test_but_a_trainee_can_hold_the_narrow_tier(): void
    {
        ['admin' => $admin, 'departmentId' => $dept] = $this->orgWith(['hr' => 'full', 'pms' => 'full']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/hr/employees', $this->hire($dept, [
            'employment_status' => 'ojt',
            'grant_roles' => ['pm_member'],
        ]))->assertCreated();

        $this->assertTrue(
            User::where('email', 'juan@bituin.ph')->firstOrFail()->hasRole('pm_member'),
            'An OJT still has to be able to see the work.',
        );
    }

    public function test_a_module_the_org_never_availed_cannot_be_granted(): void
    {
        // HR only — this client does not have Projects at all.
        ['admin' => $admin, 'departmentId' => $dept] = $this->orgWith(['hr' => 'full']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/hr/employees', $this->hire($dept, [
            'grant_roles' => ['pm_member'],
        ]))->assertStatus(422)->assertJsonValidationErrors('grant_roles.0');
    }

    public function test_the_hire_form_cannot_hand_out_a_role_it_does_not_own(): void
    {
        ['admin' => $admin, 'departmentId' => $dept] = $this->orgWith(['hr' => 'full', 'pms' => 'full']);
        Sanctum::actingAs($admin);

        // Not an oversight to guard: this endpoint is reachable by hr_officer, who
        // has no business minting a platform owner or another module's admin.
        foreach (['super_admin', 'hr_officer', 'acc_admin'] as $forbidden) {
            $this->postJson('/api/v1/hr/employees', $this->hire($dept, [
                'email' => "juan+{$forbidden}@bituin.ph",
                'grant_roles' => [$forbidden],
            ]))->assertStatus(422)->assertJsonValidationErrors('grant_roles.0');
        }
    }
}
