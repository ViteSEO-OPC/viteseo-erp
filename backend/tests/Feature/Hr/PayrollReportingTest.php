<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Slice 13b (2026-07-28 audit) — display, audit trail and reporting. No pay moves here.
 */
class PayrollReportingTest extends HrTestCase
{
    use RefreshDatabase;

    private const CYCLE_START = '2026-03-01';

    private const CYCLE_END = '2026-03-15';

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

    /** Full attendance for the cycle — without it every day is unpaid and gross is 0. */
    private function workedEveryDay(): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::CYCLE_END, $tz);

        for ($d = Carbon::parse(self::CYCLE_START, $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend() || Attendance::where('employee_id', $this->employee->id)
                ->where('work_date', $d->toDateString())->exists()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runPayroll(): PayrollRun
    {
        return app(PayrollService::class)->run(
            self::CYCLE_START, self::CYCLE_END, 'semi_monthly', User::factory()->create(),
        );
    }

    // --- item 2: one definition of "worked" -----------------------------------------

    public function test_hours_worked_subtracts_only_the_unpaid_meal(): void
    {
        $tz = config('app.timezone_local');
        $row = Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-03-02',
            'time_in' => Carbon::parse('2026-03-02 08:00', $tz)->utc(),
            'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 20,
            'source' => Attendance::SOURCE_MANUAL,
        ]);

        app(\App\Modules\Hr\Services\AttendanceService::class)->adjust(
            $this->employee, '2026-03-02', ['time_in' => '08:00', 'time_out' => '17:00'],
        );

        // 9h clock − 60 min lunch = 8.0. The 20-min PAID break stays inside the paid day; it
        // used to be subtracted here and nowhere else in the module.
        $this->assertSame(8.0, (float) $row->fresh()->hours_worked);
    }

    // --- item 6: employer shares reach the API --------------------------------------

    /**
     * The counterpart contributions HR remits. Since the payslip redesign these are gated to
     * HR rather than served to everyone (see PayslipResource), so this reads them through the
     * HR endpoint; PayslipDocumentTest holds the other half — that the employee can't see them.
     */
    public function test_employer_shares_are_exposed_to_hr_on_the_payslip_resource(): void
    {
        $run = $this->runPayroll();
        $payslip = $run->payslips()->firstOrFail();

        $hr = $this->actingAsHr();

        $hr->getJson("/api/v1/hr/me/payslips/{$payslip->id}")
            ->assertStatus(404); // not the caller's own payslip — ownership still enforced

        $data = $hr->getJson("/api/v1/hr/payslips/{$payslip->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('employer_sss', $data);
        $this->assertArrayHasKey('employer_philhealth', $data);
        $this->assertArrayHasKey('employer_pagibig', $data);
    }

    // --- item 12: money-config edits are audited ------------------------------------

    public function test_attendance_rule_changes_record_what_changed(): void
    {
        app(AttendancePolicyService::class)->update(['overtime_ordinary_pct' => 150]);

        $this->assertDatabaseHas('hr_audit_logs', ['module' => 'Rules']);

        $log = AuditLog::where('module', 'Rules')->latest('id')->firstOrFail();
        $this->assertStringContainsString('overtime_ordinary_pct', $log->description);
        $this->assertSame(125, (int) ($log->before['overtime_ordinary_pct'] ?? 0));
        $this->assertSame(150, (int) ($log->after['overtime_ordinary_pct'] ?? 0));
    }

    public function test_contribution_rate_changes_record_before_and_after(): void
    {
        $setting = ContributionSetting::where('type', 'sss')->firstOrFail();

        app(ContributionService::class)->updateSetting($setting, [
            'config' => ['rate' => 0.09],
        ]);

        $log = AuditLog::where('module', 'Benefits')->latest('id')->firstOrFail();
        $this->assertNotNull($log->before);
        $this->assertSame(0.09, (float) ($log->after['config']['rate'] ?? 0));
    }

    public function test_payroll_settings_changes_are_audited(): void
    {
        $this->actingAsHr()
            ->putJson('/api/v1/hr/payroll/settings', [
                'frequency' => 'monthly',
                'deduction_timing' => 'first',
                'tax_timing' => 'every',
            ])
            ->assertOk();

        $log = AuditLog::where('module', 'Payroll')->latest('id')->firstOrFail();
        $this->assertStringContainsString('frequency', $log->description);
        $this->assertSame('monthly', $log->after['frequency'] ?? null);
    }

    // --- item 13: the tardiness penalty is itemised ---------------------------------

    public function test_the_late_penalty_is_reported_separately_from_absences(): void
    {
        AttendancePolicy::query()->update(['late_penalty_enabled' => true, 'lates_per_deduction' => 1]);

        $tz = config('app.timezone_local');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-03-02',
            'time_in' => Carbon::parse('2026-03-02 10:00', $tz)->utc(),
            'time_out' => Carbon::parse('2026-03-02 17:00', $tz)->utc(),
            'status' => 'late', 'lunch_minutes' => 60, 'source' => Attendance::SOURCE_MANUAL,
        ]);

        $slip = $this->runPayroll()->payslips()->firstOrFail();

        $this->assertSame(1.0, (float) $slip->late_penalty_days);
        // It is part of unpaid_days, just no longer indistinguishable from an absence.
        $this->assertGreaterThanOrEqual(1.0, (float) $slip->unpaid_days);
    }

    // --- item 14: reports never read a mid-recompute run ----------------------------

    public function test_ytd_ignores_a_run_that_is_still_processing(): void
    {
        $this->workedEveryDay();
        $run = $this->runPayroll();
        $settled = app(PayrollService::class)->ytd($this->employee, 2026);
        $this->assertGreaterThan(0, $settled['gross']);

        $run->update(['status' => 'processing']);

        $this->assertSame(0.0, app(PayrollService::class)->ytd($this->employee, 2026)['gross']);
    }

    public function test_thirteenth_month_ignores_a_processing_run(): void
    {
        $this->workedEveryDay();
        $run = $this->runPayroll();
        $this->assertNotEmpty(app(PayrollService::class)->thirteenthMonth(2026));

        $run->update(['status' => 'processing']);

        $this->assertSame([], app(PayrollService::class)->thirteenthMonth(2026));
    }
}
