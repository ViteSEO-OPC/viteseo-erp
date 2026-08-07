<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * FIN-033 — the act that declares money paid had no preconditions and no provenance.
 *
 * `markDone()` is the only thing in the system that asserts a payslip's money left the company. It
 * checked nothing: not that the payslip was `computed`, not that its run had finished, not that it
 * was not already disbursed. It recorded no actor and no timestamp — "who marked this paid?" was
 * answerable only from the audit log, and "when?" only from `updated_at`, which the next write
 * overwrote. Nothing stopped a double-submit doing it twice.
 *
 * THE IDEMPOTENCY DISTINCTION IS THE INTERESTING PART, and both halves are asserted below. A repeat
 * carrying the SAME key is a duplicate request and is answered as success without acting twice; a
 * repeat with a DIFFERENT key is a genuine second attempt and is refused. Erroring on a double-click
 * teaches people to ignore the error, which is how the real refusal stops being read.
 */
class DisbursementGuardTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $tz = config('app.timezone_local');
        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte(Carbon::parse(self::CYCLE_END, $tz)); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runPayroll(): PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    private function slip(): Payslip
    {
        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    // --- provenance -----------------------------------------------------------------------

    public function test_disbursing_records_who_and_when(): void
    {
        $this->runPayroll();
        $hr = $this->hrUser();

        app(PayrollService::class)->markDone($this->slip(), $hr);

        $slip = $this->slip();
        $this->assertSame('disbursed', $slip->status);
        $this->assertSame($hr->id, $slip->disbursed_by, 'the actor was not recorded');
        $this->assertNotNull($slip->disbursed_at, 'the disbursement time was not recorded');
    }

    /** Reversing must clear the provenance, or a reversed payslip keeps crediting someone. */
    public function test_reversing_clears_the_provenance(): void
    {
        $this->runPayroll();
        $payroll = app(PayrollService::class);
        $payroll->markDone($this->slip(), $this->hrUser(), 'key-1');

        $payroll->reopenPayslip($this->slip(), 'recorded in error');

        $slip = $this->slip();
        $this->assertSame('computed', $slip->status);
        $this->assertNull($slip->disbursed_by);
        $this->assertNull($slip->disbursed_at);
        $this->assertNull($slip->disbursement_key, 'a stale key would no-op the next real disbursement');
    }

    // --- preconditions ---------------------------------------------------------------------

    public function test_a_payslip_cannot_be_disbursed_twice(): void
    {
        $this->runPayroll();
        $payroll = app(PayrollService::class);
        $payroll->markDone($this->slip(), $this->hrUser());

        $this->expectException(ValidationException::class);
        $payroll->markDone($this->slip(), $this->hrUser());
    }

    public function test_a_payslip_from_an_unfinished_run_cannot_be_disbursed(): void
    {
        $run = $this->runPayroll();
        $run->update(['status' => 'processing']);

        try {
            app(PayrollService::class)->markDone($this->slip(), $this->hrUser());
            $this->fail('a payslip from a processing run was declared paid');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'must finish',
                implode(' ', $e->validator->errors()->all()),
            );
        }
    }

    // --- idempotency ------------------------------------------------------------------------

    /** A double-click must not error — the second request is the same request. */
    public function test_the_same_idempotency_key_is_a_no_op_not_an_error(): void
    {
        $this->runPayroll();
        $payroll = app(PayrollService::class);

        $payroll->markDone($this->slip(), $this->hrUser(), 'submit-abc');
        $first = $this->slip()->disbursed_at;

        // No exception, and nothing changes.
        $payroll->markDone($this->slip(), $this->hrUser(), 'submit-abc');

        $this->assertSame('disbursed', $this->slip()->status);
        $this->assertEquals($first, $this->slip()->disbursed_at, 'the duplicate re-stamped the payslip');
    }

    /** A different key is a genuine second attempt, and must still be refused. */
    public function test_a_different_idempotency_key_is_still_refused(): void
    {
        $this->runPayroll();
        $payroll = app(PayrollService::class);
        $payroll->markDone($this->slip(), $this->hrUser(), 'submit-abc');

        $this->expectException(ValidationException::class);
        $payroll->markDone($this->slip(), $this->hrUser(), 'submit-xyz');
    }

    // --- over HTTP, which is how it actually happens ----------------------------------------

    public function test_the_endpoint_accepts_an_idempotency_key_header(): void
    {
        $this->runPayroll();
        $slip = $this->slip();

        $this->actingAsHr()
            ->withHeader('Idempotency-Key', 'ui-123')
            ->postJson("/api/v1/hr/payslips/{$slip->id}/mark-done")
            ->assertOk();

        // The retry a flaky connection produces — same key, so success rather than a 422.
        $this->actingAsHr()
            ->withHeader('Idempotency-Key', 'ui-123')
            ->postJson("/api/v1/hr/payslips/{$slip->id}/mark-done")
            ->assertOk();

        $this->assertSame('ui-123', $this->slip()->disbursement_key);
    }

    public function test_the_endpoint_refuses_a_second_disbursement(): void
    {
        $this->runPayroll();
        $slip = $this->slip();

        $this->actingAsHr()->postJson("/api/v1/hr/payslips/{$slip->id}/mark-done")->assertOk();
        $this->actingAsHr()->postJson("/api/v1/hr/payslips/{$slip->id}/mark-done")->assertStatus(422);
    }
}
