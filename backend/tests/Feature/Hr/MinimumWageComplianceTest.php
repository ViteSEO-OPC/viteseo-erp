<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\PayScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Turning the minimum-wage warning into something that can actually be acted on. */
class MinimumWageComplianceTest extends HrTestCase
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
            'user_id' => User::factory()->create(['name' => 'Rina Cruz'])->id,
            'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    private function setFloor(float $amount): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['minimum_daily_wage' => $amount]);
    }

    private function useFixedDivisor(int $divisor): void
    {
        app(AttendancePolicyService::class)->update([
            'daily_rate_basis' => 'fixed_divisor',
            'annual_divisor' => $divisor,
        ]);
    }

    /**
     * @return array{start: string, end: string, pay_date: string}
     */
    private function currentPeriod(): array
    {
        return app(PayScheduleService::class)
            ->currentPeriodFor('semi_monthly', PayrollSetting::current()->anchor_date);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(): array
    {
        return app(PayrollService::class)->minimumWageCheckFor($this->employee->fresh());
    }

    // --- the divisor is what decides which verdict applies -----------------------------------

    public function test_the_same_salary_passes_on_261_and_fails_on_365(): void
    {
        $this->setFloor(750);

        // 365 — the salary covers every calendar day, so one day is worth 240,000 ÷ 365.
        $this->useFixedDivisor(365);
        $over365 = $this->check();

        $this->assertSame(657.53, $over365['daily_rate']);
        $this->assertTrue($over365['below'], '₱20,000 over 365 days is below a ₱750 floor');

        // 261 — the salary covers workdays only, so the same money buys fewer, dearer days.
        $this->useFixedDivisor(261);
        $over261 = $this->check();

        $this->assertSame(919.54, $over261['daily_rate']);
        $this->assertFalse($over261['below'], '₱20,000 over 261 days clears a ₱750 floor');
    }

    /** The rate is the point of the field, and it is needed before anyone enters a wage order. */
    public function test_the_daily_rate_is_reported_with_its_basis_even_with_no_floor(): void
    {
        $this->useFixedDivisor(365);

        $check = $this->check();

        $this->assertNull($check['minimum']);
        $this->assertFalse($check['below']);
        $this->assertSame(657.53, $check['daily_rate']);
        $this->assertSame('fixed_divisor', $check['basis']);
        $this->assertSame(365, $check['divisor']);
    }

    // --- the proposal has to actually clear the floor ------------------------------------------

    /**
     * The one outcome an adjustment must never have is a raise that leaves the person still
     * non-compliant, so the proposal is rounded UP to the centavo and round-trips through the same
     * arithmetic that produced the breach.
     */
    public function test_the_proposed_salary_clears_the_floor_when_applied(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);

        $proposed = $this->check()['compliant_salary'];
        $this->assertSame(22812.50, $proposed);

        $this->employee->update(['base_salary' => $proposed]);

        $after = $this->check();
        $this->assertFalse($after['below'], 'the proposed salary did not clear the floor');
        $this->assertGreaterThanOrEqual(750.0, $after['daily_rate']);
    }

    // --- exemptions ---------------------------------------------------------------------------

    /**
     * `below` stays the arithmetic truth; `flagged` is what the lists and the run gate read. A
     * warning that is permanently wrong for the same part-timers is one everybody scrolls past.
     */
    public function test_an_exempt_employee_is_below_but_not_flagged(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);
        $this->employee->update([
            'minimum_wage_exempt' => true,
            'minimum_wage_exempt_reason' => 'Part-time, 3 days a week',
        ]);

        $check = $this->check();

        $this->assertTrue($check['below']);
        $this->assertFalse($check['flagged']);
        $this->assertSame('Part-time, 3 days a week', $check['exempt_reason']);
    }

    public function test_marking_someone_exempt_requires_a_reason(): void
    {
        $this->actingAsHr()
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}", ['minimum_wage_exempt' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minimum_wage_exempt_reason');
    }

    /** Clearing the flag clears the note — a stale justification reads as a live one. */
    public function test_clearing_the_exemption_clears_its_reason(): void
    {
        $this->employee->update([
            'minimum_wage_exempt' => true,
            'minimum_wage_exempt_reason' => 'Part-time',
        ]);

        $this->actingAsHr()
            ->patchJson("/api/v1/hr/employees/{$this->employee->id}", ['minimum_wage_exempt' => false])
            ->assertOk();

        $this->assertFalse((bool) $this->employee->fresh()->minimum_wage_exempt);
        $this->assertNull($this->employee->fresh()->minimum_wage_exempt_reason);
    }

    // --- the pre-run review --------------------------------------------------------------------

    public function test_the_review_lists_who_the_run_would_pay_below_the_floor(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);
        $period = $this->currentPeriod();

        $response = $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/minimum-wage-check?frequency=semi_monthly'
                . "&cycle_start={$period['start']}&cycle_end={$period['end']}")
            ->assertOk();

        $response->assertJsonPath('meta.flagged_count', 1);
        $response->assertJsonPath('meta.minimum', 750);
        $response->assertJsonPath('data.0.employee.name', 'Rina Cruz');
        $response->assertJsonPath('data.0.compliant_salary', 22812.5);
    }

    public function test_the_review_is_empty_when_no_floor_is_configured(): void
    {
        $this->useFixedDivisor(365);

        $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/minimum-wage-check?frequency=semi_monthly')
            ->assertOk()
            ->assertJsonPath('meta.flagged_count', 0)
            ->assertJsonPath('meta.minimum', null);
    }

    // --- the run gate --------------------------------------------------------------------------

    public function test_payroll_refuses_to_run_past_an_unacknowledged_breach(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);
        $period = $this->currentPeriod();

        $this->actingAsHr()
            ->postJson('/api/v1/hr/payroll/run', [
                'frequency' => 'semi_monthly',
                'cycle_start' => $period['start'],
                'cycle_end' => $period['end'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minimum_wage_ack');
    }

    /** A speed bump, not a wall: every reason the check cannot see is still a reason to proceed. */
    public function test_an_acknowledged_breach_lets_the_run_through(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);
        $period = $this->currentPeriod();

        $this->actingAsHr()
            ->postJson('/api/v1/hr/payroll/run', [
                'frequency' => 'semi_monthly',
                'cycle_start' => $period['start'],
                'cycle_end' => $period['end'],
                'minimum_wage_ack' => true,
                'minimum_wage_ack_reason' => 'Regional wage order does not cover this branch',
            ])
            ->assertJsonMissingValidationErrors('minimum_wage_ack');
    }

    public function test_acknowledging_without_a_reason_is_refused(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);
        $period = $this->currentPeriod();

        $this->actingAsHr()
            ->postJson('/api/v1/hr/payroll/run', [
                'frequency' => 'semi_monthly',
                'cycle_start' => $period['start'],
                'cycle_end' => $period['end'],
                'minimum_wage_ack' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minimum_wage_ack_reason');
    }

    /** Nothing changes until somebody enters a wage order — no guessed legal threshold. */
    public function test_no_configured_floor_does_not_gate_the_run(): void
    {
        $this->useFixedDivisor(365);
        $period = $this->currentPeriod();

        $this->actingAsHr()
            ->postJson('/api/v1/hr/payroll/run', [
                'frequency' => 'semi_monthly',
                'cycle_start' => $period['start'],
                'cycle_end' => $period['end'],
            ])
            ->assertJsonMissingValidationErrors('minimum_wage_ack');
    }

    // --- applying the adjustment ---------------------------------------------------------------

    public function test_applying_the_adjustment_records_a_dated_salary_change(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);

        $this->actingAsHr()
            ->postJson('/api/v1/hr/payroll/minimum-wage-adjustments', [
                'employee_ids' => [$this->employee->id],
                'effective_date' => '2026-08-01',
                'reason' => 'Wage Order NCR-27',
                'acknowledge_repricing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.applied.0.from', 20000)
            ->assertJsonPath('data.applied.0.to', 22812.5);

        $this->assertSame(22812.50, (float) $this->employee->fresh()->base_salary);

        $history = SalaryHistory::where('employee_id', $this->employee->id)->latest('id')->first();
        $this->assertSame(22812.50, (float) $history->base_salary);
        $this->assertSame('2026-08-01', $history->effective_date->toDateString());
        $this->assertSame('Wage Order NCR-27', $history->reason);
    }

    /**
     * Re-checked at apply time rather than trusted from the screen: the list may have been open a
     * while, and raising someone already compliant is a raise nobody decided to give.
     */
    public function test_an_already_compliant_employee_is_skipped(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(261); // ₱919.54/day — clears the floor

        $this->actingAsHr()
            ->postJson('/api/v1/hr/payroll/minimum-wage-adjustments', [
                'employee_ids' => [$this->employee->id],
                'effective_date' => '2026-08-01',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.applied')
            ->assertJsonCount(1, 'data.skipped');

        $this->assertSame(20000.0, (float) $this->employee->fresh()->base_salary);
    }

    /** After the raise the person is no longer flagged — the loop actually closes. */
    public function test_the_adjustment_clears_the_flag(): void
    {
        $this->setFloor(750);
        $this->useFixedDivisor(365);

        $this->actingAsHr()->postJson('/api/v1/hr/payroll/minimum-wage-adjustments', [
            'employee_ids' => [$this->employee->id],
            'effective_date' => '2026-08-01',
            'acknowledge_repricing' => true,
        ])->assertOk();

        $this->assertFalse($this->check()['flagged']);
    }
}
