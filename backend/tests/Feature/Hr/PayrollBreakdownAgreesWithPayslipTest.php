<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * THE PAYROLL SCREEN AND THE PAYSLIP MUST DESCRIBE THE SAME PERIOD.
 *
 * They did not. The stored `gross` column is already net of the absence charge — payroll subtracts
 * it before writing the row — and `total_deductions` therefore holds only what came off AFTER that
 * point. The run breakdown printed both raw, so an employee who lost five figures to absences read
 * as "Gross ₱69,889.20 · Deductions ₱2,162.50": a near-full-pay period with the largest single
 * thing that happened to it nowhere on the row, while their payslip showed the charge in full.
 *
 * Neither figure was wrong. They were two different cuts of the same money presented as the same
 * quantity, which is the worst of both — an HR officer reconciling the screen against the document
 * finds two numbers that disagree and no statement of why.
 *
 * The breakdown now states gross the way the document does, and these tests pin the three ways that
 * can silently come apart again: the row against the payslip, the row against itself, and the
 * summary cards against the column they sit above.
 */
class PayrollBreakdownAgreesWithPayslipTest extends HrTestCase
{
    use RefreshDatabase;

    private const START = '2026-03-01';

    private const END = '2026-03-15';

    private Employee $employee;

    private User $employeeUser;

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
        $this->employeeUser = User::factory()->create();
        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** Attendance for the cycle, minus its first `$absentDays` workdays. */
    private function attendance(int $absentDays): void
    {
        $tz = config('app.timezone_local');
        $end = Carbon::parse(self::END, $tz);
        $skipped = 0;

        for ($d = Carbon::parse(self::START, $tz); $d->lte($end); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }

            if ($skipped < $absentDays) {
                $skipped++;

                continue;
            }

            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runPayroll(): Payslip
    {
        app(PayrollService::class)->run(self::START, self::END, 'semi_monthly', $this->hrUser());

        return Payslip::where('employee_id', $this->employee->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function runDetail(Payslip $payslip): array
    {
        $this->actingAsHr();

        return $this->getJson("/api/v1/hr/payroll/runs/{$payslip->payroll_run_id}")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> */
    private function document(Payslip $payslip): array
    {
        Sanctum::actingAs($this->employeeUser);

        return $this->getJson("/api/v1/hr/me/payslips/{$payslip->id}")
            ->assertOk()
            ->json('data');
    }

    /**
     * The row and the document, side by side — on a period with a large absence charge, which is
     * the only case where the old and new definitions differ at all.
     */
    public function test_the_breakdown_row_states_the_same_figures_as_the_payslip(): void
    {
        $this->attendance(absentDays: 4);
        $payslip = $this->runPayroll();

        $doc = $this->document($payslip);
        $row = collect($this->runDetail($payslip)['breakdown'])->firstWhere('id', $payslip->id);

        $this->assertGreaterThan(0.0, $doc['absence_deduction'], 'The fixture must actually charge absences.');

        // Gross is the document's full compensation — earnings BEFORE the absence charge.
        $this->assertEqualsWithDelta($doc['totals']['compensation'], $row['gross'], 0.01,
            'The breakdown states a different gross than the payslip.');

        // Deductions is everything the document took off: the attendance charge AND the statutory side.
        $this->assertEqualsWithDelta(
            $doc['totals']['deductions'] + $doc['totals']['government'],
            $row['deductions'],
            0.01,
            'The breakdown omits deductions the payslip shows.',
        );

        $this->assertEqualsWithDelta($doc['net_pay'], $row['net'], 0.01);
    }

    /** A row that does not reconcile against itself is the defect this replaced. */
    public function test_every_breakdown_row_reconciles(): void
    {
        $this->attendance(absentDays: 4);
        $payslip = $this->runPayroll();

        foreach ($this->runDetail($payslip)['breakdown'] as $row) {
            $this->assertEqualsWithDelta($row['net'], $row['gross'] - $row['deductions'], 0.01,
                "Row for {$row['employee']} does not add up.");
        }
    }

    /**
     * The summary cards sit directly above the column they summarise, so they must BE its sum —
     * restating gross on the rows alone would have broken that on the same screen.
     */
    public function test_the_run_totals_are_the_sum_of_the_breakdown(): void
    {
        $this->attendance(absentDays: 4);
        $detail = $this->runDetail($this->runPayroll());
        $rows = $detail['breakdown'];

        $this->assertNotEmpty($rows);
        $this->assertEqualsWithDelta(array_sum(array_column($rows, 'gross')), $detail['total_gross'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($rows, 'deductions')), $detail['total_deductions'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($rows, 'net')), $detail['total_net'], 0.01);
    }

    /**
     * The runs LIST reads preloaded aggregates while the DETAIL sums rows it already has in memory.
     * Two code paths, one answer — otherwise the totals change as you click into a run.
     */
    public function test_the_list_and_the_detail_report_the_same_totals(): void
    {
        $this->attendance(absentDays: 4);
        $payslip = $this->runPayroll();
        $detail = $this->runDetail($payslip);

        $this->actingAsHr();
        $listed = collect($this->getJson('/api/v1/hr/payroll/runs')->assertOk()->json('data'))
            ->firstWhere('id', $payslip->payroll_run_id);

        $this->assertEqualsWithDelta($detail['total_gross'], $listed['total_gross'], 0.01);
        $this->assertEqualsWithDelta($detail['total_deductions'], $listed['total_deductions'], 0.01);
        $this->assertEqualsWithDelta($detail['total_net'], $listed['total_net'], 0.01);
    }
}
