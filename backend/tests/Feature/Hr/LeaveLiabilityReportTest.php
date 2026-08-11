<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * MF-11 — the peso value of unused paid leave, which was nowhere on the books.
 *
 * It is a liability, not a statistic. Unused paid leave becomes cash the moment somebody leaves —
 * `runFinalPay()` converts it at the daily rate — and for the five-day Service Incentive Leave the
 * Labour Code requires that conversion whether the employee asks or not (Art. 95). So the company owes
 * it now, and a balance sheet without it understates payroll obligations by the whole accrued balance.
 * The existing Absences report even promised "liability accrual" in its own description and computed
 * none.
 *
 * THE TEST THAT MATTERS IS `test_it_agrees_with_what_final_pay_actually_converts`. The report values
 * leave through `PayrollService::leaveLiabilityFor()`, which is built on the same two functions final
 * pay uses. A report that did its own arithmetic would eventually disagree with the payslip it is
 * predicting, and accounting would accrue one figure while payroll paid another — with nothing to say
 * which was wrong.
 */
class LeaveLiabilityReportTest extends HrTestCase
{
    use RefreshDatabase;

    private LeaveType $paid;

    private LeaveType $unpaid;

    private Department $ops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $this->ops = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->paid = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);
        $this->unpaid = LeaveType::create(['name' => 'LWOP', 'code' => 'LWOP', 'is_paid' => false, 'is_unlimited' => true]);
    }

    private function employee(string $no = 'E-001', float $salary = 30000, ?Department $dept = null): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => ($dept ?? $this->ops)->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'employment_status' => 'regular',
            'base_salary' => $salary, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function balance(Employee $e, LeaveType $type, float $entitled, float $used = 0): void
    {
        LeaveBalance::create([
            'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'year' => 2026, 'entitled_days' => $entitled, 'used_days' => $used,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(array $filters = []): array
    {
        return app(ReportService::class)->get('leave_liability', $filters);
    }

    // --- the figure -----------------------------------------------------------------------------

    public function test_it_values_unused_paid_leave_at_the_daily_rate(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->paid, entitled: 10, used: 4);

        $liability = app(PayrollService::class)->leaveLiabilityFor($employee);

        $this->assertSame(6.0, $liability['days'], '10 entitled − 4 used');
        $this->assertGreaterThan(0, $liability['daily_rate']);
        $this->assertSame(
            round(6 * $liability['daily_rate'], 2),
            $liability['amount'],
        );
    }

    /**
     * THE ONE THAT KEEPS THE BOOKS HONEST. Whatever the report accrues must be what final pay
     * actually pays out, because the report exists to predict that payslip.
     */
    public function test_it_agrees_with_what_final_pay_actually_converts(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->paid, entitled: 10, used: 4);

        $accrued = app(PayrollService::class)->leaveLiabilityFor($employee)['amount'];

        // Now actually separate and pay them out on the same day the accrual was priced.
        app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-20',
        ]);

        $run = app(PayrollService::class)->runFinalPay(
            Employee::withTrashed()->find($employee->id),
            ['reason' => 'Resignation'],
            $this->hrUser(),
        );

        $slip = $run->payslips()->firstOrFail();

        $this->assertSame(6.0, (float) $slip->leave_conversion_days);
        $this->assertSame(
            $accrued,
            (float) $slip->leave_conversion,
            'the accrual and the payout must be the same number',
        );
    }

    /** Unpaid leave costs nothing to not take, so it is not an obligation. */
    public function test_unpaid_leave_types_are_excluded(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->unpaid, entitled: 15);

        $this->assertSame(0.0, app(PayrollService::class)->leaveLiabilityFor($employee)['days']);
        $this->assertSame(0, count($this->report()['table']['rows']));
    }

    /** An over-taken balance is the employee owing time, not the company owing money. */
    public function test_a_negative_balance_never_becomes_a_negative_liability(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->paid, entitled: 5, used: 8);

        $this->assertSame(0.0, app(PayrollService::class)->leaveLiabilityFor($employee)['amount']);
    }

    /** A bigger salary on the same balance is a bigger obligation. */
    public function test_the_liability_scales_with_salary(): void
    {
        $lower = $this->employee('E-001', 30000);
        $higher = $this->employee('E-002', 60000);
        $this->balance($lower, $this->paid, entitled: 10);
        $this->balance($higher, $this->paid, entitled: 10);

        $service = app(PayrollService::class);
        $this->assertGreaterThan(
            $service->leaveLiabilityFor($lower)['amount'],
            $service->leaveLiabilityFor($higher)['amount'],
        );
    }

    // --- the report -----------------------------------------------------------------------------

    public function test_the_report_totals_every_balance(): void
    {
        $a = $this->employee('E-001');
        $b = $this->employee('E-002');
        $this->balance($a, $this->paid, entitled: 10);
        $this->balance($b, $this->paid, entitled: 5);

        $report = $this->report();

        $this->assertSame('Leave Liability', $report['title']);
        $this->assertSame('15.0', $report['summary'][1]['value'], 'unused paid days');
        $this->assertSame('2', $report['summary'][2]['value'], 'employees with a balance');
        $this->assertCount(2, $report['table']['rows']);
    }

    /** Largest exposure first — the report exists to show which balances are worth acting on. */
    public function test_rows_are_ordered_by_liability(): void
    {
        $small = $this->employee('E-001');
        $large = $this->employee('E-002');
        $this->balance($small, $this->paid, entitled: 2);
        $this->balance($large, $this->paid, entitled: 20);

        $rows = $this->report()['table']['rows'];

        $this->assertSame($large->user->name, $rows[0][0]);
    }

    /** Nobody with a zero balance should pad the table — an all-zero list hides the signal. */
    public function test_employees_with_no_balance_are_omitted(): void
    {
        $with = $this->employee('E-001');
        $this->employee('E-002'); // no balance at all
        $this->balance($with, $this->paid, entitled: 10);

        $this->assertCount(1, $this->report()['table']['rows']);
    }

    /**
     * A former employee's balance was already settled by their final pay, so counting it here
     * would accrue the same obligation twice.
     */
    public function test_former_employees_are_excluded(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->paid, entitled: 10);

        app(EmployeeService::class)->separate($employee, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-20',
        ]);

        $this->assertCount(0, $this->report()['table']['rows']);
    }

    public function test_it_can_be_filtered_by_department(): void
    {
        $other = Department::create(['name' => 'Finance', 'code' => 'FIN']);
        $ops = $this->employee('E-001', 30000, $this->ops);
        $fin = $this->employee('E-002', 30000, $other);
        $this->balance($ops, $this->paid, entitled: 10);
        $this->balance($fin, $this->paid, entitled: 10);

        $filtered = $this->report(['department' => $other->id]);

        $this->assertCount(1, $filtered['table']['rows']);
        $this->assertSame('Finance', $filtered['table']['rows'][0][1]);
    }

    /**
     * The `bars` contract, pinned. `{title, items:[{label,value,display}]}` is what `ReportBars`
     * reads; the `{series, points}` shape belongs to `stacked` and renders nothing here — a mismatch
     * that fails silently as an empty panel rather than an error.
     */
    public function test_the_department_chart_matches_the_bars_contract(): void
    {
        $other = Department::create(['name' => 'Finance', 'code' => 'FIN']);
        $this->balance($this->employee('E-001', 30000, $this->ops), $this->paid, entitled: 4);
        $this->balance($this->employee('E-002', 30000, $other), $this->paid, entitled: 20);

        $bars = $this->report()['bars'];

        $this->assertArrayHasKey('title', $bars);
        $this->assertArrayHasKey('items', $bars);
        $this->assertSame(['label', 'value', 'display'], array_keys($bars['items'][0]));
        $this->assertIsInt($bars['items'][0]['value'], 'ReportBars divides by this');
        $this->assertSame('Finance', $bars['items'][0]['label'], 'largest department first');
    }

    /** The note has to say it is an estimate — no report can know a future raise. */
    public function test_the_report_states_that_it_is_an_estimate(): void
    {
        $this->assertStringContainsString('estimate', strtolower((string) $this->report()['note']));
    }

    // --- the HTTP surface -----------------------------------------------------------------------

    public function test_hr_can_read_the_report(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->paid, entitled: 10);

        $this->actingAs($this->hrUser())
            ->getJson('/api/v1/hr/reports/leave_liability')
            ->assertOk()
            ->assertJsonPath('data.title', 'Leave Liability');
    }

    public function test_an_employee_cannot_read_the_report(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)
            ->getJson('/api/v1/hr/reports/leave_liability')
            ->assertForbidden();
    }

    /** It exports as CSV, not PDF — accounting reconciles rows, it doesn't read a chart. */
    public function test_it_exports_as_csv(): void
    {
        $employee = $this->employee();
        $this->balance($employee, $this->paid, entitled: 10);

        $this->assertSame('csv', app(ReportService::class)->exportFormat('leave_liability'));

        $this->actingAs($this->hrUser())
            ->postJson('/api/v1/hr/reports/leave_liability/export')
            ->assertSuccessful();
    }
}
