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

/** Item 7 — the half of the overtime review queue that flags without withholding. */
class AutoOvertimeFlagTest extends HrTestCase
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

    /**
     * Auto mode with the SHIPPED caps (both 0 = uncapped) and the shipped review threshold, which
     * is the exact configuration item 7 exists for.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function policy(array $attrs = []): void
    {
        app(AttendancePolicyService::class)->get()->update(array_merge([
            'overtime_mode' => 'auto_after_threshold',
            'overtime_threshold_minutes' => 0,
            'auto_overtime_daily_cap_minutes' => 0,
            'auto_overtime_period_cap_minutes' => 0,
            'auto_overtime_review_minutes' => 240,
        ], $attrs));
    }

    /** 2026-08-03 is a Monday. Punches are stored UTC and read back as Manila minute-of-day. */
    private function worked(string $date, string $timeIn, string $timeOut): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$timeIn}", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} {$timeOut}", $tz)->utc(),
            'status' => 'present',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function review(): array
    {
        return app(AttendanceService::class)->autoOvertimeRows('2026-08-01', '2026-08-15');
    }

    // --- the gap item 7 closes -------------------------------------------------------------------

    public function test_an_unusually_long_day_is_flagged_even_with_no_caps_set(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '08:00', '23:00'); // 6 hours past a 17:00 end

        $rows = $this->review();

        $this->assertCount(1, $rows, 'the shipped configuration used to review nothing at all');
        $this->assertSame('flagged', $rows[0]['kind']);
        $this->assertSame(['long_overtime'], $rows[0]['reasons']);
        $this->assertSame(360, $rows[0]['raw_minutes']);
        $this->assertSame(360, $rows[0]['paid_minutes'], 'flagging withholds nothing');
        $this->assertSame(0, $rows[0]['excess_minutes']);
    }

    /** THE LOAD-BEARING TEST. A flag must be a notification, not a cap wearing a different name. */
    public function test_flagging_a_day_does_not_change_what_it_pays(): void
    {
        $this->policy(['auto_overtime_review_minutes' => 0]);
        $this->worked('2026-08-03', '08:00', '23:00');
        $unflagged = $this->payslipOvertimeMinutes();

        Attendance::query()->forceDelete();
        app(AttendancePolicyService::class)->get()->update(['auto_overtime_review_minutes' => 60]);
        $this->worked('2026-08-03', '08:00', '23:00');

        $this->assertNotSame([], $this->review(), 'the day is now flagged');
        $this->assertSame($unflagged, $this->payslipOvertimeMinutes(), 'and paid exactly the same');
    }

    public function test_a_day_under_the_threshold_is_not_flagged(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '08:00', '19:00'); // 2 hours, well under 4

        $this->assertSame([], $this->review());
    }

    public function test_a_zero_threshold_turns_the_flag_off(): void
    {
        $this->policy(['auto_overtime_review_minutes' => 0]);
        $this->worked('2026-08-03', '08:00', '23:00');

        $this->assertSame([], $this->review(), '0 means never flag, not flag everything');
    }

    /** Measured on the raw overrun: a cap trimming the paid figure doesn't make the day ordinary. */
    public function test_the_threshold_measures_the_whole_overrun_not_the_capped_part(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 60, 'auto_overtime_review_minutes' => 240]);
        $this->worked('2026-08-03', '08:00', '23:00'); // 360 raw, 60 paid, 300 held

        $rows = $this->review();

        $this->assertCount(1, $rows);
        $this->assertSame(60, $rows[0]['paid_minutes']);
        $this->assertContains('long_overtime', $rows[0]['reasons']);
    }

    // --- late plus overtime on the same day -------------------------------------------------------

    public function test_a_day_that_was_both_late_and_overtime_is_flagged(): void
    {
        $this->policy();
        // In at 09:00 against an 08:00 start with 15 minutes grace, out two hours past the end.
        $this->worked('2026-08-03', '09:00', '19:00');

        $rows = $this->review();

        $this->assertCount(1, $rows);
        $this->assertSame('flagged', $rows[0]['kind']);
        $this->assertSame(['late_and_overtime'], $rows[0]['reasons'], 'two hours is under the 4h threshold');
        $this->assertSame(60, $rows[0]['late_minutes'], 'counted from the scheduled start, not from grace');
    }

    public function test_an_arrival_inside_the_grace_period_is_not_late(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '08:10', '19:00'); // 10 minutes into a 15-minute grace

        $this->assertSame([], $this->review());
    }

    /**
     * Lateness here is read from the punches, not from the deduction ledger — DeductionService
     * reports 0 late minutes whenever the company does not charge for lateness, and the surprising
     * half of this pairing is the overtime being paid, not the lateness being charged.
     */
    public function test_lateness_is_flagged_even_when_the_company_never_charges_for_it(): void
    {
        $this->policy(['late_deduction_mode' => 'none', 'late_penalty_enabled' => false]);
        $this->worked('2026-08-03', '09:00', '19:00');

        $rows = $this->review();

        $this->assertCount(1, $rows);
        $this->assertSame(['late_and_overtime'], $rows[0]['reasons']);
        $this->assertSame(60, $rows[0]['late_minutes']);
    }

    public function test_a_late_day_with_no_overtime_is_not_listed(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '09:00', '17:00'); // late, left on time

        $this->assertSame([], $this->review(), 'lateness alone is the violation engine\'s business');
    }

    public function test_both_flags_can_fire_on_one_day(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '09:00', '23:00');

        $rows = $this->review();

        $this->assertCount(1, $rows);
        $this->assertSame(['long_overtime', 'late_and_overtime'], $rows[0]['reasons']);
    }

    // --- how the two kinds relate ----------------------------------------------------------------

    /** Held money outranks a notification: HR must act on one and merely read the other. */
    public function test_a_capped_day_is_held_even_when_it_also_trips_a_flag(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '09:00', '23:00');

        $rows = $this->review();

        $this->assertCount(1, $rows);
        $this->assertSame('held', $rows[0]['kind']);
        $this->assertSame(120, $rows[0]['excess_minutes']);
        $this->assertContains('cap', $rows[0]['reasons']);
        $this->assertContains('long_overtime', $rows[0]['reasons']);
    }

    /** Approving releases the held minutes; the flag stays, because the day is still unusual. */
    public function test_approving_turns_a_held_row_into_a_flagged_one(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '08:00', '23:00');
        $this->assertSame('held', $this->review()[0]['kind']);

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 6, 'time_from' => '17:00', 'time_to' => '23:00',
            'reason' => 'Month-end close', 'status' => 'approved',
        ]);

        $rows = $this->review();

        $this->assertCount(1, $rows);
        $this->assertSame('flagged', $rows[0]['kind'], 'nothing is being withheld any more');
        $this->assertSame(0, $rows[0]['excess_minutes']);
        $this->assertSame(['long_overtime'], $rows[0]['reasons']);
        $this->assertSame(360, $rows[0]['approved_minutes']);
    }

    public function test_held_rows_sort_above_flagged_ones(): void
    {
        $this->policy(['auto_overtime_daily_cap_minutes' => 240]);
        $this->worked('2026-08-03', '08:00', '23:00'); // 360 raw → 120 held
        $this->worked('2026-08-04', '08:00', '22:00'); // 300 raw → 60 held

        OvertimeRequest::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'hours' => 6, 'time_from' => '17:00', 'time_to' => '23:00',
            'reason' => 'Released', 'status' => 'approved',
        ]);

        $rows = $this->review();

        $this->assertCount(2, $rows);
        // The 4th is the SMALLER overrun but the only one still withholding pay, so it leads.
        $this->assertSame('held', $rows[0]['kind']);
        $this->assertSame('2026-08-04', $rows[0]['work_date']);
        $this->assertSame('flagged', $rows[1]['kind']);
    }

    // --- what stays out ---------------------------------------------------------------------------

    public function test_nothing_is_reviewed_in_approved_only_mode(): void
    {
        $this->policy(['overtime_mode' => 'approved_only']);
        $this->worked('2026-08-03', '09:00', '23:00');

        $this->assertSame(
            [],
            $this->review(),
            'every paid minute was authorised by a person, so there is nothing to review',
        );
    }

    public function test_an_abandoned_shift_is_never_flagged(): void
    {
        $this->policy();
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-03',
            'time_in' => Carbon::parse('2026-08-03 08:00', config('app.timezone_local'))->utc(),
            'status' => 'present', // no time out — item 5 resolves this to the scheduled end
        ]);

        $this->assertSame([], $this->review(), 'a missing punch produces zero overtime, so zero to flag');
    }

    public function test_the_endpoint_returns_the_flagged_rows(): void
    {
        $this->policy();
        $this->worked('2026-08-03', '09:00', '23:00');

        $this->actingAsHr()
            ->getJson('/api/v1/hr/overtime-requests/auto-review?start=2026-08-01&end=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'flagged')
            ->assertJsonPath('data.0.late_minutes', 60)
            ->assertJsonPath('data.0.reasons', ['long_overtime', 'late_and_overtime']);
    }

    // --- the policy field -------------------------------------------------------------------------

    public function test_hr_can_set_the_review_threshold(): void
    {
        $this->actingAsHr()
            ->patchJson('/api/v1/hr/attendance-policy', ['auto_overtime_review_minutes' => 90])
            ->assertOk()
            ->assertJsonPath('data.auto_overtime_review_minutes', 90);
    }

    public function test_the_review_threshold_is_bounded(): void
    {
        $this->actingAsHr()
            ->patchJson('/api/v1/hr/attendance-policy', ['auto_overtime_review_minutes' => 5000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('auto_overtime_review_minutes');
    }

    /**
     * Overtime minutes on the payslip for the cycle containing 2026-08-03. The run is torn down
     * again so a second call in the same test prices the period fresh rather than reading back the
     * first run's figures.
     */
    private function payslipOvertimeMinutes(): int
    {
        $run = app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());

        $minutes = (int) $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail()->overtime_minutes;

        $run->payslips()->forceDelete();
        $run->forceDelete();

        return $minutes;
    }
}
