<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whoever asks "why can't Juan sign in any more?" must find the answer in the audit trail.
 *
 * The separation entry records that employment ended; it never said the account closed, and
 * for a resignation the account closes days later, from the scheduler, with nobody's name on
 * it. So the closing -- and the reopening -- is its own entry, written where it happens.
 */
class ClosingAnAccountLeavesATrailTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{org: \App\Models\Org, employee: Employee} */
    private function orgWithOneEmployee(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['hr' => 'full'],
        )['org'];

        $employee = CurrentOrg::runAs($org->id, fn (): Employee => app(EmployeeService::class)->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@bituin.ph',
            'password' => 'JuanDC2026!',
            'department_id' => Department::create(['name' => 'Operations', 'code' => 'OPS'])->id,
            'job_title' => 'Staff',
            'employment_status' => 'regular',
            'hire_date' => '2026-01-05',
            'base_salary' => 25000,
        ]));

        return ['org' => $org, 'employee' => $employee];
    }

    /** @return \Illuminate\Support\Collection<int, AuditLog> */
    private function accountEntries(int $orgId, Employee $employee)
    {
        return CurrentOrg::runAs($orgId, fn () => AuditLog::query()
            ->where('auditable_type', Employee::class)
            ->where('auditable_id', $employee->id)
            ->where('description', 'like', "%Juan Dela Cruz's account%")
            ->orderBy('id')
            ->get());
    }

    private function separate(int $orgId, Employee $employee, string $type, string $lastDay): void
    {
        CurrentOrg::runAs($orgId, fn () => app(EmployeeService::class)->separate($employee, [
            'separation_type' => $type,
            'separation_date' => $lastDay,
        ]));
    }

    public function test_a_termination_records_that_the_account_closed(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        $this->separate($org->id, $employee, 'involuntary', now()->toDateString());

        $entries = $this->accountEntries($org->id, $employee);
        $this->assertCount(1, $entries);
        $this->assertSame('updated', $entries[0]->action);
        $this->assertStringStartsWith("Closed Juan Dela Cruz's account", $entries[0]->description);
    }

    public function test_a_resignation_records_the_closing_on_the_day_the_sweep_closes_it(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();
        $lastDay = now()->addDays(14)->toDateString();

        $this->separate($org->id, $employee, 'voluntary', $lastDay);
        $this->assertCount(0, $this->accountEntries($org->id, $employee), 'still open while the notice is worked');

        $this->travelTo(now()->addDays(15));
        $this->artisan('hr:apply-separations')->assertSuccessful();

        $entries = $this->accountEntries($org->id, $employee);
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]->user_id, 'nobody closed it -- the scheduler did');
        $this->assertStringContainsString($lastDay, $entries[0]->description);
        $this->assertSame(User::STATUS_INACTIVE, $employee->user->fresh()->status);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        $this->separate($org->id, $employee, 'voluntary', now()->toDateString());
        $this->artisan('hr:apply-separations', ['--dry-run' => true])->assertSuccessful();

        $this->assertCount(0, $this->accountEntries($org->id, $employee));
    }

    public function test_a_second_sweep_does_not_write_it_twice(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();

        $this->separate($org->id, $employee, 'voluntary', now()->toDateString());
        $this->artisan('hr:apply-separations')->assertSuccessful();
        $this->artisan('hr:apply-separations')->assertSuccessful();

        $this->assertCount(1, $this->accountEntries($org->id, $employee));
    }

    public function test_reinstating_records_that_the_account_reopened(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();
        $this->separate($org->id, $employee, 'involuntary', now()->toDateString());

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)
            ->reinstate(Employee::withTrashed()->findOrFail($employee->id)));

        $entries = $this->accountEntries($org->id, $employee);
        $this->assertCount(2, $entries);
        $this->assertSame('reopened', $entries[1]->action);
        $this->assertStringStartsWith("Reopened Juan Dela Cruz's account", $entries[1]->description);
    }

    public function test_rehiring_records_that_the_account_reopened(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();
        $this->separate($org->id, $employee, 'involuntary', now()->subDays(30)->toDateString());

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)
            ->rehire(Employee::withTrashed()->findOrFail($employee->id), now()->toDateString()));

        $entries = $this->accountEntries($org->id, $employee);
        $this->assertSame('reopened', $entries->last()->action);
        $this->assertStringStartsWith("Reopened Juan Dela Cruz's account", $entries->last()->description);
    }

    public function test_reinstating_someone_whose_account_never_closed_writes_no_reopening(): void
    {
        ['org' => $org, 'employee' => $employee] = $this->orgWithOneEmployee();
        // A resignation still inside its notice: the account is open, so there is nothing to reopen.
        $this->separate($org->id, $employee, 'voluntary', now()->addDays(14)->toDateString());

        CurrentOrg::runAs($org->id, fn () => app(EmployeeService::class)
            ->reinstate(Employee::withTrashed()->findOrFail($employee->id)));

        $this->assertCount(0, $this->accountEntries($org->id, $employee));
    }
}
