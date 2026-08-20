<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 *  (2026-07-27): payslipFor() must price a day through the policy's daily-rate basis,
 * not always period-workdays. The headline guards are:
 *   - default `period_workdays` reproduces the number (no-op for existing orgs),
 *   - `fixed_divisor` actually changes the daily rate (the gap this slice closes),
 *   - a FULL-attendance period pays exactly perPeriodBase under EITHER basis (base pay is
 *     untouched — the basis only re-prices absences/late), and
 *   - the daily rate is one shared number, so absence and holiday premium can't disagree.
 */
class PayrollDailyRateTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $user = User::factory()->create();
        $this->employee = Employee::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function policy(array $overrides = []): AttendancePolicy
    {
        return new AttendancePolicy(array_merge(
            \App\Modules\Hr\Services\AttendancePolicyService::DEFAULTS,
            $overrides,
        ));
    }

    /** @return array<string,float> */
    private function slip(AttendancePolicy $policy, float $unpaidDays): array
    {
        // 20 000/mo, semi-monthly (24 periods) → perPeriodBase = 10 000; 22 scheduled workdays.
        $dist = ['index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every'];

        return app(PayrollService::class)->payslipFor(
            $this->employee, 0.0, $unpaidDays, 24, 22, $dist, 0.0, null, $policy,
        );
    }

    public function test_default_basis_matches_period_workdays(): void
    {
        // perPeriodBase 10 000 ÷ 22 workdays = 454.55/day (today's behaviour).
        $slip = $this->slip($this->policy(['daily_rate_basis' => 'period_workdays']), 1.0);

        $this->assertEqualsWithDelta(454.55, $slip['daily_rate'], 0.01);
        $this->assertEqualsWithDelta(10000 - 454.55, $slip['base_salary'], 0.01);
    }

    public function test_fixed_divisor_reprices_the_day(): void
    {
        // 20 000 × 12 ÷ 261 = 919.54/day — the DOLE Mon–Fri divisor.
        $slip = $this->slip($this->policy(['daily_rate_basis' => 'fixed_divisor', 'annual_divisor' => 261]), 1.0);

        $this->assertEqualsWithDelta(919.54, $slip['daily_rate'], 0.01);
        $this->assertEqualsWithDelta(10000 - 919.54, $slip['base_salary'], 0.01);
    }

    public function test_fixed_divisor_absence_costs_more_than_period_workdays(): void
    {
        $pw = $this->slip($this->policy(['daily_rate_basis' => 'period_workdays']), 1.0);
        $fd = $this->slip($this->policy(['daily_rate_basis' => 'fixed_divisor', 'annual_divisor' => 261]), 1.0);

        // Same one-day absence costs MORE under the (fewer-day) fixed divisor.
        $this->assertGreaterThan($pw['daily_rate'], $fd['daily_rate']);
        $this->assertLessThan($pw['base_salary'], $fd['base_salary']);
    }

    public function test_full_attendance_pays_perPeriodBase_under_either_basis(): void
    {
        // With zero unpaid days the basis must NOT change base pay — it only re-prices absences.
        $pw = $this->slip($this->policy(['daily_rate_basis' => 'period_workdays']), 0.0);
        $fd = $this->slip($this->policy(['daily_rate_basis' => 'fixed_divisor', 'annual_divisor' => 261]), 0.0);

        $this->assertEqualsWithDelta(10000.0, $pw['base_salary'], 0.01);
        $this->assertEqualsWithDelta(10000.0, $fd['base_salary'], 0.01);
    }
}
