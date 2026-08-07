<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * FIN-020 — maker–checker on the payroll run: separation of duties.
 *
 * One role could edit a salary, run payroll, mark it disbursed, lock the run and unlock it again with no
 * second party involved anywhere. That is the control finance auditors look for first, and the audit says
 * so: a single compromised HR account could raise a pay rate, pay it, and seal the period.
 *
 * ONLY THE ENFORCEMENT HALF IS HERE. A dedicated finance role and distinct `hr.payroll.*` permissions
 * live in `RolesAndPermissionsSeeder`, a shared CTO-owned file, and remain open.
 *
 * THE PROVENANCE WAS ALREADY THERE — `run_by`, `disbursed_by` and `locked_by` were all recorded and
 * nothing acted on them. So the trail needed to verify separation of duties after the fact existed; what
 * was missing was anything enforcing it.
 *
 * The setting defaults OFF, which `test_the_default_changes_nothing` guards. Forcing it on would leave a
 * one-officer company unable to disburse until somebody found the switch — payroll blocked, on a payday.
 * Unlike the overtime caps, it cannot silently move a figure in either direction.
 */
class MakerCheckerTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-12-20 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => 45000, 'pay_frequency' => 'monthly', 'is_management' => false,
            'tin' => '123-456-789-000', 'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2', 'pagibig_number' => '1234-5678-9012',
            'date_of_birth' => '1995-03-14', 'address' => '12 Mabini St, Makati',
        ]);
    }


    /** Attendance for a month, then that month's run. */
    private function payMonth(int $month): PayrollRun
    {
        $tz = config('app.timezone_local');
        $start = Carbon::create(2026, $month, 1, 0, 0, 0, $tz);

        for ($day = $start->copy(); $day->month === $month; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            Attendance::firstOrCreate(
                ['employee_id' => $this->employee->id, 'work_date' => $day->toDateString()],
                [
                    'time_in' => $day->copy()->setTime(8, 0)->utc(),
                    'time_out' => $day->copy()->setTime(17, 0)->utc(),
                    'status' => 'present',
                ],
            );
        }

        return app(PayrollService::class)->run(
            $start->toDateString(),
            $start->copy()->endOfMonth()->toDateString(),
            'monthly',
            $this->hrUser(),
        );
    }


    private function setting(bool $on): void
    {
        PayrollSetting::current()->update(['require_maker_checker' => $on]);
    }

    /** THE GUARD ON THE MIGRATION: off by default, so no existing install is blocked on a payday. */
    public function test_the_default_changes_nothing(): void
    {
        $this->assertFalse((bool) PayrollSetting::current()->require_maker_checker);

        $runner = $this->hrUser();
        $run = app(PayrollService::class)->run('2026-01-01', '2026-01-31', 'monthly', $runner);

        // The same person runs and disburses — allowed, exactly as before.
        $slip = app(PayrollService::class)->markDone($run->payslips()->firstOrFail(), $runner);

        $this->assertSame('disbursed', $slip->status);
    }

    /**
     * THE FINDING'S OWN VALIDATION: "Assert the user who ran a cycle cannot mark its payslips
     * disbursed."
     */
    public function test_the_runner_cannot_disburse_their_own_run(): void
    {
        $this->setting(true);
        $runner = $this->hrUser();
        $run = app(PayrollService::class)->run('2026-01-01', '2026-01-31', 'monthly', $runner);

        try {
            app(PayrollService::class)->markDone($run->payslips()->firstOrFail(), $runner);
            $this->fail('the runner should not be able to disburse their own run');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Someone else', $e->validator->errors()->first('status'));
        }
    }

    public function test_a_second_person_can_disburse_it(): void
    {
        $this->setting(true);
        $run = app(PayrollService::class)->run('2026-01-01', '2026-01-31', 'monthly', $this->hrUser());

        $slip = app(PayrollService::class)->markDone($run->payslips()->firstOrFail(), $this->hrUser());

        $this->assertSame('disbursed', $slip->status);
    }

    /** Unlocking is the most consequential undo here — it makes a sealed cycle recomputable again. */
    public function test_the_locker_cannot_unlock_their_own_run(): void
    {
        $this->setting(true);
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-01-01', '2026-01-31', 'monthly', $this->hrUser());

        $locker = $this->hrUser();
        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }
        $payroll->lock($run->fresh(), $locker);

        $this->expectException(ValidationException::class);
        $payroll->unlock($run->fresh(), $locker);
    }

    public function test_a_second_person_can_unlock_it(): void
    {
        $this->setting(true);
        $payroll = app(PayrollService::class);
        $run = $payroll->run('2026-01-01', '2026-01-31', 'monthly', $this->hrUser());

        foreach ($run->payslips as $slip) {
            $payroll->markDone($slip, $this->hrUser());
        }
        $payroll->lock($run->fresh(), $this->hrUser());

        $unlocked = $payroll->unlock($run->fresh(), $this->hrUser());

        $this->assertNull($unlocked->locked_at);
    }

    /**
     * A run from before the provenance columns existed has no recorded runner. The control stays silent
     * rather than blocking legitimate work on old data — separation of duties, not a puzzle about nulls.
     */
    public function test_a_run_with_no_recorded_runner_is_not_blocked(): void
    {
        $this->setting(true);
        $run = app(PayrollService::class)->run('2026-01-01', '2026-01-31', 'monthly', $this->hrUser());
        $run->update(['run_by' => null]);

        $slip = app(PayrollService::class)->markDone($run->payslips()->firstOrFail(), $this->hrUser());

        $this->assertSame('disbursed', $slip->status);
    }

    public function test_hr_can_turn_the_control_on_over_http(): void
    {
        $this->actingAs($this->hrUser())
            // The endpoint requires the whole settings shape, not a partial patch, so the existing
            // values are echoed back alongside the one being changed.
            ->putJson('/api/v1/hr/payroll/settings', [
                'frequency' => PayrollSetting::current()->frequency,
                'require_maker_checker' => true,
            ])
            ->assertOk();

        $this->assertTrue((bool) PayrollSetting::current()->fresh()->require_maker_checker);
    }
}
