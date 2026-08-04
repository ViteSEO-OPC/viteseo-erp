<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B7 — a per-employee statutory amount is an OVERRIDE, and the payslip has to say so.
 *
 * Ten profiles carry a peso amount typed into `sss_contribution` and friends. When one is set,
 * payroll uses it and ignores the government rates — but only on the EMPLOYEE side. The employer
 * half is still calculated, so one payslip ends up carrying a pair that exists on no SSS table
 * (₱1,350 employee against ₱3,530 employer), and a remittance built from it cannot be filed.
 *
 * Nothing said so anywhere: three unlabelled peso boxes next to the bank details, and a payslip
 * that presented the mismatch as an ordinary contribution.
 */
class StatutoryOverrideTest extends HrTestCase
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
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /**
     * The employee has to actually be PAID for any of this to mean anything.
     *
     * Without attendance every workday is unpaid, base pay is ₱0, and gross cannot cover the
     * contributions. Before FIN-053 that still left the component columns holding their full
     * computed values, so `$slip->sss` read ₱675 on a payslip that withheld nothing — and these
     * assertions passed against a figure no one was ever charged. Now the columns hold what was
     * collected, so a zero-gross fixture would prove only that ₱0 == ₱0.
     *
     * Same reasoning as `PayrollRunLockTest::workedEveryDay()`, which documents the trap.
     */
    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = \Carbon\Carbon::parse('2026-03-15', $tz);

        for ($d = \Carbon\Carbon::parse('2026-03-01', $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }

            \App\Modules\Hr\Models\Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $d->toDateString(),
                'time_in' => \Carbon\Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => \Carbon\Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => \App\Modules\Hr\Models\Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function payslip(): \App\Modules\Hr\Models\Payslip
    {
        $this->workedEveryDay();

        return app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create())
            ->payslips()->firstOrFail();
    }

    /**
     * Take this employee off one contribution. `benefitsFor()` back-fills a missing row as
     * ENROLLED, so the row has to exist before it is flipped — updating a relation that has no
     * rows yet silently matches nothing.
     */
    private function unenrol(string $type): void
    {
        \App\Modules\Hr\Models\EmployeeBenefit::updateOrCreate(
            ['employee_id' => $this->employee->id, 'contribution_type' => $type],
            ['enrolled' => false],
        );
    }

    // --- the preview the employee form shows --------------------------------------------

    public function test_the_preview_shows_what_the_rates_would_charge(): void
    {
        $this->employee->update(['sss_contribution' => 1350]);

        $rows = collect(app(ContributionService::class)->overridePreviewFor($this->employee))
            ->keyBy('type');

        $this->assertSame(1350.0, $rows['sss']['override']);
        $this->assertGreaterThan(0, $rows['sss']['calculated_employee']);
        $this->assertNotSame(
            $rows['sss']['calculated_employee'],
            $rows['sss']['calculated_employer'],
            'the employer side is computed separately — that mismatch is the point',
        );
        $this->assertNull($rows['philhealth']['override'], 'untouched types report no override');
    }

    /** The preview must agree with what payroll actually charges, or it is worse than nothing. */
    public function test_the_preview_matches_the_payslip_when_there_is_no_override(): void
    {
        $rows = collect(app(ContributionService::class)->overridePreviewFor($this->employee))
            ->keyBy('type');

        $slip = $this->payslip();

        // The payslip carries the per-period figure; the preview is monthly, and this cycle is
        // one of two in the month with `deduction_timing` on every cutoff.
        $this->assertSame(
            $rows['philhealth']['calculated_employee'],
            round((float) $slip->philhealth * 2, 2),
        );
    }

    public function test_a_type_the_employee_is_not_enrolled_in_reports_as_inert(): void
    {
        $this->unenrol('sss');
        $this->employee->update(['sss_contribution' => 1350]);

        $rows = collect(app(ContributionService::class)->overridePreviewFor($this->employee->fresh()))
            ->keyBy('type');

        $this->assertFalse($rows['sss']['enrolled']);
        $this->assertSame(0.0, $rows['sss']['calculated_employee']);
    }

    public function test_the_preview_endpoint_is_reachable(): void
    {
        $this->actingAsHr()
            ->getJson("/api/v1/hr/employees/{$this->employee->id}/statutory-preview")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'sss')
            ->assertJsonStructure(['data' => [['type', 'enrolled', 'override', 'calculated_employee', 'calculated_employer', 'basis']]]);
    }

    // --- the payslip says when an override was in force ----------------------------------

    public function test_the_payslip_records_which_deductions_were_overridden(): void
    {
        $this->employee->update(['sss_contribution' => 1350, 'philhealth_contribution' => 450]);

        $slip = $this->payslip();

        $this->assertSame(['sss', 'philhealth'], $slip->contribution_overrides);
    }

    public function test_a_payslip_with_no_override_records_nothing(): void
    {
        $this->assertNull($this->payslip()->contribution_overrides);
    }

    /** An override on a type they are not enrolled in deducts nothing, so it is not "in force". */
    public function test_an_override_on_an_unenrolled_type_is_not_recorded(): void
    {
        $this->unenrol('sss');
        $this->employee->update(['sss_contribution' => 1350]);

        $this->assertNull($this->payslip()->contribution_overrides);
    }

    /** HR-only, like the employer shares: the employee cannot act on it. */
    public function test_the_flag_is_hidden_from_the_employee_s_own_payslip(): void
    {
        $this->employee->update(['sss_contribution' => 1350]);
        $slip = $this->payslip();

        $this->actingAsHr()
            ->getJson("/api/v1/hr/payslips/{$slip->id}")
            ->assertOk()
            ->assertJsonPath('data.contribution_overrides', ['sss']);

        // The employee reads their own copy through `me/payslips`, which is the document they
        // receive — the flag must not be on it.
        $this->employee->user->assignRole('hr_employee');
        $this->actingAs($this->employee->user)
            ->getJson("/api/v1/hr/me/payslips/{$slip->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.contribution_overrides');
    }

    public function test_the_remittance_csv_names_the_overridden_contributions(): void
    {
        $this->employee->update(['sss_contribution' => 1350]);
        $run = app(PayrollService::class)
            ->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());

        $csv = app(PayrollService::class)->runCsv($run);

        $this->assertStringContainsString('Overridden', $csv, 'the column exists');
        $this->assertStringContainsString('SSS', $csv);
    }

    // --- the override itself still behaves as before --------------------------------------

    /** Regression guard: B7 labels and records the override, it does not change what it pays. */
    public function test_the_override_still_replaces_the_employee_side_only(): void
    {
        $this->employee->update(['sss_contribution' => 1350]);
        $slip = $this->payslip();

        // ₱1,350/month over two cutoffs.
        $this->assertSame(675.0, (float) $slip->sss);
        $this->assertGreaterThan(0, (float) $slip->employer_sss);
        $this->assertNotSame((float) $slip->sss, (float) $slip->employer_sss);
    }
}
