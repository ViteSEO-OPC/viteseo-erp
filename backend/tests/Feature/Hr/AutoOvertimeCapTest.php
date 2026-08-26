<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/** A ceiling on overtime nobody approved. */
class AutoOvertimeCapTest extends HrTestCase
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

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function policy(array $attrs = []): void
    {
        $this->policyAlways(array_merge([
            'overtime_mode' => 'auto_after_threshold',
            'overtime_threshold_minutes' => 0,
            // Item 7's review flag OFF for this file. Every case here works a six-hour overrun,
            // which would otherwise also trip the "unusually long" flag and put a `flagged` row
            // next to the `held` one under test — these are the CAP tests, and the flag has its
            // own file. Individual cases override this where the interaction is the point.
            'auto_overtime_review_minutes' => 0,
        ], $attrs));
    }

    /** A workday (2026-08-03 is a Monday) clocked in at 08:00 Manila and out at $timeOut Manila. */
    private function worked(string $date, string $timeOut): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} {$timeOut}", $tz)->utc(),
            'status' => 'present',
        ]);
    }

    /** @return array<string, array<string, int>> */
    private function autoMap(string $start = '2026-08-03', string $end = '2026-08-07'): array
    {
        $policy = app(AttendancePolicyService::class)->get()->fresh();

        return AttendanceService::applyAutoOvertimePeriodCap(
            app(AttendanceService::class)->autoOvertimeMinutesFor(
                $this->employee,
                $start,
                $end,
                (int) $policy->overtime_threshold_minutes,
                (int) $policy->auto_overtime_daily_cap_minutes,
            ),
            (int) $policy->auto_overtime_period_cap_minutes,
        );
    }

    // --- the daily cap ---------------------------------------------------------------------------

    /** THE GUARD ON THE MIGRATION. 0 = uncapped, so nothing already being paid moves. */
    public function test_the_shipped_default_changes_nothing(): void
    {
        $this->policy(); // caps left at their 0 defaults
        $this->worked('2026-08-03', '23:00:00'); // the forgotten clock-out: 6 hours past shift end

        $entry = $this->autoMap()['2026-08-03'];

        $this->assertSame(360, $entry['minutes'], 'uncapped, exactly as before this change');
        $this->assertSame(0, $entry['excess_minutes'], 'nothing held back means no queue item');
    }

    public function test_the_daily_cap_holds_back_the_excess(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $entry = $this->autoMap()['2026-08-03'];

        $this->assertSame(240, $entry['minutes'], 'four hours pay automatically');
        $this->assertSame(360, $entry['raw_minutes'], 'the full figure is kept, not discarded');
        $this->assertSame(120, $entry['excess_minutes'], 'two hours await an approval');
    }

    public function test_a_day_inside_the_cap_is_untouched(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '19:00:00'); // 2 hours

        $entry = $this->autoMap()['2026-08-03'];

        $this->assertSame(120, $entry['minutes']);
        $this->assertSame(0, $entry['excess_minutes']);
    }

    /**
     * Capping has to move the night portion with it. The window is 17:00→23:00, of which 22:00→23:00
     * is night; capped to four hours the window becomes 17:00→21:00, which contains no night at all.
     * Trimming the total and leaving `night` alone would pay night premium on unpaid minutes.
     */
    public function test_capping_recomputes_the_night_portion(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $uncapped = AttendanceService::applyAutoOvertimePeriodCap(
            app(AttendanceService::class)->autoOvertimeMinutesFor($this->employee, '2026-08-03', '2026-08-07', 0, 0),
            0,
        );

        $this->assertGreaterThan(0, $uncapped['2026-08-03']['night'], '22:00–23:00 is night');
        $this->assertSame(0, $this->autoMap()['2026-08-03']['night'], 'the paid window now ends at 21:00');
    }

    // --- the period cap -------------------------------------------------------------------------

    /** Spent chronologically: the earliest days consume the budget, so pay never moves retroactively. */
    public function test_the_period_cap_is_spent_earliest_first(): void
    {
        $this->policy(['auto_overtime_period_cap_minutes' => 180]);
        $this->worked('2026-08-03', '19:00:00'); // 120
        $this->worked('2026-08-04', '19:00:00'); // 120 — only 60 of it fits
        $this->worked('2026-08-05', '19:00:00'); // 120 — nothing left

        $map = $this->autoMap();

        $this->assertSame(120, $map['2026-08-03']['minutes'], 'the first day is paid in full');
        $this->assertSame(60, $map['2026-08-04']['minutes'], 'the second exhausts the budget');
        $this->assertSame(0, $map['2026-08-05']['minutes'], 'the third pays nothing automatically');
        $this->assertSame(120, $map['2026-08-05']['excess_minutes'], 'and all of it is queued');
    }

    /** The two caps compose rather than stacking: the excess is measured against the RAW figure once. */
    public function test_both_caps_together_report_the_excess_once(): void
    {
        $this->policy([
            'auto_overtime_daily_cap_minutes' => 240,
            'auto_overtime_period_cap_minutes' => 60,
        ]);
        $this->worked('2026-08-03', '23:00:00'); // raw 360, daily→240, period→60

        $entry = $this->autoMap()['2026-08-03'];

        $this->assertSame(60, $entry['minutes']);
        $this->assertSame(300, $entry['excess_minutes'], '360 − 60, not 360 − 240 − 180');
    }

    // --- the release ----------------------------------------------------------------------------

    /**
     * THE POINT OF THE WHOLE DESIGN. The cap bounds what pays unasked; it does not cap earnings.
     * An approved request for the date releases the held minutes into the run.
     */
    public function test_an_approved_request_releases_the_held_minutes(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $capped = $this->payslipOvertimeMinutes();
        $this->assertSame(240, $capped, 'without an approval the cap holds');

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 6, 'time_from' => '17:00', 'time_to' => '23:00',
            'reason' => 'Month-end close', 'status' => 'approved',
        ]);

        $this->assertSame(360, $this->payslipOvertimeMinutes(), 'the approval pays the full six hours');
    }

    /** A partial approval releases only what it covers. */
    public function test_a_partial_approval_releases_only_its_own_minutes(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00'); // 360

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 5, 'time_from' => '17:00', 'time_to' => '22:00',
            'reason' => 'Month-end close', 'status' => 'approved',
        ]);

        $this->assertSame(300, $this->payslipOvertimeMinutes(), '5 approved hours, not 6');
    }

    /** HR may approve only the held tail instead of repeating the four hours auto-pay already covers. */
    public function test_an_approval_for_only_the_held_window_releases_that_window(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00'); // 17:00–23:00, with 21:00–23:00 held

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 2, 'time_from' => '21:00', 'time_to' => '23:00',
            'reason' => 'Approved held tail', 'status' => 'approved',
        ]);

        $this->assertSame(360, $this->payslipOvertimeMinutes());
    }

    /** A disjoint approval pays its exact hour and does not turn the unapproved gap into night OT. */
    public function test_a_disjoint_night_approval_releases_only_its_exact_interval(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00'); // 17:00–21:00 auto-paid; 22:00–23:00 approved

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 1, 'time_from' => '22:00', 'time_to' => '23:00',
            'reason' => 'Approved night tail', 'status' => 'approved',
        ]);

        $result = $this->payslipOvertimeResult();

        $this->assertSame(300, $result['overtime_minutes']);
        $this->assertSame(60, $result['night_diff_minutes']);
    }

    /** A request still awaiting a decision releases nothing. */
    public function test_a_pending_request_releases_nothing(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 6, 'time_from' => '17:00', 'time_to' => '23:00',
            'reason' => 'Month-end close', 'status' => 'pending',
        ]);

        $this->assertSame(240, $this->payslipOvertimeMinutes());
    }

    // --- the review queue -----------------------------------------------------------------------

    public function test_the_review_queue_lists_what_the_cap_held(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $rows = app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-03', $rows[0]['work_date']);
        $this->assertSame(120, $rows[0]['excess_minutes']);
        $this->assertSame(360, $rows[0]['raw_minutes']);
        $this->assertSame('E-001', $rows[0]['employee_no']);
    }

    /** Approving is the only resolution — there is no separate state to clear. */
    public function test_approving_removes_the_item_from_the_queue(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 6, 'time_from' => '17:00', 'time_to' => '23:00',
            'reason' => 'Month-end close', 'status' => 'approved',
        ]);

        $this->assertSame([], app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15'));
    }

    public function test_approving_only_the_held_tail_removes_the_item_from_the_queue(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 2, 'time_from' => '21:00', 'time_to' => '23:00',
            'reason' => 'Approved held tail', 'status' => 'approved',
        ]);

        $this->assertSame([], app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15'));
    }

    public function test_a_non_overlapping_approval_leaves_the_review_item_unchanged(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 2, 'time_from' => '23:00', 'time_to' => '01:00',
            'reason' => 'Outside the actual punches', 'status' => 'approved',
        ]);

        $rows = app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15');

        $this->assertCount(1, $rows);
        $this->assertSame(120, $rows[0]['excess_minutes']);
        $this->assertSame(0, $rows[0]['approved_minutes']);
    }

    public function test_duplicate_exact_approvals_do_not_double_clear_the_review_item(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        foreach ([1, 2] as $copy) {
            OvertimeRequest::create([
                'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
                'hours' => 1, 'time_from' => '22:00', 'time_to' => '23:00',
                'reason' => "Duplicate approval {$copy}", 'status' => 'approved',
            ]);
        }

        $rows = app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15');

        $this->assertCount(1, $rows);
        $this->assertSame(60, $rows[0]['excess_minutes']);
        $this->assertSame(60, $rows[0]['approved_minutes']);
    }

    public function test_mixed_exact_and_legacy_approvals_release_the_legacy_fallback(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 2, 'time_from' => '17:00', 'time_to' => '19:00',
            'reason' => 'Exact time already inside auto pay', 'status' => 'approved',
        ]);
        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 2, 'time_from' => null, 'time_to' => null,
            'reason' => 'Legacy approval for held capacity', 'status' => 'approved',
        ]);

        $this->assertSame(360, $this->payslipOvertimeMinutes());
        $this->assertSame([], app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15'));
    }

    public function test_the_queue_is_empty_in_approved_only_mode(): void
    {
        $this->policy(['overtime_mode' => 'approved_only', 'auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $this->assertSame(
            [],
            app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15'),
            'nothing is auto-paid in this mode, so nothing is being held back',
        );
    }

    public function test_the_queue_is_empty_when_uncapped(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '23:00:00');

        $this->assertSame([], app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15'));
    }

    /** Read-only: opening the queue must not create requests or touch attendance (cf.). */
    public function test_the_queue_writes_nothing(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $service = app(AttendanceService::class);
        $service->autoOvertimeRows('2026-08-01', '2026-08-15');
        $service->autoOvertimeRows('2026-08-01', '2026-08-15');

        $this->assertSame(0, OvertimeRequest::count(), 'no request was filed on the caller’s behalf');
        $this->assertSame(1, Attendance::count());
    }

    public function test_hr_can_read_the_queue_over_http(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '23:00:00');

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/overtime-requests/auto-review?start=2026-08-01&end=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.0.work_date', '2026-08-03')
            ->assertJsonPath('data.0.excess_minutes', 120);
    }

    public function test_an_employee_cannot_read_the_queue(): void
    {
        $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/overtime-requests/auto-review?start=2026-08-01&end=2026-08-15')
            ->assertForbidden();
    }

    /** What the payslip's overtime_minutes comes to for the first August cycle. */
    private function payslipOvertimeMinutes(): int
    {
        return $this->payslipOvertimeResult()['overtime_minutes'];
    }

    /** @return array{overtime_minutes:int, night_diff_minutes:int} */
    private function payslipOvertimeResult(): array
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $payslip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();
        $result = [
            'overtime_minutes' => (int) $payslip->overtime_minutes,
            'night_diff_minutes' => (int) $payslip->night_diff_minutes,
        ];

        $run->payslips()->forceDelete();
        $run->forceDelete();

        return $result;
    }
}
