<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** Attendance is the source of truth, and HR has the final say over it. */
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

    private function leave(bool $paid, string $date, float $days = 1, bool $halfDay = false): LeaveRequest
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
            'is_half_day' => $halfDay,
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

    /**
     * A HALF DAY IS NOT EVIDENCE. The employee takes half off and is expected to work the
     * other half, so clocking in is the normal course of that day — not proof the leave went
     * unused. Refunding it would return the credit of every half day ever filed, and return
     * 1.0 against a 0.5 deduction while doing it.
     */
    public function test_half_day_leave_keeps_its_credit_when_the_day_is_worked(): void
    {
        $this->leave(paid: true, date: $this->today, days: 0.5, halfDay: true);
        $this->assertSame(0.5, $this->usedDays());

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '13:00', 'time_out' => '17:00']);

        $this->assertSame(0.5, $this->usedDays(), 'the half day was always meant to be worked');
    }

    /**
     * A non-working holiday inside a leave range never consumed a credit (LeaveService::
     * computeDays skips it), so working it cannot give one back.
     */
    public function test_a_holiday_inside_the_range_refunds_nothing(): void
    {
        $leave = $this->leave(paid: true, date: $this->today);
        $set = HolidaySet::firstOrCreate(
            ['country_code' => 'PH', 'year' => (int) Carbon::parse($this->today)->format('Y')],
            ['source' => 'manual'],
        );
        Holiday::create([
            'holiday_set_id' => $set->id, 'name' => 'Test', 'date' => $this->today,
            'type' => 'regular', 'source' => 'manual',
        ]);

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);

        $this->assertSame(1.0, $this->usedDays(), 'nothing was spent on a holiday, so nothing returns');
        $this->assertNotNull($leave->fresh());
    }

    // --- 1b. the employee's own clock-in settles it too ------------------------------

    /** The whole point: no HR action required. */
    public function test_a_self_clock_in_on_paid_leave_returns_the_credit(): void
    {
        $today = $this->travelToAWorkdayMorning();
        $this->leave(paid: true, date: $today);
        $this->assertSame(1.0, $this->usedDays());

        $this->svc()->clockIn($this->employee);

        $this->assertSame(0.0, $this->usedDays(), 'the credit goes back without HR touching the row');
        $this->assertSame(0.0, $this->unpaid($today), 'and the day is paid');
    }

    public function test_a_self_clock_in_on_half_day_leave_keeps_the_credit(): void
    {
        $today = $this->travelToAWorkdayMorning();
        $this->leave(paid: true, date: $today, days: 0.5, halfDay: true);

        $this->svc()->clockIn($this->employee);

        $this->assertSame(0.5, $this->usedDays());
    }

    /** Unpaid leave holds no credit, so a clock-in has nothing to give back. */
    public function test_a_self_clock_in_on_unpaid_leave_stamps_nothing(): void
    {
        $today = $this->travelToAWorkdayMorning();
        $this->leave(paid: false, date: $today);

        $att = $this->svc()->clockIn($this->employee);

        $this->assertNull($att->fresh()->leave_credit_refunded_at);
        $this->assertSame(0.0, $this->unpaid($today), 'LWOP that was worked is still paid');
    }

    /** Freeze the clock on a workday morning and return that Manila date. */
    private function travelToAWorkdayMorning(): string
    {
        $tz = config('app.timezone_local');
        $d = Carbon::now($tz)->startOfMonth()->addDays(6)->setTime(8, 0);
        while ($d->isWeekend()) {
            $d->addDay();
        }
        Carbon::setTestNow($d);

        return $d->toDateString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- 1c. approval is the other order the two facts arrive in ---------------------

    /** A pending request plus an EMPTY balance, so approve() does the deducting. */
    private function pendingLeave(string $start, string $end, float $days = 1, bool $unlimited = false): LeaveRequest
    {
        $type = LeaveType::create([
            'name' => 'Annual', 'code' => 'AL', 'is_paid' => true,
            'default_days' => 10, 'is_unlimited' => $unlimited,
        ]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => (int) Carbon::parse($start)->format('Y'),
            'entitled_days' => 10, 'used_days' => 0,
        ]);

        return LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => $start, 'end_date' => $end, 'days' => $days,
            'reason' => 'x', 'status' => 'pending',
        ]);
    }

    private function leaves(): LeaveService
    {
        return app(LeaveService::class);
    }

    /** The day was already worked when the leave was approved over it. */
    public function test_approving_leave_over_a_worked_day_spends_no_credit(): void
    {
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);
        $leave = $this->pendingLeave($this->today, $this->today);

        $this->leaves()->approve($leave, User::factory()->create());

        $this->assertSame(0.0, $this->usedDays(), 'a day payroll pays as worked cannot also cost a credit');
        $this->assertSame(0.0, $this->unpaid($this->today));
    }

    /** Only the day that was actually worked comes back — the rest of the range still costs. */
    public function test_only_the_worked_day_of_an_approved_range_is_refunded(): void
    {
        $tz = config('app.timezone_local');
        $start = Carbon::parse($this->today, $tz)->subDay();
        while ($start->isWeekend()) {
            $start->subDay();
        }

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);
        $leave = $this->pendingLeave($start->toDateString(), $this->today, days: 2);

        $this->leaves()->approve($leave, User::factory()->create());

        $this->assertSame(1.0, $this->usedDays(), 'the unworked day still spends its credit');
    }

    /** Reversing the approval gives back what the balance is STILL holding, not the full request. */
    public function test_reopening_after_a_refund_restores_only_what_is_outstanding(): void
    {
        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);
        $leave = $this->pendingLeave($this->today, $this->today);
        $hr = User::factory()->create();

        $this->leaves()->approve($leave, $hr);
        $this->assertSame(0.0, $this->usedDays());

        $this->leaves()->reopen($leave->fresh());

        $this->assertSame(0.0, $this->usedDays(), 'nothing was outstanding, so nothing comes back');
        $this->assertNull(
            Attendance::where('employee_id', $this->employee->id)->first()->leave_credit_refunded_at,
            'the stamp stops describing anything once the request is pending again',
        );

        // And the round trip lands exactly where it started, rather than charging the worked
        // day because a stale stamp refused to refund twice.
        $this->leaves()->approve($leave->fresh(), $hr);
        $this->assertSame(0.0, $this->usedDays());
    }

    /**
     * An unlimited type draws no credits at all, so there is no balance behind it to give back
     * — the refund must not fall through to the per-type bucket and decrement someone's
     * unrelated allowance.
     */
    public function test_an_unlimited_type_refunds_nothing(): void
    {
        $leave = $this->pendingLeave($this->today, $this->today, unlimited: true);
        $this->leaves()->approve($leave, User::factory()->create());
        // Credits the employee spent on OTHER leave that year — the balance the fall-through
        // bucket would have raided.
        LeaveBalance::where('employee_id', $this->employee->id)->update(['used_days' => 3]);

        $this->svc()->adjust($this->employee, $this->today, ['time_in' => '08:00', 'time_out' => '17:00']);

        $this->assertSame(3.0, $this->usedDays(), 'an unrelated balance is left alone');
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
