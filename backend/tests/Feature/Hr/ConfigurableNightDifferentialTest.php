<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\AttendancePolicyVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\OvertimeRequestService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class ConfigurableNightDifferentialTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $schedule = WorkSchedule::create([
            'name' => 'Night',
            'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '20:00',
            'end_time' => '06:00',
            'grace_minutes' => 0,
            'unpaid_break_minutes' => 0,
            'paid_break_minutes' => 0,
            'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-ND-001',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 20000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
            'work_schedule_id' => $schedule->id,
        ]);
    }

    private function work(string $date): void
    {
        $this->punch($date, '20:00', 10);
    }

    /** A punch of $hours from $timeIn on $date, rolling past midnight as needed. */
    private function punch(string $date, string $timeIn, int $hours): void
    {
        $tz = config('app.timezone_local');
        $start = Carbon::parse("{$date} {$timeIn}", $tz);

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $start->copy()->utc(),
            'time_out' => $start->copy()->addHours($hours)->utc(),
            'lunch_minutes' => 0,
            'status' => 'present',
        ]);
    }

    /** Set the company window from 2026-03-01, so `asOf` inside the March cycle resolves it. */
    private function window(string $from, string $to, array $extra = []): void
    {
        app(AttendancePolicyService::class)->update([
            'night_diff_start_time' => $from,
            'night_diff_end_time' => $to,
            'effective_from' => '2026-03-01',
        ] + $extra);
    }

    /** The rules a March payroll run would price with. */
    private function marchPolicy(): AttendancePolicy
    {
        return app(AttendancePolicyService::class)->asOf('2026-03-15');
    }

    public function test_the_api_exposes_and_updates_the_night_window(): void
    {
        $this->actingAsHr()
            ->getJson('/api/v1/hr/attendance-policy')
            ->assertOk()
            ->assertJsonPath('data.night_diff_start_time', '22:00')
            ->assertJsonPath('data.night_diff_end_time', '06:00');

        $this->actingAsHr()
            // No date: this case is about the endpoint exposing and round-tripping the window,
            // and the endpoint no longer accepts a past one. The cases that need the window
            // dated INTO March go through the service, which is where backdating still lives.
            ->putJson('/api/v1/hr/attendance-policy', [
                'night_diff_start_time' => '21:30',
                'night_diff_end_time' => '05:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.night_diff_start_time', '21:30')
            ->assertJsonPath('data.night_diff_end_time', '05:30');
    }

    public function test_the_api_rejects_invalid_or_equal_window_times(): void
    {
        $this->actingAsHr()
            ->putJson('/api/v1/hr/attendance-policy', [
                'night_diff_start_time' => 'not-a-time',
                'night_diff_end_time' => '06:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('night_diff_start_time');

        $this->actingAsHr()
            ->putJson('/api/v1/hr/attendance-policy', [
                'night_diff_start_time' => '22:00',
                'night_diff_end_time' => '22:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['night_diff_start_time', 'night_diff_end_time']);
    }

    public function test_payroll_uses_and_snapshots_the_window_in_force_at_cycle_end(): void
    {
        app(AttendancePolicyService::class)->update([
            'night_diff_start_time' => '21:00',
            'night_diff_end_time' => '03:00',
            'effective_from' => '2026-03-01',
        ]);
        $this->work('2026-03-02');

        $march = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $marchSlip = $march->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(360, (int) $marchSlip->night_diff_minutes);
        $this->assertSame('21:00:00', $march->policy_snapshot['attendance_policy']['night_diff_start_time']);
        $this->assertSame('03:00:00', $march->policy_snapshot['attendance_policy']['night_diff_end_time']);

        // A later live change must not re-price an older cycle when it is run again.
        app(AttendancePolicyService::class)->update([
            'night_diff_start_time' => '22:00',
            'night_diff_end_time' => '06:00',
            'effective_from' => '2026-08-01',
        ]);

        $rerun = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $rerunSlip = $rerun->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(360, (int) $rerunSlip->night_diff_minutes);
        $this->assertSame('21:00:00', $rerun->policy_snapshot['attendance_policy']['night_diff_start_time']);
        $this->assertSame('03:00:00', $rerun->policy_snapshot['attendance_policy']['night_diff_end_time']);
    }

    public function test_legacy_policy_versions_without_window_fields_use_the_original_default(): void
    {
        $payload = app(AttendancePolicyService::class)->get()->getAttributes();
        unset(
            $payload['id'],
            $payload['created_at'],
            $payload['updated_at'],
            $payload['night_diff_start_time'],
            $payload['night_diff_end_time'],
        );

        AttendancePolicyVersion::create([
            'effective_from' => '2025-01-01',
            'payload' => $payload,
        ]);

        $historic = app(AttendancePolicyService::class)->asOf('2025-01-15');

        $this->assertSame('22:00:00', $historic->night_diff_start_time);
        $this->assertSame('06:00:00', $historic->night_diff_end_time);
    }

    /**
     * The window has to reach the OVERTIME night split, not only the day's total night minutes.
     * That split is what decides which minutes compound onto the overtime rate, so a window that
     * stopped at `nightMinutesFor` would leave graveyard overtime priced against 22:00–06:00
     * while the payslip's ND line used the company's own hours.
     */
    public function test_a_custom_window_reaches_automatic_overtime_night_minutes(): void
    {
        $this->window('23:00', '08:00', ['overtime_mode' => 'auto_after_threshold']);
        // Mon 2 Mar, 20:00 → 08:00. The shift ends at 06:00, so the last two hours are overtime,
        // and the window runs to 08:00 — every overtime minute is also a night minute.
        $this->punch('2026-03-02', '20:00', 12);

        $auto = app(AttendanceService::class)->autoOvertimeMinutesFor(
            $this->employee,
            '2026-03-02',
            '2026-03-02',
            0,
            0,
            $this->marchPolicy(),
        );

        $this->assertSame(120, $auto['2026-03-02']['minutes']);
        $this->assertSame(120, $auto['2026-03-02']['night'], 'the 06:00–08:00 tail is inside a 23:00–08:00 window');
    }

    /** The same for the approval path, which computes night per matched punch window. */
    public function test_a_custom_window_reaches_approved_overtime_night_minutes(): void
    {
        $this->window('23:00', '08:00');
        $this->punch('2026-03-02', '20:00', 12);

        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-03-02',
            'time_from' => '06:00',
            'time_to' => '08:00',
            'hours' => OvertimeRequestService::hoursBetween('06:00', '08:00'),
            'reason' => 'Handover',
            'status' => 'approved',
        ]);

        $approved = app(AttendanceService::class)->approvedOvertimeMinutesFor(
            $this->employee,
            '2026-03-02',
            '2026-03-02',
            null,
            $this->marchPolicy(),
        );

        $this->assertSame(120, $approved['2026-03-02']['minutes']);
        $this->assertSame(120, $approved['2026-03-02']['night']);
    }

    /**
     * A window does not have to cross midnight. A company may relocate the band inside one day,
     * and the band arithmetic must not assume the 22:00–06:00 shape.
     */
    public function test_a_window_that_does_not_cross_midnight_prices_a_day_shift(): void
    {
        WorkSchedule::firstOrFail()->update(['start_time' => '08:00', 'end_time' => '17:00']);
        $this->window('13:00', '15:00');
        $this->punch('2026-03-02', '08:00', 9);

        $run = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $payslip = $run->payslips()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(120, (int) $payslip->night_diff_minutes);
    }
}
