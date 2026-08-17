<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicyVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
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
        $tz = config('app.timezone_local');
        $start = Carbon::parse("{$date} 20:00", $tz);

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $start->copy()->utc(),
            'time_out' => $start->copy()->addHours(10)->utc(),
            'lunch_minutes' => 0,
            'status' => 'present',
        ]);
    }

    public function test_the_api_exposes_and_updates_the_night_window(): void
    {
        $this->actingAsHr()
            ->getJson('/api/v1/hr/attendance-policy')
            ->assertOk()
            ->assertJsonPath('data.night_diff_start_time', '22:00')
            ->assertJsonPath('data.night_diff_end_time', '06:00');

        $this->actingAsHr()
            ->putJson('/api/v1/hr/attendance-policy', [
                'night_diff_start_time' => '21:30',
                'night_diff_end_time' => '05:30',
                'effective_from' => '2026-03-01',
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
}
