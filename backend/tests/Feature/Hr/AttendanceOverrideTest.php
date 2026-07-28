<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Slice 16 — attendance is the source of truth, and HR has the final say over it.
 *
 * Three things were wrong:
 *   1. Approved leave beat the clock. Someone on UNPAID leave who came in anyway was docked a
 *      full day for a day they worked, and HR marking them present changed nothing.
 *   2. Paid leave kept the credit even when the day was worked, and HR could not give it back
 *      (an approved leave can only be cancelled before it starts).
 *   3. Violations only recomputed the CURRENT month, so correcting a June record left June's
 *      flags exactly as they were — a late added there raised nothing, a late removed there
 *      stayed flagged forever.
 */
class AttendanceOverrideTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** A past weekday in the current month, so the default recompute window covers it. */
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = config('app.timezone_local');
        $d = Carbon::now($tz)->startOfMonth()->addDays(6);
        while ($d->isWeekend() || $d->gte(Carbon::now($tz)->startOfDay())) {
            $d->subDay();
        }
        $this->today = $d->toDateString();

        $schedule = WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'work_schedule_id' => $schedule->id,
        ]);
    }

    private function svc(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function unpaid(string $date): float
    {
        return $this->svc()->unpaidDaysFor($this->employee->fresh(), $date, $date);
    }

    private function leave(bool $paid, string $date, float $days = 1): LeaveRequest
    {
        $type = LeaveType::create([
            'name' => $paid ? 'Annual' : 'LWOP', 'code' => $paid ? 'AL' : 'LWOP',
            'is_paid' => $paid, 'default_days' => 10,
        ]);
        if ($paid) {
            LeaveBalance::create([
                'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
                'year' => (int) Carbon::parse($date)->format('Y'),
                'entitled_days' => 10, 'used_days' => $days,
            ]);
        }

        return LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => $date, 'end_date' => $date, 'days' => $days,
            'reason' => 'x', 'status' => 'approved', 'deducted_from' => $paid ? 'per_type' : 'none',
        ]);
    }

    private function usedDays(): float
    {
        return (float) LeaveBalance::where('employee_id', $this->employee->id)->value('used_days');
    }

    // --- 1. a clock-in beats leave --------------------------------------------------

    public function test_unpaid_leave_but_came_to_work_is_paid(): void
    {
        $this->leave(paid: false, date: $this->today);
        $this->assertSame(1.0, $this->unpaid($this->today), 'no-show on LWOP is still docked');

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);

        $this->assertSame(0.0, $this->unpaid($this->today));
    }

    public function test_paid_leave_but_came_to_work_returns_the_credit(): void
    {
        $this->leave(paid: true, date: $this->today);
        $this->assertSame(1.0, $this->usedDays());

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);

        $this->assertSame(0.0, $this->usedDays(), 'the credit goes back');
        $this->assertSame(0.0, $this->unpaid($this->today), 'and the day is still paid');
    }

    /** Editing twice must not refund twice. */
    public function test_the_credit_refund_is_idempotent(): void
    {
        $this->leave(paid: true, date: $this->today);

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '09:00', 'time_out' => '18:00']);

        $this->assertSame(0.0, $this->usedDays());
    }

    /** Undoing the correction puts the credit back on the balance. */
    public function test_clearing_the_attendance_re_takes_the_credit(): void
    {
        $this->leave(paid: true, date: $this->today);

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);
        $this->assertSame(0.0, $this->usedDays());

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => null]);

        $this->assertSame(1.0, $this->usedDays());
    }

    // --- 2. HR's status wins --------------------------------------------------------

    public function test_hr_can_mark_a_late_clock_in_as_present(): void
    {
        $row = $this->svc()->adjust($this->employee, $this->today, [
            'time_in' => '10:30', 'time_out' => '17:00',
        ]);
        $this->assertSame('late', $row->status, 'derived from the times when HR says nothing');

        $row = $this->svc()->adjust($this->employee, $this->today, [
            'time_in' => '10:30', 'time_out' => '17:00', 'status' => 'present',
        ]);

        $this->assertSame('present', $row->status);
        $this->assertTrue((bool) $row->status_override);
    }

    public function test_hr_can_mark_a_day_as_a_rest_day(): void
    {
        $this->assertSame(1.0, $this->unpaid($this->today));

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => null, 'status' => 'rest_day']);

        $this->assertSame(0.0, $this->unpaid($this->today));
    }

    public function test_hr_marking_half_day_costs_half(): void
    {
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'status' => 'half_day']);

        $this->assertSame(0.5, $this->unpaid($this->today));
    }

    public function test_hr_marking_absent_costs_a_full_day(): void
    {
        $this->svc()->adjust($this->employee, $this->today, [
            'time_in' => '08:00', 'time_out' => '17:00', 'status' => 'absent',
        ]);

        $this->assertSame(1.0, $this->unpaid($this->today), 'HR wins even over a clock-in');
    }

    /** The roster shows what HR asserted, not what the clock implies. */
    public function test_the_roster_shows_the_override(): void
    {
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => null, 'status' => 'on_leave']);

        $row = collect($this->svc()->allForDate($this->today))->firstWhere('employee_id', $this->employee->id);

        $this->assertSame('on_leave', $row['status']);
    }

    public function test_an_unknown_status_is_ignored_not_stored(): void
    {
        $row = $this->svc()->adjust($this->employee, $this->today, [
            'time_in' => '08:00', 'time_out' => '17:00', 'status' => 'nonsense',
        ]);

        $this->assertSame('present', $row->status);
        $this->assertFalse((bool) $row->status_override);
    }

    // --- 3. violations follow attendance -------------------------------------------

    private function flags(string $date): string
    {
        return Violation::where('employee_id', $this->employee->id)
            ->whereDate('work_date', $date)->pluck('type')->implode(',');
    }

    public function test_a_late_is_flagged_and_clears_when_corrected(): void
    {
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '10:30', 'time_out' => '17:00']);
        $this->assertSame('late', $this->flags($this->today));

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);
        $this->assertSame('', $this->flags($this->today));
    }

    /** THE MONTH BUG: correcting a past month used to leave its flags untouched. */
    public function test_a_past_month_correction_updates_that_months_flags(): void
    {
        $tz = config('app.timezone_local');
        $past = Carbon::now($tz)->subMonth()->startOfMonth()->addDays(6);
        while ($past->isWeekend()) {
            $past->addDay();
        }
        $pastDate = $past->toDateString();

        $this->svc()->adjust($this->employee, $pastDate, ['time_in' => '10:30', 'time_out' => '17:00']);
        $this->assertSame('late', $this->flags($pastDate), 'a late in a past month must flag');

        $this->svc()->adjust($this->employee, $pastDate, ['time_in' => '08:00', 'time_out' => '17:00']);
        $this->assertSame('', $this->flags($pastDate), 'and clear when corrected');
    }

    /** An HR override to a non-working status raises no flag. */
    public function test_marking_a_day_on_leave_clears_its_absence_flag(): void
    {
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => null]);
        $this->assertStringContainsString('ua', $this->flags($this->today));

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => null, 'status' => 'on_leave']);

        $this->assertSame('', $this->flags($this->today));
    }

    public function test_the_endpoint_accepts_a_status(): void
    {
        $this->hrPost('/api/v1/hr/attendance/adjust', [
            'employee_id' => $this->employee->id,
            'work_date' => $this->today,
            'time_in' => '10:30',
            'time_out' => '17:00',
            'status' => 'present',
        ])->assertCreated();

        $this->assertSame('present', Attendance::firstOrFail()->status);
    }

    public function test_the_endpoint_rejects_an_invalid_status(): void
    {
        $this->hrPost('/api/v1/hr/attendance/adjust', [
            'employee_id' => $this->employee->id,
            'work_date' => $this->today,
            'status' => 'vacationing',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
