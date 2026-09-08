<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use App\Support\Seats;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Separating an employee ends their employment and nothing else.
 *
 * `EmployeeService::separate()` soft-deletes the `hr_employees` row. That is the
 * whole operation. The `users` row it hangs off is not touched, so the account
 * keeps its password, its org membership, every module role it was ever granted,
 * and any token already in a browser. The person disappears from the HR lists
 * and from nowhere else: they can still sign in, still open Projects, still own
 * CRM leads, and still occupy a paid seat.
 *
 * Nothing announces it. The separation succeeds, the attrition report is right,
 * and the account stays live behind it -- which is the shape of the failure that
 * is hardest to notice, because every screen an HR officer looks at agrees the
 * person is gone.
 *
 * These tests describe the intended behaviour, so before the fix they FAIL:
 * the sign-in returns 200 rather than being refused.
 */
class ASeparatedEmployeeCannotSignInTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'JuanDC2026!';

    /**
     * An org with HR availed, plus one employee hired through the service.
     *
     * @return array{org: \App\Models\Org, admin: User, employee: Employee}
     */
    private function orgWithOneEmployee(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['hr' => 'full'],
        );
        $org = $result['org'];

        $employee = CurrentOrg::runAs($org->id, function (): Employee {
            $department = Department::create(['name' => 'Operations', 'code' => 'OPS']);

            return app(EmployeeService::class)->create([
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'email' => 'juan@bituin.ph',
                'password' => self::PASSWORD,
                'department_id' => $department->id,
                'job_title' => 'Staff',
                'employment_status' => 'regular',
                'hire_date' => '2026-01-05',
                'base_salary' => 25000,
            ]);
        });

        return ['org' => $org, 'admin' => $result['admin'], 'employee' => $employee];
    }

    /**
     * The suite runs in one process and `runAs()` leaves its pin standing, which
     * would resolve the org for a login request that must resolve it on its own.
     */
    private function signIn(): \Illuminate\Testing\TestResponse
    {
        CurrentOrg::clear();

        return $this->postJson('/api/v1/auth/login', [
            'email' => 'juan@bituin.ph',
            'password' => self::PASSWORD,
        ]);
    }

    public function test_a_separated_employee_cannot_sign_in(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        // They could sign in the day before, and that is the point: nothing about
        // the account changes here except that the person no longer works here.
        $this->signIn()->assertOk();

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'involuntary',
            'separation_date' => now()->toDateString(),
        ]));

        $response = $this->signIn();

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'no longer active',
            (string) $response->json('message'),
            'The message must say the account is closed, not that the password is wrong — '
                . 'a generic refusal sends the person and the HR officer chasing a password reset.',
        );
    }

    public function test_a_token_they_already_hold_stops_working_too(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        $juan = User::where('email', 'juan@bituin.ph')->firstOrFail();

        // Refusing the login door is not enough on its own. Someone separated at
        // 4pm still has this morning's token in an open browser tab, and Sanctum
        // tokens do not expire for 30 days.
        Sanctum::actingAs($juan);
        $this->getJson('/api/v1/auth/me')->assertOk();

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'involuntary',
            'separation_date' => now()->toDateString(),
        ]));

        Sanctum::actingAs($juan->fresh());
        $this->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_the_seat_they_held_is_released(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        $before = Seats::used($org->id);

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'voluntary',
            'separation_date' => now()->toDateString(),
        ]));

        // The org_user row deliberately stays — rehire needs it, and OrgUserRule
        // validates historical references against it. Only the COUNT changes.
        $this->assertSame(
            $before - 1,
            Seats::used($org->id),
            'A seat is a person who can sign in. Someone who has left is neither.',
        );
    }

    public function test_a_voluntary_separation_takes_effect_on_the_last_day_not_before(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        // HR files the resignation two weeks out. The person is still working it.
        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'voluntary',
            'separation_date' => now()->addDays(14)->toDateString(),
        ]));

        $this->signIn()->assertOk();

        // On the day, the scheduled sweep closes the account.
        $this->travelTo(now()->addDays(15));
        $this->artisan('hr:apply-separations')->assertSuccessful();

        $this->signIn()->assertStatus(403);
    }

    public function test_reinstating_them_restores_access(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'involuntary',
            'separation_date' => now()->toDateString(),
        ]));

        $this->signIn()->assertStatus(403);

        // Reinstatement's whole claim is that the ending never happened. Because
        // nothing was removed, there is nothing to put back: lifting the block is
        // the entire operation.
        CurrentOrg::runAs(
            $org->id,
            fn () => app(EmployeeService::class)->reinstate(Employee::withTrashed()->findOrFail($employee->id)),
        );

        $this->signIn()->assertOk();
    }
}
