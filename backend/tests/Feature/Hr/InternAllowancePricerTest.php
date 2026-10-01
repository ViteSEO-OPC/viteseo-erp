<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\InternAllowancePricer;
use App\Modules\Hr\Support\InternAllowancePolicy;
use App\Modules\Hr\Support\InternDay;
use App\Modules\Hr\Support\InternPricing;
use App\Modules\Hr\Support\InternRateContext;
use App\Modules\Hr\Support\PayslipDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The intern allowance pricer, on days made up for the purpose. Every rule on Company → Intern
 * Allowance is a company's choice, so each one is exercised here against the peso figure it has to
 * produce.
 */
class InternAllowancePricerTest extends HrTestCase
{
    use RefreshDatabase;

    private int $dayNo = 0;

    // --- fixtures ------------------------------------------------------------------------------

    private function rules(array $overrides = []): InternAllowancePolicy
    {
        return InternAllowancePolicy::fromPayload(
            InternAllowancePolicy::normalise(array_merge(InternAllowancePolicy::DEFAULTS, $overrides))[0],
        );
    }

    private function company(array $overrides = []): AttendancePolicy
    {
        return new AttendancePolicy(array_merge(AttendancePolicyService::DEFAULTS, [
            'daily_rate_basis' => 'period_workdays',
            'premium_regular_holiday_pct' => 200,
            'premium_special_day_pct' => 130,
            'premium_rest_day_pct' => 130,
            'overtime_ordinary_pct' => 125,
            'night_diff_pct' => 10,
            'late_penalty_enabled' => false,
        ], $overrides));
    }

    private function day(string $kind, array $facts = []): InternDay
    {
        return new InternDay(...array_merge([
            'date' => sprintf('2026-09-%02d', ++$this->dayNo),
            'kind' => $kind,
            'isWorkday' => true,
            'scheduledMinutes' => 480,
            'worked' => $kind === InternDay::WORKED,
            'workedMinutes' => $kind === InternDay::WORKED ? 480 : 0,
        ], $facts));
    }

    /** @return list<InternDay> */
    private function worked(int $times, array $facts = []): array
    {
        return array_map(fn () => $this->day(InternDay::WORKED, $facts), range(1, $times));
    }

    private function price(array $days, InternAllowancePolicy $rules, float $amount = 500, array $company = [], string $share = InternRateContext::SHARE_PERIOD, int $periodsPerYear = 24): InternPricing
    {
        return app(InternAllowancePricer::class)->price(
            $days,
            $rules,
            new InternRateContext(
                amount: $amount,
                amountSource: 'default',
                periodsPerYear: $periodsPerYear,
                scheduledWorkdays: count(array_filter($days, fn (InternDay $d) => $d->isWorkday)),
                share: $share,
            ),
            $this->company($company),
        );
    }

    /** @return array<string, float> charge pesos by kind */
    private function chargesByKind(InternPricing $pricing): array
    {
        $out = [];
        foreach ($pricing->charges as $charge) {
            $out[$charge['kind']] = round(($out[$charge['kind']] ?? 0) + $charge['amount'], 2);
        }

        return $out;
    }

    // --- per day ----------------------------------------------------------------------------------

    public function test_a_daily_allowance_pays_every_scheduled_day(): void
    {
        $pricing = $this->price($this->worked(10), $this->rules());

        $this->assertSame(5000.0, $pricing->periodBase);
        $this->assertSame(5000.0, $pricing->netAllowance());
        $this->assertSame('10 days × ₱500.00', $pricing->note);
    }

    public function test_an_absent_day_pays_nothing(): void
    {
        $pricing = $this->price([...$this->worked(10), $this->day(InternDay::ABSENT)], $this->rules());

        $this->assertSame(5500.0, $pricing->periodBase);
        $this->assertSame(['absent' => 500.0], $this->chargesByKind($pricing));
        $this->assertSame(5000.0, $pricing->netAllowance());
    }

    public function test_absence_can_be_left_unpriced(): void
    {
        $pricing = $this->price([$this->day(InternDay::ABSENT)], $this->rules(['absence_rule' => 'no_deduction']));

        $this->assertSame([], $pricing->charges);
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function halfDayRules(): array
    {
        return [
            'half the allowance' => ['pay_50', 250.0],
            'the hours actually worked' => ['actual_hours', 312.5], // 3h of 8h worked → 5h unpaid
            'no allowance' => ['no_allowance', 500.0],
            'the full allowance' => ['pay_full', 0.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('halfDayRules')]
    public function test_a_half_day_is_priced_by_the_half_day_rule(string $rule, float $charge): void
    {
        $day = $this->day(InternDay::HALF_DAY, ['halves' => 1, 'worked' => true, 'workedMinutes' => 180]);

        $pricing = $this->price([$day], $this->rules(['half_day_rule' => $rule]));

        $this->assertEquals($charge, $pricing->chargeTotal());
    }

    // --- attendance rules -------------------------------------------------------------------------

    public function test_lates_cost_nothing_unless_a_rule_says_so(): void
    {
        $pricing = $this->price($this->worked(1, ['lateMinutes' => 45, 'lateChargeMinutes' => 45]), $this->rules());

        $this->assertSame([], $pricing->charges);
        $this->assertSame(45, $pricing->lateMinutes);
    }

    public function test_a_late_can_cost_the_missed_hours(): void
    {
        $pricing = $this->price(
            $this->worked(1, ['lateMinutes' => 60, 'lateChargeMinutes' => 60]),
            $this->rules(['late_rule' => 'missed_hours']),
        );

        $this->assertSame(['late' => 62.5], $this->chargesByKind($pricing)); // 1h of an 8h ₱500 day
    }

    public function test_a_late_can_cost_a_fixed_amount(): void
    {
        $pricing = $this->price(
            $this->worked(1, ['lateMinutes' => 5, 'lateChargeMinutes' => 5]),
            $this->rules(['late_rule' => 'fixed_amount', 'late_fixed_amount' => 50]),
        );

        $this->assertSame(['late' => 50.0], $this->chargesByKind($pricing));
    }

    public function test_arriving_past_the_partial_day_threshold_prices_the_day_as_partial(): void
    {
        $rules = $this->rules([
            'late_rule' => 'partial_after_minutes',
            'late_partial_after_minutes' => 30,
            'partial_day_pricing' => 'half_day_rule',
            'half_day_rule' => 'pay_50',
        ]);

        $within = $this->price($this->worked(1, ['lateMinutes' => 30, 'lateChargeMinutes' => 30]), $rules);
        $past = $this->price($this->worked(1, ['lateMinutes' => 31, 'lateChargeMinutes' => 31]), $rules);

        $this->assertSame([], $within->charges);
        $this->assertSame(['half_day' => 250.0], $this->chargesByKind($past));
    }

    public function test_undertime_and_an_approved_early_out_follow_the_undertime_rule(): void
    {
        $rules = $this->rules(['undertime_rule' => 'missed_hours', 'early_out_rule' => 'follow_undertime']);

        $pricing = $this->price([
            ...$this->worked(1, ['undertimeMinutes' => 120, 'undertimeChargeMinutes' => 120]),
            ...$this->worked(1, ['earlyOutMinutes' => 240]),
        ], $rules);

        $this->assertSame(['undertime' => 125.0, 'early_out' => 250.0], $this->chargesByKind($pricing));
    }

    /** Paid by the hours rendered, the missed minutes are the whole charge — never charged twice. */
    public function test_hours_based_pay_charges_the_hours_short_and_nothing_else(): void
    {
        $rules = $this->rules([
            'per_day_proration' => 'by_hours_rendered',
            'late_rule' => 'missed_hours', // normalised away
        ]);

        $pricing = $this->price(
            $this->worked(1, ['workedMinutes' => 360, 'lateMinutes' => 120, 'lateChargeMinutes' => 120]),
            $rules,
        );

        $this->assertSame(['undertime' => 125.0], $this->chargesByKind($pricing)); // 2h of 8h
    }

    /**
     * HR marking a day a half day is the whole verdict on it. The morning worked is not also docked
     * as undertime for the afternoon HR excused — the double charge the demo data first turned up.
     */
    public function test_a_half_day_marked_by_hr_is_not_also_charged_undertime(): void
    {
        $day = $this->day(InternDay::HALF_DAY, [
            'halves' => 1, 'byHr' => true, 'worked' => true, 'workedMinutes' => 240,
            'undertimeMinutes' => 360, 'undertimeChargeMinutes' => 300,
        ]);

        $pricing = $this->price([$day], $this->rules(['undertime_rule' => 'missed_hours', 'half_day_rule' => 'pay_50']));

        $this->assertSame(['half_day' => 250.0], $this->chargesByKind($pricing));
    }

    public function test_a_day_never_costs_more_than_the_day(): void
    {
        $rules = $this->rules([
            'half_day_rule' => 'no_allowance',
            'late_rule' => 'fixed_amount',
            'late_fixed_amount' => 100,
        ]);
        $day = $this->day(InternDay::HALF_DAY, ['halves' => 1, 'worked' => true, 'workedMinutes' => 240, 'lateMinutes' => 20, 'lateChargeMinutes' => 20]);

        $this->assertSame(500.0, $this->price([$day], $rules)->chargeTotal());
    }

    public function test_the_company_tardiness_penalty_applies_only_when_the_rules_follow_it(): void
    {
        $days = $this->worked(3, ['lateMinutes' => 10, 'lateChargeMinutes' => 10]);
        $company = ['late_penalty_enabled' => true, 'lates_per_deduction' => 3];

        $this->assertSame([], $this->price($days, $this->rules(), 500, $company)->charges);

        $following = $this->price($days, $this->rules(['late_penalty_rule' => 'follow_company']), 500, $company);
        $this->assertSame(['late_penalty' => 500.0], $this->chargesByKind($following));
    }

    /**
     * HR marking a day a half day is the whole verdict on it — the charge side already skips its
     * late. The penalty counts WHOLE days, so counting that late anyway is how an excused day ends
     * up being the third one that costs an intern a day's allowance.
     */
    public function test_a_late_hr_has_already_excused_does_not_count_toward_the_tardiness_penalty(): void
    {
        $late = ['lateMinutes' => 10, 'lateChargeMinutes' => 10];
        $excused = $this->day(InternDay::HALF_DAY, [
            'halves' => 1, 'byHr' => true, 'worked' => true, 'workedMinutes' => 240,
        ] + $late);

        $charges = $this->chargesByKind($this->price(
            [...$this->worked(2, $late), $excused],
            $this->rules(['late_penalty_rule' => 'follow_company', 'half_day_rule' => 'pay_50']),
            500,
            ['late_penalty_enabled' => true, 'lates_per_deduction' => 3],
        ));

        $this->assertArrayNotHasKey('late_penalty', $charges, 'two countable lates are not three');
        $this->assertSame(250.0, $charges['half_day'], 'the excused day is still priced as a half day');
    }

    /**
     * Pesos come off the exact fraction of a day, not off the ledger's rounded reading of it.
     * 45 min of an 8h ₱500 day is 0.09375 — ₱46.88. Rounding the days to four places first and
     * multiplying after bills ₱46.90, and every charge line could drift a centavo the same way.
     */
    public function test_a_charge_is_priced_from_the_exact_fraction_of_the_day(): void
    {
        $pricing = $this->price(
            $this->worked(1, ['lateMinutes' => 45, 'lateChargeMinutes' => 45]),
            $this->rules(['late_rule' => 'missed_hours']),
        );

        $this->assertSame(['late' => 46.88], $this->chargesByKind($pricing));
    }

    // --- attendance requirement -------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array<string, float>}> */
    public static function belowMinimumRules(): array
    {
        return [
            'no allowance for the day' => ['no_allowance_for_day', ['below_min_attendance' => 500.0]],
            'priced as a half day' => ['treat_as_half_day', ['half_day' => 250.0]],
            'prorated by hours' => ['prorate_by_hours', ['undertime' => 187.5]], // 3h of 8h short
            'flagged only' => ['flag_only', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('belowMinimumRules')]
    public function test_a_day_below_the_minimum_attendance_is_priced_by_its_rule(string $rule, array $charges): void
    {
        $pricing = $this->price(
            $this->worked(1, ['workedMinutes' => 300]), // 62.5% of 8h
            $this->rules(['min_attendance_pct' => 75, 'below_min_attendance' => $rule]),
        );

        $this->assertSame($charges, $this->chargesByKind($pricing));
        if ($rule === 'flag_only') {
            $this->assertSame('below_min_attendance', $pricing->flags[0]['code']);
        }
    }

    // --- non-working days -------------------------------------------------------------------------

    public function test_an_unworked_regular_holiday_is_paid_or_not_by_the_rule(): void
    {
        $holiday = fn () => $this->day(InternDay::HOLIDAY, ['holidayType' => 'regular']);

        $this->assertSame([], $this->price([$holiday()], $this->rules())->charges);
        $this->assertSame(
            ['holiday_unpaid' => 500.0],
            $this->chargesByKind($this->price([$holiday()], $this->rules(['regular_holiday_not_worked' => 'no_allowance']))),
        );
    }

    public function test_a_regular_holiday_after_an_absence_can_be_forfeited(): void
    {
        $days = [$this->day(InternDay::ABSENT), $this->day(InternDay::HOLIDAY, ['holidayType' => 'regular'])];

        $this->assertSame(['absent' => 500.0], $this->chargesByKind($this->price($days, $this->rules())));
        $this->assertSame(
            ['absent' => 500.0, 'holiday_forfeited' => 500.0],
            $this->chargesByKind($this->price($days, $this->rules(['holiday_forfeit_after_absence' => true]))),
        );
    }

    public function test_working_a_holiday_earns_the_premium_the_rules_choose(): void
    {
        $worked = fn () => $this->day(InternDay::HOLIDAY, ['holidayType' => 'regular', 'worked' => true, 'workedMinutes' => 480]);

        $plain = $this->price([$worked()], $this->rules());
        $company = $this->price([$worked()], $this->rules(['regular_holiday_worked' => 'allowance_with_premium']));
        $custom = $this->price([$worked()], $this->rules([
            'regular_holiday_worked' => 'allowance_with_premium',
            'regular_holiday_premium_source' => 'custom',
            'regular_holiday_premium_pct' => 150,
        ]));

        $this->assertSame(0.0, $plain->holidayPay);
        $this->assertSame(500.0, $company->holidayPay); // 200% → the allowance again on top
        $this->assertSame(250.0, $custom->holidayPay);  // 150% → half again on top
        $this->assertSame(200, $company->holidayLines[0]['rate_pct']);
    }

    public function test_a_special_working_day_is_an_ordinary_day_unless_the_rules_say_otherwise(): void
    {
        $day = fn () => $this->day(InternDay::HOLIDAY, ['holidayType' => 'special_working']);

        $ordinary = $this->price([$day()], $this->rules());
        $asHoliday = $this->price([$day()], $this->rules([
            'special_working_day' => 'as_special_holiday',
            'special_holiday_not_worked' => 'allowance',
        ]));

        $this->assertSame(['absent' => 500.0], $this->chargesByKind($ordinary));
        $this->assertSame([], $asHoliday->charges);
    }

    public function test_a_holiday_on_a_rest_day_can_be_paid_without_being_worked(): void
    {
        $pricing = $this->price(
            [$this->day(InternDay::HOLIDAY, ['holidayType' => 'regular', 'isWorkday' => false])],
            $this->rules(['regular_holiday_rest_day_not_worked' => 'allowance']),
        );

        $this->assertSame(500.0, $pricing->holidayPay);
        $this->assertTrue($pricing->holidayLines[0]['rest_day']);
    }

    public function test_a_suspended_day_follows_the_suspension_unless_the_rules_decide(): void
    {
        $unpaid = fn (array $f = []) => $this->day(InternDay::SUSPENSION, ['suspensionPaid' => false] + $f);

        $this->assertSame(['suspended_unpaid' => 500.0], $this->chargesByKind($this->price([$unpaid()], $this->rules())));
        $this->assertSame([], $this->price([$unpaid()], $this->rules(['suspension_not_worked' => 'allowance']))->charges);
        // HR suspending this one person's day without pay is about that person, and wins.
        $this->assertSame(
            ['suspended_unpaid' => 500.0],
            $this->chargesByKind($this->price([$unpaid(['byHr' => true])], $this->rules(['suspension_not_worked' => 'allowance']))),
        );
    }

    public function test_a_worked_rest_day_is_paid_by_the_rest_day_rule(): void
    {
        $restDay = fn () => $this->day(InternDay::REST_DAY, ['isWorkday' => false, 'worked' => true, 'workedMinutes' => 480]);

        $unpaid = $this->price([$restDay()], $this->rules());
        $allowance = $this->price([$restDay()], $this->rules(['rest_day_worked' => 'allowance']));
        $premium = $this->price([$restDay()], $this->rules(['rest_day_worked' => 'allowance_with_premium']));

        $this->assertSame(0.0, $unpaid->restDayPay);
        $this->assertSame('rest_day_work_unpaid', $unpaid->flags[0]['code']);
        $this->assertSame(500.0, $allowance->restDayPay);
        $this->assertSame(650.0, $premium->restDayPay); // company rest-day rate, 130%
    }

    /**
     * A rest-day premium buys the hours actually rendered. Paid by the day — where an ordinary day
     * is NOT prorated by attendance — an intern who clocks half a rest day is owed half the
     * premium, not the whole day's: the rest day is not the allowance they were already owed.
     */
    public function test_a_partly_worked_rest_day_pays_only_for_the_hours_worked(): void
    {
        $restDay = fn (int $minutes) => $this->day(InternDay::REST_DAY, [
            'isWorkday' => false, 'worked' => true, 'workedMinutes' => $minutes,
        ]);
        $rules = $this->rules(['rest_day_worked' => 'allowance_with_premium']);

        $this->assertSame(650.0, $this->price([$restDay(480)], $rules)->restDayPay);
        $this->assertSame(325.0, $this->price([$restDay(240)], $rules)->restDayPay); // half the day, half the premium
    }

    /**
     * Whether a premium day buys the hours rendered or a whole day is the COMPANY's call, not ours:
     * some pay for the hours, some treat any rest-day work as a full day earned.
     */
    public function test_a_premium_day_can_be_worth_a_whole_day_however_much_was_worked(): void
    {
        $halfWorked = fn () => $this->day(InternDay::REST_DAY, [
            'isWorkday' => false, 'worked' => true, 'workedMinutes' => 240,
        ]);
        $rules = fn (string $basis) => $this->rules([
            'rest_day_worked' => 'allowance_with_premium',
            'premium_day_basis' => $basis,
        ]);

        $this->assertSame(325.0, $this->price([$halfWorked()], $rules('hours_rendered'))->restDayPay);
        $this->assertSame(650.0, $this->price([$halfWorked()], $rules('whole_day'))->restDayPay);
    }

    /**
     * HR marking one person's day "not a working day" takes it out of the period entirely. Counted
     * as a working day, a per_day allowance (daily × workingDays) would pay a whole day's
     * allowance for a day nobody was ever scheduled to work.
     */
    public function test_a_day_hr_marks_not_a_working_day_is_not_paid(): void
    {
        $dayOff = $this->day(InternDay::DAY_OFF, ['byHr' => true, 'detail' => 'Marked not a working day by HR']);

        $pricing = $this->price([...$this->worked(10), $dayOff], $this->rules());

        $this->assertSame(5000.0, $pricing->periodBase); // the ten days worked, not eleven
        $this->assertSame([], $pricing->charges);
    }

    public function test_overtime_and_night_minutes_are_paid_only_when_the_rules_pay_them(): void
    {
        $day = fn () => $this->worked(1, ['overtimeMinutes' => 60, 'nightMinutes' => 60])[0];

        $none = $this->price([$day()], $this->rules());
        $paid = $this->price([$day()], $this->rules([
            'overtime_rule' => 'with_premium',
            'night_diff_rule' => 'company_rate',
        ]));

        $this->assertSame(0.0, $none->overtimePay + $none->nightDiffPay);
        $this->assertSame(78.13, $paid->overtimePay);  // ₱62.50/h × 125%
        $this->assertSame(6.25, $paid->nightDiffPay);  // ₱62.50/h × 10%
    }

    // --- leave --------------------------------------------------------------------------------------

    public function test_intern_leave_is_unpaid_unless_the_rules_follow_the_leave_type(): void
    {
        $paidType = fn () => $this->day(InternDay::LEAVE, ['isLeave' => true, 'leavePaid' => true, 'detail' => 'Vacation']);

        $this->assertSame(['leave_unpaid' => 500.0], $this->chargesByKind($this->price([$paidType()], $this->rules())));
        $this->assertSame([], $this->price([$paidType()], $this->rules(['leave_pay_rule' => 'follow_leave_type']))->charges);
    }

    // --- per hour -------------------------------------------------------------------------------------

    public function test_an_hourly_allowance_pays_the_hours_rendered_up_to_the_daily_cap(): void
    {
        $rules = $this->rules(['method' => 'per_hour', 'hourly_amount' => 60, 'max_paid_hours_per_day' => 8]);

        $pricing = $this->price([
            ...$this->worked(1, ['workedMinutes' => 600]), // capped at 8h
            ...$this->worked(1, ['workedMinutes' => 300]),
            $this->day(InternDay::ABSENT),
        ], $rules, 60);

        $this->assertSame(780.0, $pricing->periodBase); // 13h × ₱60
        $this->assertSame(0.0, $pricing->chargeTotal());
        $this->assertSame(1.0, $pricing->toSlipFields()['unpaid_days'], 'the absence is still on the record');
    }

    /**
     * "Treat as a half day" is offered for every method, so it has to MEAN something under an hourly
     * allowance too — half the day the rules call a full one, whatever was rendered. It used to be
     * stored, reported as accepted, and then quietly behave as `flag_only`.
     */
    public function test_below_minimum_attendance_can_be_paid_as_half_a_day_when_paid_by_the_hour(): void
    {
        $rules = $this->rules([
            'method' => 'per_hour',
            'hourly_amount' => 60,
            'min_attendance_pct' => 75,
            'below_min_attendance' => 'treat_as_half_day',
        ]);

        // 5h rendered of an 8h day — 62.5%, under the 75% bar.
        $pricing = $this->price($this->worked(1, ['workedMinutes' => 300]), $rules, 60);

        $this->assertSame(240.0, $pricing->periodBase);   // 4h × ₱60 — half a day, not the 5h rendered
        $this->assertSame(0.0, $pricing->chargeTotal());  // an hourly allowance is earned, never docked
        $this->assertSame(0.5, $pricing->toSlipFields()['unpaid_days'], 'the half day is still on the record');
    }

    // --- fixed monthly --------------------------------------------------------------------------------

    public function test_a_monthly_allowance_is_spread_across_cutoffs_and_docked_at_the_daily_equivalent(): void
    {
        $rules = $this->rules(['method' => 'fixed_monthly', 'monthly_amount' => 10000]);

        $pricing = $this->price([...$this->worked(10), $this->day(InternDay::ABSENT)], $rules, 10000);

        $this->assertSame(5000.0, $pricing->periodBase);     // ₱10,000 × 12 ÷ 24
        $this->assertSame(454.55, $pricing->dailyRate);      // ₱5,000 ÷ 11 workdays
        $this->assertSame(['absent' => 454.55], $this->chargesByKind($pricing));
    }

    public function test_a_monthly_allowance_without_proration_ignores_the_days_attended(): void
    {
        $rules = $this->rules(['method' => 'fixed_monthly', 'monthly_amount' => 10000, 'monthly_proration' => 'none']);

        $pricing = $this->price([...$this->worked(10), $this->day(InternDay::ABSENT)], $rules, 10000);

        $this->assertSame([], $pricing->charges);
        $this->assertSame(5000.0, $pricing->netAllowance());
    }

    public function test_a_monthly_allowance_paid_once_a_month_lands_whole_or_not_at_all(): void
    {
        $rules = $this->rules(['method' => 'fixed_monthly', 'monthly_amount' => 10000, 'distribution_timing' => 'last_cutoff']);

        $this->assertSame(10000.0, $this->price($this->worked(22), $rules, 10000, [], InternRateContext::SHARE_MONTH)->periodBase);
        $this->assertSame(0.0, $this->price($this->worked(11), $rules, 10000, [], InternRateContext::SHARE_NONE)->periodBase);
    }

    // --- the payslip it becomes ----------------------------------------------------------------------

    /** The document apportions the charge back into rows; they must add up to what was taken. */
    public function test_the_payslip_rows_add_up_to_the_charge(): void
    {
        $pricing = $this->price([
            ...$this->worked(8),
            ...$this->worked(1, ['lateMinutes' => 60, 'lateChargeMinutes' => 60]),
            $this->day(InternDay::ABSENT),
            $this->day(InternDay::HALF_DAY, ['halves' => 1, 'worked' => true, 'workedMinutes' => 240]),
        ], $this->rules(['late_rule' => 'missed_hours']));

        $slip = $pricing->toSlipFields() + ['gross' => $pricing->earnings()];
        $rows = PayslipDocument::deductionRows($slip);

        $this->assertTrue($slip['is_intern']);
        $this->assertSame(812.5, $slip['absence_deduction']); // 500 absent + 250 half + 62.50 late
        $this->assertSame(62.5, $slip['late_deduction']);
        $this->assertEquals(812.5, array_sum(array_column($rows, 'amount')));
        $this->assertSame('Allowance', PayslipDocument::earningLines($slip)[0]['name']);
    }
}
