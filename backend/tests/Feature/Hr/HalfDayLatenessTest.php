<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EarlyOutRequestService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * An approved half day moves the shift, so lateness must be judged against the half actually
 * worked.
 *
 * THE BUG THIS PINS. Only DeductionService knew a half day shrinks the working window; the clock
 * measured every arrival against the full 08:00–17:00 shift. Somebody with the morning approved
 * off who walked in at 1:00 PM was stamped `late` by five hours — which raised a disciplinary
 * violation, inflated the "Late arrivals" tile, and, with the per-N-lates penalty switched on,
 * cost them A FULL DAY'S PAY for arriving exactly on time.
 *
 * The money test at the bottom is the one to read. The rest exist so the status, the badge and
 * the payslip cannot drift apart again — they are three renderings of one fact.
 */
class HalfDayLatenessTest extends HrTestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-24'; // a Monday

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00',
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function approveHalfDay(string $period, string $date = self::DATE): HalfDayRequest
    {
        return HalfDayRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'period' => $period, 'reason' => 'errand', 'status' => 'approved',
        ]);
    }

    /** An approved half day of PAID leave — the other funding model, same shape. */
    private function approveHalfDayLeave(string $period): LeaveRequest
    {
        $type = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'year' => 2026, 'entitled_days' => 10, 'used_days' => 0,
        ]);

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));
        $request = app(LeaveService::class)->create($this->employee, [
            'leave_type_id' => $type->id,
            'start_date' => self::DATE, 'end_date' => self::DATE,
            'reason' => 'morning off', 'is_half_day' => true, 'half_day_period' => $period,
        ]);

        return app(LeaveService::class)->approve($request, User::factory()->create());
    }

    private function clockInAt(string $time, string $date = self::DATE): Attendance
    {
        $this->travelTo(Carbon::parse("{$date} {$time}", config('app.timezone_local')));

        return app(AttendanceService::class)->clockIn($this->employee);
    }

    // --- the reported bug ------------------------------------------------------------------

    public function test_the_afternoon_half_starts_at_one_not_at_eight(): void
    {
        $this->approveHalfDay('AM');

        $this->assertSame('present', $this->clockInAt('13:00')->status);
    }

    public function test_the_same_holds_for_a_half_day_of_leave(): void
    {
        $this->approveHalfDayLeave('AM');

        $this->assertSame('present', $this->clockInAt('13:00')->status);
    }

    public function test_grace_still_applies_to_the_worked_half(): void
    {
        $this->approveHalfDay('AM');

        $this->assertSame('present', $this->clockInAt('13:15')->status);
    }

    public function test_genuinely_late_for_the_afternoon_is_still_late(): void
    {
        $this->approveHalfDay('AM');

        $this->assertSame('late', $this->clockInAt('13:16')->status);
    }

    public function test_a_morning_half_day_is_judged_against_the_morning(): void
    {
        $this->approveHalfDay('PM'); // afternoon off — they work 08:00–12:00

        $this->assertSame('late', $this->clockInAt('09:00')->status);
    }

    public function test_a_day_with_both_halves_off_has_no_shift_to_be_late_for(): void
    {
        $this->approveHalfDay('AM');
        $this->approveHalfDay('PM');

        $this->assertSame('present', $this->clockInAt('15:00')->status);
    }

    public function test_an_ordinary_day_is_untouched(): void
    {
        $this->assertSame('late', $this->clockInAt('09:00')->status);
    }

    // --- the same rule everywhere the status is re-derived -----------------------------------

    public function test_an_hr_correction_does_not_re_stamp_it_late(): void
    {
        $this->approveHalfDay('AM');

        $row = app(AttendanceService::class)->adjust($this->employee, self::DATE, [
            'time_in' => '13:05', 'time_out' => '17:00',
        ]);

        $this->assertSame('present', $row->status);
    }

    public function test_the_roster_badge_agrees_with_the_stored_status(): void
    {
        $this->approveHalfDay('AM');
        $this->clockInAt('13:00');

        $row = collect(app(AttendanceService::class)->allForDate(self::DATE))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertSame('present', $row['status']);
        $this->assertSame('on_time', $row['timing']['category']);
        $this->assertSame(0, $row['timing']['late_minutes']);
        // And the board says WHY the shift was short, so a 1:00 PM arrival isn't unexplained.
        $this->assertSame('AM', $row['half_day_off']);
    }

    public function test_the_late_arrivals_tile_does_not_count_it(): void
    {
        $this->approveHalfDay('AM');
        $this->clockInAt('13:00');

        $summary = app(AttendanceService::class)->summary(self::DATE);

        $this->assertSame(0, $summary['late_arrivals']);
        $this->assertSame(1, $summary['on_time']);
    }

    public function test_the_overtime_queue_is_not_told_they_were_five_hours_late(): void
    {
        $this->approveHalfDay('AM');
        $this->clockInAt('13:00');

        $this->assertSame(
            [],
            app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE),
        );
    }

    public function test_no_disciplinary_violation_is_raised(): void
    {
        $this->approveHalfDay('AM');
        $this->clockInAt('13:00');

        $this->travelTo(Carbon::parse('2026-08-31 12:00', config('app.timezone_local')));
        app(ViolationService::class)->recompute('2026-08');

        $this->assertSame(0, Violation::where('employee_id', $this->employee->id)->where('type', 'late')->count());
    }

    public function test_an_abandoned_punch_closes_at_the_worked_halfs_end(): void
    {
        $this->approveHalfDay('PM'); // they work the morning, 08:00–12:00

        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . ' 08:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
        ]);

        $this->travelTo(Carbon::parse('2026-08-25 09:00', config('app.timezone_local')));
        $review = app(AttendanceService::class)->openShiftReview(self::DATE, self::DATE);

        $this->assertCount(1, $review);
        $this->assertSame('12:00', $review[0]['resolved_time_out']);
    }

    // --- early-out, which is priced against the same window ------------------------------------

    public function test_an_early_out_is_measured_from_the_worked_halfs_end(): void
    {
        $tz = config('app.timezone_local');

        // Afternoon off, so they work 08:00–12:00 — and leave at 10:00. Two hours short of the
        // noon finish they owed, NOT seven hours short of 5 PM.
        $this->approveHalfDay('PM');
        EarlyOutRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'early_out_time' => '10:00', 'reason' => 'x', 'status' => 'approved',
        ]);
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . ' 08:00', $tz)->utc(),
            'time_out' => Carbon::parse(self::DATE . ' 10:00', $tz)->utc(),
            'status' => 'present',
        ]);

        $this->travelTo(Carbon::parse('2026-08-31 12:00', $tz));
        $detail = app(AttendanceService::class)->unpaidDaysDetailFor(
            $this->employee, self::DATE, self::DATE, app(AttendancePolicyService::class)->get(),
        );

        // 0.5 for the half + 120 min ÷ the 540-min scheduled span. It used to charge a FULL day:
        // 0.5 for the half plus another 0.5 for an early-out measured against 5 PM.
        $this->assertEqualsWithDelta(0.5 + 120 / 540, $detail->unpaidDays, 0.0001);
        $this->assertLessThan(1.0, $detail->unpaidDays);
    }

    public function test_an_early_out_cannot_be_filed_inside_the_half_already_off(): void
    {
        $this->approveHalfDay('PM'); // they work 08:00–12:00; 3 PM is already their own time

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(EarlyOutRequestService::class)->create($this->employee, [
            'work_date' => self::DATE, 'early_out_time' => '15:00', 'reason' => 'x',
        ]);
    }

    public function test_an_early_out_inside_the_worked_half_still_files(): void
    {
        $this->approveHalfDay('PM');

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));
        $request = app(EarlyOutRequestService::class)->create($this->employee, [
            'work_date' => self::DATE, 'early_out_time' => '10:00', 'reason' => 'x',
        ]);

        $this->assertSame('pending', $request->status);
    }

    public function test_the_open_shift_queue_reports_the_window_it_resolved_against(): void
    {
        $this->approveHalfDay('PM');

        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => self::DATE,
            'time_in' => Carbon::parse(self::DATE . ' 08:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
        ]);

        $this->travelTo(Carbon::parse('2026-08-25 09:00', config('app.timezone_local')));
        $row = app(AttendanceService::class)->openShiftReview(self::DATE, self::DATE)[0];

        // The reported window and the resolved end have to be the same story.
        $this->assertSame('08:00', $row['scheduled_start']);
        $this->assertSame('12:00', $row['scheduled_end']);
        $this->assertSame('12:00', $row['resolved_time_out']);
        $this->assertSame('PM', $row['half_day_off']);
    }

    // --- the money ---------------------------------------------------------------------------

    public function test_on_time_half_days_never_trigger_the_per_n_lates_penalty(): void
    {
        $policy = app(AttendancePolicyService::class)->get();
        $policy->forceFill(['late_penalty_enabled' => true, 'lates_per_deduction' => 3])->save();

        // Three afternoons off-and-back, each started at exactly 1:00 PM.
        foreach (['2026-08-24', '2026-08-25', '2026-08-26'] as $date) {
            $this->approveHalfDay('AM', $date);
            $this->clockInAt('13:00', $date);
            $this->travelTo(Carbon::parse("{$date} 17:00", config('app.timezone_local')));
            app(AttendanceService::class)->clockOut($this->employee);
        }

        $this->travelTo(Carbon::parse('2026-08-31 12:00', config('app.timezone_local')));
        $detail = app(AttendanceService::class)->unpaidDaysDetailFor(
            $this->employee, '2026-08-24', '2026-08-26', app(AttendancePolicyService::class)->get(),
        );

        // Three half days off = 1.5 unpaid days, and NOT a fourth for imagined tardiness.
        $this->assertSame(0.0, $detail->latePenaltyDays);
        $this->assertEqualsWithDelta(1.5, $detail->unpaidDays, 0.0001);
        $this->assertSame(
            [],
            array_values(array_filter($detail->lines, fn (array $l) => $l['kind'] === 'late_penalty')),
        );
    }
}
