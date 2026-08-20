<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\OvertimeRequestService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Decisions made after the period they affect was already paid. */
class RetroApprovalFlagTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

    private const WORK_DATE = '2026-03-04';

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
                'time_out' => Carbon::parse("{$d->toDateString()} 19:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function settleThePeriod(): void
    {
        $run = app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
        app(PayrollService::class)->markDone(
            Payslip::where('payroll_run_id', $run->id)->firstOrFail(),
        );
    }

    private function overtimeRequest(string $status = 'pending'): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => self::WORK_DATE,
            'hours' => 2,
            'reason' => 'Month-end close',
            'status' => $status,
        ]);
    }

    // --- approving into a settled period --------------------------------------------------

    public function test_approving_overtime_for_a_settled_period_records_a_difference(): void
    {
        $this->settleThePeriod();
        $request = $this->overtimeRequest();

        app(OvertimeRequestService::class)->approve($request, $this->hrUser());

        $flag = RetroFlag::where('flaggable_id', $request->id)->firstOrFail();

        $this->assertSame('approved', $request->fresh()->status, 'the approval must still go through');
        $this->assertSame(RetroFlag::OWED, $flag->direction);
        $this->assertStringContainsString('Mar 1–Mar 15, 2026', $flag->reason);
    }

    /** An open period needs no flag — re-running it absorbs the change, which is the normal path. */
    public function test_approving_overtime_for_an_open_period_records_nothing(): void
    {
        $request = $this->overtimeRequest();

        app(OvertimeRequestService::class)->approve($request, $this->hrUser());

        $this->assertSame(0, RetroFlag::count(), 'an open period must not create a difference');
    }

    // --- cancelling something already paid --------------------------------------------------

    public function test_cancelling_approved_overtime_that_was_paid_records_an_overpayment(): void
    {
        $request = $this->overtimeRequest('approved');
        $this->settleThePeriod();

        app(OvertimeRequestService::class)->cancel($request, $this->hrUser(), 'Never actually authorised');

        $flag = RetroFlag::where('flaggable_id', $request->id)->firstOrFail();

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(RetroFlag::OVERPAID, $flag->direction);
    }

    /** Cancelling a PENDING request paid nothing, so there is nothing to recover. */
    public function test_cancelling_a_pending_request_records_nothing(): void
    {
        $request = $this->overtimeRequest();
        $this->settleThePeriod();

        app(OvertimeRequestService::class)->cancel($request, $this->hrUser(), 'Withdrawn');

        $this->assertSame(0, RetroFlag::count());
    }

    // --- the queue ---------------------------------------------------------------------------

    public function test_outstanding_differences_are_listed_for_hr(): void
    {
        $this->settleThePeriod();
        app(OvertimeRequestService::class)->approve($this->overtimeRequest(), $this->hrUser());

        $this->actingAsHr()
            ->getJson('/api/v1/hr/retro-flags')
            ->assertOk()
            ->assertJsonPath('data.0.direction', RetroFlag::OWED)
            ->assertJsonPath('data.0.employee.name', $this->employee->user->name);
    }

    public function test_resolving_a_difference_requires_a_note_and_clears_it_from_the_queue(): void
    {
        $this->settleThePeriod();
        app(OvertimeRequestService::class)->approve($this->overtimeRequest(), $this->hrUser());
        $flag = RetroFlag::firstOrFail();

        $this->actingAsHr()
            ->postJson("/api/v1/hr/retro-flags/{$flag->id}/resolve", [])
            ->assertStatus(422);

        $this->actingAsHr()
            ->postJson("/api/v1/hr/retro-flags/{$flag->id}/resolve", ['note' => 'Paid on adjustment run 2026-04-01'])
            ->assertOk();

        $this->assertNotNull($flag->fresh()->resolved_at);
        $this->actingAsHr()->getJson('/api/v1/hr/retro-flags')->assertJsonCount(0, 'data');
    }

    /** Re-deciding the same request must leave one outstanding difference, not a pile. */
    public function test_repeated_decisions_on_one_request_keep_a_single_outstanding_flag(): void
    {
        $this->settleThePeriod();
        $request = $this->overtimeRequest();

        app(OvertimeRequestService::class)->approve($request, $this->hrUser());
        app(OvertimeRequestService::class)->cancel($request->fresh(), $this->hrUser(), 'Changed our mind');

        $this->assertSame(1, RetroFlag::whereNull('resolved_at')->count());
    }
}
