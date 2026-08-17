<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\OvertimeRequestService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Approved overtime is permission to pay work proved by the matching punch interval. */
class ApprovedOvertimePunchOverlapTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get()->update(['overtime_mode' => 'approved_only']);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-001',
            'job_title' => 'Staff',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    private function worked(string $timeIn, string $timeOut, string $date = '2026-08-03'): void
    {
        $tz = config('app.timezone_local');
        $clockIn = Carbon::parse("{$date} {$timeIn}", $tz);
        $clockOut = Carbon::parse("{$date} {$timeOut}", $tz);
        if ($clockOut->lt($clockIn)) {
            $clockOut->addDay();
        }

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $clockIn->utc(),
            'time_out' => $clockOut->utc(),
            'status' => 'present',
        ]);
    }

    private function approved(string $from, string $to, string $date = '2026-08-03'): void
    {
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_from' => $from,
            'time_to' => $to,
            'hours' => OvertimeRequestService::hoursBetween($from, $to),
            'reason' => 'Month-end close',
            'status' => 'approved',
        ]);
    }

    /** @return array{overtime_minutes:int, night_diff_minutes:int, night_diff_pay:float} */
    private function payrollResult(): array
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
        $payslip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();
        $result = [
            'overtime_minutes' => (int) $payslip->overtime_minutes,
            'night_diff_minutes' => (int) $payslip->night_diff_minutes,
            'night_diff_pay' => (float) $payslip->night_diff_pay,
        ];

        $run->payslips()->forceDelete();
        $run->forceDelete();

        return $result;
    }

    public function test_a_request_after_the_actual_clock_out_pays_no_overtime(): void
    {
        $this->worked('08:00', '23:00');
        $withoutRequest = $this->payrollResult();

        $this->approved('23:00', '01:00');
        $withNonOverlappingRequest = $this->payrollResult();

        $this->assertSame(0, $withNonOverlappingRequest['overtime_minutes']);
        $this->assertSame(60, $withNonOverlappingRequest['night_diff_minutes']);
        $this->assertSame($withoutRequest['night_diff_pay'], $withNonOverlappingRequest['night_diff_pay']);
    }

    public function test_only_the_part_of_a_request_inside_the_punch_window_is_paid(): void
    {
        $this->worked('08:00', '23:00');
        $withoutRequest = $this->payrollResult();

        $this->approved('21:00', '01:00');
        $result = $this->payrollResult();

        $this->assertSame(120, $result['overtime_minutes']);
        $this->assertSame(60, $result['night_diff_minutes']);
        $this->assertGreaterThan($withoutRequest['night_diff_pay'], $result['night_diff_pay']);
    }

    public function test_time_before_a_late_clock_in_is_not_paid_as_overtime(): void
    {
        $this->worked('18:00', '20:00');
        $this->approved('17:00', '20:00');

        $this->assertSame(120, $this->payrollResult()['overtime_minutes']);
    }

    public function test_an_overnight_request_aligns_to_the_next_day_shift_end(): void
    {
        WorkSchedule::firstOrFail()->update(['start_time' => '22:00', 'end_time' => '06:00']);
        $this->worked('22:00', '08:00');
        $this->approved('06:00', '08:00');

        $result = $this->payrollResult();

        $this->assertSame(120, $result['overtime_minutes']);
        $this->assertSame(480, $result['night_diff_minutes']);
    }

    public function test_a_legacy_hours_only_approval_keeps_its_capacity_limited_fallback(): void
    {
        $this->worked('08:00', '19:00');
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-03',
            'hours' => 3,
            'reason' => 'Legacy request',
            'status' => 'approved',
        ]);

        $this->assertSame(120, $this->payrollResult()['overtime_minutes']);
    }

    public function test_rest_day_approval_pays_only_the_punch_backed_overtime_tail(): void
    {
        $this->worked('08:00', '19:00', '2026-08-02'); // Sunday: 10 paid hours, final 2 are OT
        $this->approved('18:00', '20:00', '2026-08-02');

        $result = $this->payrollResult();

        $this->assertSame(60, $result['overtime_minutes']);
        $this->assertSame(0, $result['night_diff_minutes']);
    }
}
