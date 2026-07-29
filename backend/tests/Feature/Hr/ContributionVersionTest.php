<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B3a — statutory rates are dated versions, resolved as of the PAY PERIOD.
 *
 * One undated row meant changing a rate silently repriced every payroll ever run, and an old
 * period could never be reproduced. These rates really do move: SSS went 14% → 15% in January
 * 2025 with its ceiling ₱30,000 → ₱35,000.
 *
 * The headline test is the 2026 SSS schedule end to end: 5% employee, 10% employer, plus the flat
 * ₱30 employer EC contribution above ₱15,000 — which must never touch the employee's net pay.
 */
class ContributionVersionTest extends HrTestCase
{
    use RefreshDatabase;

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
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

    }

    /** @return array<string, mixed> */
    private function slip(string $cycleEnd): array
    {
        return app(PayrollService::class)->payslipFor(
            employee: $this->employee->fresh(),
            reimb: 0.0, unpaidDays: 0.0, periodsPerYear: 24, workingDaysInPeriod: 11,
            // `count: 1` puts the WHOLE monthly contribution on this payslip. With the usual
            // semi-monthly `count: 2` the timing setting splits it in half, and every figure
            // below would be half the published monthly rate — true, but harder to check against
            // the schedule HR actually reads.
            dist: [
                'index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every',
                'cycle_end' => $cycleEnd,
            ],
        );
    }

    private function sss2026(): void
    {
        ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'salary_cap' => 35000,
            'fixed_employer_add_on' => 30, 'add_on_min_salary' => 15000,
            'note' => '2026 schedule: 15% total, ₱35k MSC ceiling, ₱30 EC above ₱15k.',
        ]);
    }

    /** THE HEADLINE: the 2026 SSS schedule, end to end, on a ₱20,000 salary. */
    public function test_the_2026_sss_schedule_computes_correctly(): void
    {
        $this->sss2026();

        $slip = $this->slip('2026-03-15');

        $this->assertSame(1000.0, $slip['sss'], '5% of ₱20,000');
        $this->assertSame(2030.0, $slip['employer_sss'], '10% of ₱20,000 + ₱30 EC');
    }

    /** An older period must still price at the rate that applied then. */
    public function test_an_older_period_uses_the_older_rate(): void
    {
        $this->sss2026();

        // 2024, not 2025: the seeder carries the published 2025 schedule now (B8), so a 2025
        // cycle correctly prices on that rather than on the opening version.
        $this->assertSame(900.0, $this->slip('2024-06-15')['sss'], '4.5% — the opening rate');
        $this->assertSame(1000.0, $this->slip('2026-03-15')['sss']);
    }

    /** The employer add-on is the company's cost — it must not reach the employee. */
    public function test_the_employer_add_on_never_touches_net_pay(): void
    {
        // Measured against the opening version, before either published schedule applies.
        $before = $this->slip('2024-03-15');
        $this->sss2026();
        $after = $this->slip('2026-03-15');

        $this->assertSame(2030.0, $after['employer_sss']);
        // Employee SSS rises from the rate change alone (900 → 1000); the ₱30 is not in it.
        $this->assertSame(1000.0, $after['sss']);
        $this->assertSame(
            round($before['net_pay'] - 100, 2),
            $after['net_pay'],
            'net moves by the ₱100 rate change only, not ₱130',
        );
    }

    /** Below the threshold the add-on doesn't apply. */
    public function test_the_add_on_respects_its_salary_threshold(): void
    {
        $this->sss2026();
        $this->employee->update(['base_salary' => 12000]); // under ₱15,000

        $this->assertSame(1200.0, $this->slip('2026-03-15')['employer_sss'], '10% of ₱12,000, no EC');
    }

    /** PhilHealth and Pag-IBIG were already correct — they must stay put. */
    public function test_the_other_two_are_unchanged(): void
    {
        $slip = $this->slip('2026-03-15');

        $this->assertSame(500.0, $slip['philhealth'], '2.5% of ₱20,000');
        $this->assertSame(200.0, $slip['pagibig'], '2% capped at ₱200');
    }

    /** The latest version on or before the date wins, whatever order they were entered. */
    public function test_the_latest_applicable_version_wins(): void
    {
        ContributionVersion::create(['type' => 'sss', 'effective_from' => '2027-01-01', 'employee_rate' => 0.06, 'employer_rate' => 0.11]);
        $this->sss2026();

        $this->assertSame(1000.0, $this->slip('2026-06-30')['sss'], 'the 2027 row must not leak back');
        $this->assertSame(1200.0, $this->slip('2027-06-30')['sss']);
    }

    // --- the endpoint ----------------------------------------------------------------

    public function test_hr_can_record_a_dated_rate_change(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'salary_cap' => 35000, 'fixed_employer_add_on' => 30, 'add_on_min_salary' => 15000,
        ])->assertCreated();

        $this->assertSame(1000.0, $this->slip('2026-03-15')['sss']);
    }

    /** The old endpoint took a free-form array, so a typo silently zeroed a deduction. */
    public function test_a_rate_above_one_hundred_percent_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 5, 'employer_rate' => 0.10, // 500% — a decimal slip
        ])->assertStatus(422)->assertJsonValidationErrors('employee_rate');
    }

    public function test_a_floor_above_the_ceiling_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'salary_floor' => 40000, 'salary_cap' => 35000,
        ])->assertStatus(422)->assertJsonValidationErrors('salary_floor');
    }

    public function test_an_add_on_without_a_threshold_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.05, 'employer_rate' => 0.10, 'fixed_employer_add_on' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('add_on_min_salary');
    }

    /** Re-saving the same type+date corrects that version instead of failing on the unique key. */
    public function test_resaving_the_same_date_corrects_it(): void
    {
        $payload = [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
        ];
        $this->hrPost('/api/v1/hr/contribution-versions', $payload)->assertCreated();
        $this->hrPost('/api/v1/hr/contribution-versions', [...$payload, 'employee_rate' => 0.055])->assertCreated();

        $this->assertSame(1, ContributionVersion::where('type', 'sss')->whereDate('effective_from', '2026-01-01')->count());
        $this->assertSame(1100.0, $this->slip('2026-03-15')['sss']);
    }

    public function test_the_history_endpoint_lists_versions(): void
    {
        $this->sss2026();

        $this->actingAsHr()
            ->getJson('/api/v1/hr/contribution-versions?type=sss')
            ->assertOk()
            ->assertJsonPath('data.0.effective_from', '2026-01-01')
            ->assertJsonPath('data.0.employee_rate', 0.05);
    }

    public function test_a_rate_change_is_audited(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
        ])->assertCreated();

        $log = \App\Modules\Hr\Models\AuditLog::where('module', 'Benefits')->latest('id')->firstOrFail();
        $this->assertStringContainsString('SSS', $log->description);
    }

    /** Enrolment still gates it: opt an employee out and the deduction disappears. */
    public function test_an_unenrolled_contribution_is_not_deducted(): void
    {
        app(ContributionService::class)->updateBenefits($this->employee, [
            ['contribution_type' => 'sss', 'enrolled' => false],
        ]);

        $this->assertSame(0.0, $this->slip('2026-03-15')['sss']);
    }
}
