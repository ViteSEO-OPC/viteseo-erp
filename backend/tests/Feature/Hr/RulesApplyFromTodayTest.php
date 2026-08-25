<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Services\AttendancePolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * A rule applies from today onward — never from a date already gone.
 *
 * Backdating was how an old cycle used to be repriced: date the change inside it, re-run, done.
 * A period's rules now seal when the period ends, and a period priced wrongly is fixed with a
 * payslip correction instead. Scheduling a change AHEAD still works; only the past is refused.
 */
class RulesApplyFromTodayTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 01:00 in Manila is still the PREVIOUS day in UTC, which is the whole point of the two
        // cases at the bottom: the app clock is UTC and the rule has to read Manila's calendar.
        $this->travelTo(CarbonImmutable::parse('2026-08-24 01:00:00', 'Asia/Manila'));

        app(AttendancePolicyService::class)->get();
        PayrollSetting::current();
    }

    /** @param array<string, mixed> $payload */
    private function savePayroll(array $payload): TestResponse
    {
        return $this->actingAsHr()->putJson('/api/v1/hr/payroll/settings', [
            'frequency' => 'semi_monthly',
            ...$payload,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function savePolicy(array $payload): TestResponse
    {
        return $this->actingAsHr()->putJson('/api/v1/hr/attendance-policy', $payload);
    }

    public function test_payroll_settings_refuse_a_past_effective_date(): void
    {
        $this->savePayroll(['pay_delay_days' => 3, 'effective_from' => '2026-07-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');

        $this->assertSame(0, PayrollSetting::current()->pay_delay_days, 'nothing was written');
    }

    public function test_attendance_rules_refuse_a_past_effective_date(): void
    {
        $this->savePolicy(['annual_divisor' => 313, 'effective_from' => '2026-07-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');

        $this->assertSame(261, AttendancePolicy::first()->annual_divisor, 'nothing was written');
    }

    public function test_today_is_accepted_by_both(): void
    {
        $this->savePayroll(['pay_delay_days' => 3, 'effective_from' => '2026-08-24'])->assertOk();
        $this->savePolicy(['annual_divisor' => 313, 'effective_from' => '2026-08-24'])->assertOk();
    }

    /** The UI sends no date at all now; that has to keep meaning "today". */
    public function test_omitting_the_date_is_accepted_by_both(): void
    {
        $this->savePayroll(['pay_delay_days' => 3])->assertOk();
        $this->savePolicy(['annual_divisor' => 313])->assertOk();

        $this->assertSame(3, PayrollSetting::current()->pay_delay_days);
    }

    /** Scheduling a change ahead of time was never the problem, and still works. */
    public function test_a_future_date_is_still_accepted_by_both(): void
    {
        $this->savePayroll(['pay_delay_days' => 3, 'effective_from' => '2026-12-01'])->assertOk();
        $this->savePolicy(['annual_divisor' => 313, 'effective_from' => '2026-12-01'])->assertOk();
    }

    public function test_a_benefit_programme_refuses_a_past_effective_date(): void
    {
        $this->actingAsHr()->postJson('/api/v1/hr/benefit-programs', [
            'name' => 'Meal Allowance', 'kind' => 'allowance', 'category' => 'allowance',
            'amount' => 2000, 'tax_treatment' => 'de_minimis', 'eligibility' => 'all',
            'effective_from' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors('effective_from');
    }

    /** The allowance modal sends no date now, and a programme created without one still saves. */
    public function test_a_benefit_programme_saves_with_no_effective_date(): void
    {
        $this->actingAsHr()->postJson('/api/v1/hr/benefit-programs', [
            'name' => 'Meal Allowance', 'kind' => 'allowance', 'category' => 'allowance',
            'amount' => 2000, 'tax_treatment' => 'de_minimis', 'eligibility' => 'all',
        ])->assertSuccessful();

        // No `frequency` is sent either — the column's own default has to make it monthly, or
        // the amount would be denominated as something the form never asked about.
        $this->assertSame('monthly', BenefitProgram::firstOrFail()->frequency);
    }

    /**
     * MANILA, NOT UTC. At 01:00 Manila the UTC date is still the 23rd, so a rule reading the
     * app clock would wave this through as "today" — and quietly backdate a whole day.
     */
    public function test_manila_yesterday_is_refused_even_though_it_is_utc_today(): void
    {
        $this->assertSame('2026-08-23', CarbonImmutable::now('UTC')->toDateString(), 'fixture check');

        $this->savePayroll(['pay_delay_days' => 3, 'effective_from' => '2026-08-23'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');

        $this->savePolicy(['annual_divisor' => 313, 'effective_from' => '2026-08-23'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
    }
}
