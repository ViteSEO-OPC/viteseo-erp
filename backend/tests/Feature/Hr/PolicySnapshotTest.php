<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** (2026-07-28 audit) — the run's policy snapshot must cover everything that priced it. */
class PolicySnapshotTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function snapshot(): array
    {
        return app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create())
            ->policy_snapshot;
    }

    /** THE GUARD: every editable policy field is captured, including any added later. */
    public function test_every_attendance_policy_field_is_captured(): void
    {
        $captured = array_keys($this->snapshot()['attendance_policy']);

        $this->assertEqualsCanonicalizing(
            array_keys(AttendancePolicyService::DEFAULTS),
            $captured,
            'A policy field is missing from the snapshot — build it from array_keys(DEFAULTS).',
        );
    }

    /** The exact fields v1 forgot, all of which move money. */
    public function test_the_premium_and_overtime_rates_are_captured(): void
    {
        $policy = $this->snapshot()['attendance_policy'];

        foreach ([
            'premium_rest_day_pct', 'premium_special_day_pct', 'premium_regular_holiday_pct',
            'overtime_ordinary_pct', 'overtime_rest_special_pct', 'overtime_regular_holiday_pct',
            'night_diff_pct', 'overtime_mode',
        ] as $field) {
            $this->assertArrayHasKey($field, $policy, "{$field} is not in the snapshot");
        }
    }

    /** A raised rate shows up in the NEXT run's snapshot. */
    public function test_the_snapshot_records_the_rate_in_force(): void
    {
        $this->assertSame(125, $this->snapshot()['attendance_policy']['overtime_ordinary_pct']);

        AttendancePolicy::query()->update(['overtime_ordinary_pct' => 150]);

        $this->assertSame(150, $this->snapshot()['attendance_policy']['overtime_ordinary_pct']);
    }

    /** Statutory config sets prices too, so it is captured alongside. */
    public function test_contribution_settings_are_captured(): void
    {
        // RefreshDatabase leaves this table empty; the rates live in their own seeder.
        $this->seed(ContributionSettingSeeder::class);

        $contributions = $this->snapshot()['contributions'];

        $this->assertNotEmpty($contributions);
        $this->assertArrayHasKey('type', $contributions[0]);
        $this->assertArrayHasKey('config', $contributions[0]);
        $this->assertArrayHasKey('is_active', $contributions[0]);
    }

    /** Frequency and the deduction/tax timings decide what lands on this cutoff. */
    public function test_the_pay_schedule_is_captured(): void
    {
        $setting = $this->snapshot()['payroll_setting'];

        $this->assertSame('semi_monthly', $setting['frequency']);
        $this->assertArrayHasKey('deduction_timing', $setting);
        $this->assertArrayHasKey('tax_timing', $setting);
    }

    public function test_the_snapshot_is_versioned(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame(2, $snapshot['version']);
        $this->assertArrayHasKey('captured_at', $snapshot);
    }

    /** It reaches the detail endpoint, but never the runs list. */
    public function test_the_snapshot_is_exposed_on_the_detail_only(): void
    {
        $run = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());

        $this->actingAsHr()
            ->getJson("/api/v1/hr/payroll/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.policy_snapshot.version', 2);

        $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/runs')
            ->assertOk()
            ->assertJsonMissingPath('data.0.policy_snapshot');
    }
}
