<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Moving which cutoff the allowance lands on, PART WAY THROUGH a month.
 *
 * Unlike a statutory contribution — whose last cutoff settles up against what the month has
 * already taken — an allowance is decided per cutoff and never reconciled. So a mid-month switch
 * mis-pays every time, which is why the endpoint refuses one.
 */
class AllowanceTimingSwitchTest extends HrTestCase
{
    use RefreshDatabase;

    /** The monthly allowance every case below is measured against. */
    private const MONTHLY = 4000.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        // Pinned inside August so "this month" means the fixture's month, not the wall clock —
        // otherwise every case here changes meaning on the 1st of a month.
        $this->travelTo('2026-08-20 09:00:00');

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);

        BenefitProgram::create([
            'name' => 'Meal Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => self::MONTHLY, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => 'all',
        ]);

        Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'employment_status' => 'regular',
        ]);

        // The rules this company has always had: paid semi-monthly, allowance split evenly.
        PayrollSetting::effectiveFrom(PayrollSetting::OPENING_DATE);
        PayrollSetting::current()->update(['frequency' => 'semi_monthly', 'allowance_timing' => 'every']);
    }

    /** Allowance paid by a real run of one cutoff. */
    private function runCycle(string $start, string $end): float
    {
        $run = app(PayrollService::class)->run($start, $end, 'semi_monthly', $this->hrUser());

        return (float) Payslip::where('payroll_run_id', $run->id)->value('allowances');
    }

    /** @param array<string, mixed> $payload */
    private function saveSettings(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsHr()->putJson('/api/v1/hr/payroll/settings', [
            'frequency' => 'semi_monthly', // required by the endpoint on every save
            ...$payload,
        ]);
    }

    /**
     * WHY THE GUARD EXISTS — the engine itself, with the endpoint's validation bypassed.
     *
     * Half a month on the old rule, then a WHOLE month on the new one.
     */
    public function test_a_mid_month_switch_would_pay_the_allowance_one_and_a_half_times(): void
    {
        $first = $this->runCycle('2026-08-01', '2026-08-15');

        // Straight to the model, the way nothing in the app is allowed to any more.
        PayrollSetting::effectiveFrom('2026-08-16');
        PayrollSetting::current()->update(['allowance_timing' => 'last']);

        $second = $this->runCycle('2026-08-16', '2026-08-31');

        $this->assertSame(2000.0, $first, 'the first cutoff paid half, under the old rule');
        $this->assertSame(self::MONTHLY, $second, 'the second paid a WHOLE month, under the new one');
        $this->assertSame(6000.0, $first + $second, 'so the month paid 1.5x the allowance');
    }

    /** THE GUARD: once a cutoff of this month has run, the setting is frozen for the month. */
    public function test_the_endpoint_refuses_a_switch_once_a_cutoff_of_the_month_has_run(): void
    {
        $this->runCycle('2026-08-01', '2026-08-15');

        $this->saveSettings(['allowance_timing' => 'last'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allowance_timing');

        $this->assertSame('every', PayrollSetting::current()->allowance_timing, 'nothing was written');
    }

    /** Every direction mis-pays, so every direction is refused — not just the one that overpays. */
    public function test_the_refusal_covers_the_directions_that_underpay_too(): void
    {
        $this->runCycle('2026-08-01', '2026-08-15');

        foreach (['first', 'last'] as $timing) {
            $this->saveSettings(['allowance_timing' => $timing])
                ->assertStatus(422)
                ->assertJsonValidationErrors('allowance_timing');
        }
    }

    /** Nothing has been paid for the month yet, so the switch is free to happen. */
    public function test_the_switch_is_allowed_while_the_month_is_untouched(): void
    {
        $this->saveSettings(['allowance_timing' => 'last'])->assertOk();

        $this->assertSame('last', PayrollSetting::current()->allowance_timing);
    }

    /** LAST month's cutoffs are none of this month's business. */
    public function test_a_cutoff_of_a_previous_month_does_not_block_the_switch(): void
    {
        $this->runCycle('2026-07-01', '2026-07-15');
        $this->runCycle('2026-07-16', '2026-07-31');

        $this->saveSettings(['allowance_timing' => 'last'])->assertOk();
    }

    /** The guard is about MOVING the setting — re-sending the value it already holds is not that. */
    public function test_resending_the_same_timing_is_not_a_change(): void
    {
        $this->runCycle('2026-08-01', '2026-08-15');

        $this->saveSettings(['allowance_timing' => 'every'])->assertOk();
    }

    /** It guards ONE setting. A part-run month must not freeze the whole settings page. */
    public function test_the_other_settings_still_save_mid_month(): void
    {
        $this->runCycle('2026-08-01', '2026-08-15');

        $this->saveSettings(['minimum_daily_wage' => 645, 'pay_delay_days' => 3])->assertOk();

        $this->assertSame(3, PayrollSetting::current()->pay_delay_days);
    }
}
