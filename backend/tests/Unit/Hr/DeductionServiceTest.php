<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\DeductionService;
use Tests\TestCase;

/**
 * DeductionService is pure — no DB. These tests pin the money math (audit §7 step 8)
 * BEFORE it is wired into payroll, starting with the no-op gate that makes the whole
 * slice safe. Times are Manila minute-of-day (08:00 = 480, 18:00 = 1080).
 */
class DeductionServiceTest extends TestCase
{
    private DeductionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DeductionService;
    }

    /** A policy at the seeded defaults, with optional overrides. No DB touched. */
    private function policy(array $overrides = []): AttendancePolicy
    {
        return new AttendancePolicy(array_merge(AttendancePolicyService::DEFAULTS, $overrides));
    }

    /** A standard 08:00–18:00 (9h, 1h lunch) worked day, on time, clocked out. */
    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'start_min' => 480, 'end_min' => 1080, 'grace_min' => 15,
            'am_start_min' => 480, 'am_end_min' => 720,
            'pm_start_min' => 780, 'pm_end_min' => 1080,
            'time_in_min' => 480, 'time_out_min' => 1080,
            'half_day_off' => null, 'governed_by_early_out' => false,
            'amnesty' => false, 'cap_remaining' => 1.0,
            'break_minutes' => 15, 'lunch_minutes' => 60,
        ], $overrides);
    }

    // --- the no-op gate ---

    public function test_all_modes_off_is_zero_even_when_late_and_undertime(): void
    {
        // 90 min late AND 60 min early, but every mode is off → not a peso.
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 570, 'time_out_min' => 1020]),
            $this->policy(), // defaults: late none, undertime none
        );

        $this->assertSame(0.0, $r->unpaidFraction);
        $this->assertSame(0, $r->lateMinutes);
        $this->assertSame(0, $r->undertimeMinutes);
    }

    public function test_absent_day_returns_zero(): void
    {
        $r = $this->svc->forDay($this->ctx(['time_in_min' => null]), $this->policy(['late_deduction_mode' => 'per_hour']));
        $this->assertSame(0.0, $r->unpaidFraction);
    }

    // --- per_hour late ---

    public function test_per_hour_late_exact_prices_half_an_hour(): void
    {
        // 30 min late on an 8h-priced day → 30 / 480.
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 510]),
            $this->policy(['late_deduction_mode' => 'per_hour']),
        );

        $this->assertSame(30, $r->lateMinutes);
        $this->assertEqualsWithDelta(30 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_round_up_snaps_30_to_a_full_hour(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 510]),
            $this->policy(['late_deduction_mode' => 'per_hour', 'deduction_rounding' => 'round_up', 'deduction_block_minutes' => 60]),
        );
        $this->assertEqualsWithDelta(60 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_round_down_zeroes_a_sub_block_lateness(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 510]),
            $this->policy(['late_deduction_mode' => 'per_hour', 'deduction_rounding' => 'round_down', 'deduction_block_minutes' => 60]),
        );
        $this->assertSame(0.0, $r->unpaidFraction);
    }

    public function test_round_nearest_rounds_a_half_block_up(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 510]),
            $this->policy(['late_deduction_mode' => 'per_hour', 'deduction_rounding' => 'round_nearest', 'deduction_block_minutes' => 60]),
        );
        $this->assertEqualsWithDelta(60 / 480, $r->unpaidFraction, 1e-9);
    }

    /**
     * `late_minutes_from` moves what the MONEY is measured from, not what is reported — both
     * bases report the same minutes past the scheduled start.
     */
    public function test_late_minutes_from_after_grace_forgives_the_grace_window(): void
    {
        // 08:30 arrival, 15-min grace: start_time prices 30 minutes, after_grace prices 15.
        $fromStart = $this->svc->forDay($this->ctx(['time_in_min' => 510]), $this->policy(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => 'start_time']));
        $fromGrace = $this->svc->forDay($this->ctx(['time_in_min' => 510]), $this->policy(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => 'after_grace']));

        $this->assertEqualsWithDelta(30 / 480, $fromStart->unpaidFraction, 1e-9);
        $this->assertEqualsWithDelta(15 / 480, $fromGrace->unpaidFraction, 1e-9);

        $this->assertSame(30, $fromStart->lateMinutes);
        $this->assertSame(30, $fromGrace->lateMinutes);
    }

    /**
     * Grace is a GATE under BOTH bases. `start_time` used to discard it for pay, so an arrival
     * the attendance screen called "on time" was still charged on the payslip.
     */
    public function test_an_arrival_inside_grace_is_never_late_under_either_basis(): void
    {
        foreach (['start_time', 'after_grace'] as $basis) {
            $policy = $this->policy(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => $basis]);

            // 08:10 — inside the 15-min grace.
            $inside = $this->svc->forDay($this->ctx(['time_in_min' => 490]), $policy);
            $this->assertSame(0.0, $inside->unpaidFraction, $basis);
            $this->assertSame(0, $inside->lateMinutes, $basis);

            // 08:15 — the last forgiven minute, the same boundary arrivalCategory() uses.
            $edge = $this->svc->forDay($this->ctx(['time_in_min' => 495]), $policy);
            $this->assertSame(0.0, $edge->unpaidFraction, $basis);
        }

        // 08:16 — one minute past the gate. Both report the full 16 minutes past 08:00; the
        // start_time basis prices all 16, after_grace prices only the minute outside the window.
        $fromStart = $this->svc->forDay($this->ctx(['time_in_min' => 496]), $this->policy(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => 'start_time']));
        $fromGrace = $this->svc->forDay($this->ctx(['time_in_min' => 496]), $this->policy(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => 'after_grace']));

        $this->assertSame(16, $fromStart->lateMinutes);
        $this->assertSame(16, $fromGrace->lateMinutes);
        // 1e-6, not 1e-9: neither sixteenth nor 480th of a day is exact, and forDay() rounds
        // the fraction to 6 places before returning it.
        $this->assertEqualsWithDelta(16 / 480, $fromStart->unpaidFraction, 1e-6);
        $this->assertEqualsWithDelta(1 / 480, $fromGrace->unpaidFraction, 1e-6);
    }

    /**
     * The question the basis actually answers for HR: with a 15-min grace and a "more than 30
     * minutes ⇒ 1h" rule, which minute does the money start at? 08:31 on start_time, 08:46 on
     * after_grace — and nobody inside grace pays under either.
     */
    public function test_the_basis_decides_which_minute_the_threshold_bites_at(): void
    {
        $tiers = [['over' => 30, 'hours' => 1]];
        $at = fn (int $min, string $basis): float => $this->svc->forDay(
            $this->ctx(['time_in_min' => $min]),
            $this->policy(['late_deduction_mode' => 'tiered', 'late_tiers' => $tiers, 'late_minutes_from' => $basis]),
        )->unpaidFraction;

        // start_time: 08:30 is 30 chargeable minutes, which is not MORE than 30. 08:31 is.
        $this->assertSame(0.0, $at(510, 'start_time'));
        $this->assertEqualsWithDelta(60 / 480, $at(511, 'start_time'), 1e-9);

        // after_grace: the same 31st chargeable minute lands 15 minutes later on the clock.
        $this->assertSame(0.0, $at(525, 'after_grace'));                        // 08:45 → 30
        $this->assertEqualsWithDelta(60 / 480, $at(526, 'after_grace'), 1e-9);  // 08:46 → 31
    }

    // --- undertime ---

    public function test_per_hour_undertime_prices_early_leave(): void
    {
        // Left 30 min early (17:30 vs 18:00).
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => 1050]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(30, $r->undertimeMinutes);
        $this->assertEqualsWithDelta(30 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_early_out_governed_day_is_not_double_charged(): void
    {
        // The approved early-out prices this day elsewhere → DeductionService adds nothing.
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => 900, 'governed_by_early_out' => true]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(0, $r->undertimeMinutes);
        $this->assertSame(0.0, $r->unpaidFraction);
    }

    public function test_missing_clockout_is_flagged_not_charged(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => null]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(0, $r->undertimeMinutes);
        $this->assertSame(0.0, $r->unpaidFraction);
        $this->assertContains('missing_clockout', $r->flags);
    }

    // --- cap ---

    public function test_daily_cap_binds_at_one_day(): void
    {
        // Arrived at 16:00 (8h late) under per_hour → 480/480 = 1.0, and cap holds it there.
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 960]),
            $this->policy(['late_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(1.0, $r->unpaidFraction);
    }

    public function test_cap_remaining_limits_a_half_day(): void
    {
        // Half-day (AM off) already cost 0.5; the worked PM half can add at most 0.5 more.
        $r = $this->svc->forDay(
            $this->ctx(['half_day_off' => 'AM', 'time_in_min' => 780, 'time_out_min' => 900, 'cap_remaining' => 0.5]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );
        $this->assertLessThanOrEqual(0.5, $r->unpaidFraction);
    }

    public function test_half_day_measures_against_the_worked_half(): void
    {
        // AM off → worked window is PM (13:00–18:00). Arrive 13:30 = 30 late under per_hour.
        $r = $this->svc->forDay(
            $this->ctx(['half_day_off' => 'AM', 'time_in_min' => 810, 'time_out_min' => 1080, 'cap_remaining' => 0.5]),
            $this->policy(['late_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(30, $r->lateMinutes);
    }

    // --- amnesty, tiers, flags ---

    public function test_force_majeure_amnesty_zeroes_the_day(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 570, 'amnesty' => true]),
            $this->policy(['late_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(0.0, $r->unpaidFraction);
    }

    public function test_tiered_ladder_charges_the_matching_rung(): void
    {
        $policy = $this->policy([
            'late_deduction_mode' => 'tiered',
            'late_tiers' => [['over' => 15, 'hours' => 1], ['over' => 60, 'hours' => 2]],
        ]);

        // 30 late → over 15 only → 1 hour = 60/480.
        $r30 = $this->svc->forDay($this->ctx(['time_in_min' => 510]), $policy);
        $this->assertEqualsWithDelta(60 / 480, $r30->unpaidFraction, 1e-9);

        // 90 late → over 60 → 2 hours = 120/480.
        $r90 = $this->svc->forDay($this->ctx(['time_in_min' => 570]), $policy);
        $this->assertEqualsWithDelta(120 / 480, $r90->unpaidFraction, 1e-9);
    }

    /**
     * The recurring flag turns the single rung into a repeating BLOCK. The rule HR writes as
     * "30 min late = 1h docked" then means what it says at every multiple, instead of charging
     * one hour whether somebody was 31 minutes or four hours late. Every block boundary is
     * strictly-more-than, exactly like the flat rung.
     */
    public function test_recurring_tier_charges_once_per_block_of_lateness(): void
    {
        $policy = $this->policy([
            'late_deduction_mode' => 'tiered',
            'late_tiers' => [['over' => 30, 'hours' => 1]],
            'late_tier_recurring' => true,
        ]);

        // 60 late (09:00 in) → still ONE block: 60 is not MORE than the second block's 60.
        $r60 = $this->svc->forDay($this->ctx(['time_in_min' => 540]), $policy);
        $this->assertEqualsWithDelta(60 / 480, $r60->unpaidFraction, 1e-9);
        $this->assertSame(60, $r60->lateMinutes); // the CLOCK figure is untouched by the charge

        // 61 late → the second block opens.
        $r61 = $this->svc->forDay($this->ctx(['time_in_min' => 541]), $policy);
        $this->assertEqualsWithDelta(120 / 480, $r61->unpaidFraction, 1e-9);

        // 90 late → two blocks; the third needs 91.
        $r90 = $this->svc->forDay($this->ctx(['time_in_min' => 570]), $policy);
        $this->assertEqualsWithDelta(120 / 480, $r90->unpaidFraction, 1e-9);
        $r91 = $this->svc->forDay($this->ctx(['time_in_min' => 571]), $policy);
        $this->assertEqualsWithDelta(180 / 480, $r91->unpaidFraction, 1e-9);

        // 45 late → one whole block only; the leftover 15 min does NOT buy a second.
        $r45 = $this->svc->forDay($this->ctx(['time_in_min' => 525]), $policy);
        $this->assertEqualsWithDelta(60 / 480, $r45->unpaidFraction, 1e-9);
    }

    /**
     * The toggle changes how OFTEN the rung is charged, never when it starts: at exactly the
     * threshold both modes charge nothing, and one minute past it both charge the first rung.
     * Pinned because this used to differ — recurring counted floor(late ÷ over) and so billed an
     * hour at exactly 30, meaning flipping the toggle re-priced a day the policy already handled.
     */
    public function test_recurring_and_flat_share_the_same_threshold_boundary(): void
    {
        $tiers = [['over' => 30, 'hours' => 1]];
        $price = fn (int $min, bool $recurring): float => $this->svc->forDay(
            $this->ctx(['time_in_min' => $min]),
            $this->policy(['late_deduction_mode' => 'tiered', 'late_tiers' => $tiers, 'late_tier_recurring' => $recurring]),
        )->unpaidFraction;

        // Exactly 30 late (08:30) is not MORE than 30 — nothing, either way.
        $this->assertSame(0.0, $price(510, false));
        $this->assertSame(0.0, $price(510, true));

        // 31 late buys the first rung, either way.
        $this->assertEqualsWithDelta(60 / 480, $price(511, false), 1e-9);
        $this->assertEqualsWithDelta(60 / 480, $price(511, true), 1e-9);

        // One minute short of the block is still nothing under either mode.
        $this->assertSame(0.0, $price(509, true));
    }

    /**
     * Every policy that exists today has the flag off, and must price exactly as it did before
     * the column was added — the whole no-op guarantee of this feature.
     */
    public function test_the_flag_off_is_the_old_flat_behaviour(): void
    {
        $policy = $this->policy([
            'late_deduction_mode' => 'tiered',
            'late_tiers' => [['over' => 30, 'hours' => 1]],
            'late_tier_recurring' => false,
        ]);

        // 31 and 240 minutes late cost the SAME flat hour — the complaint that motivated the flag.
        $r31 = $this->svc->forDay($this->ctx(['time_in_min' => 511]), $policy);
        $r240 = $this->svc->forDay($this->ctx(['time_in_min' => 720]), $policy);
        $this->assertEqualsWithDelta(60 / 480, $r31->unpaidFraction, 1e-9);
        $this->assertEqualsWithDelta(60 / 480, $r240->unpaidFraction, 1e-9);
    }

    /**
     * A ladder has no single rung to repeat. The save endpoint rejects the combination, so this
     * only guards a hand-edited or imported row: it must fall back to the flat ladder rather
     * than silently pick a rung and charge a multiple of it.
     */
    public function test_recurring_is_ignored_on_a_multi_rung_ladder(): void
    {
        $policy = $this->policy([
            'late_deduction_mode' => 'tiered',
            'late_tiers' => [['over' => 15, 'hours' => 1], ['over' => 60, 'hours' => 2]],
            'late_tier_recurring' => true,
        ]);

        // 90 late → the flat ladder's matching rung (over 60 → 2h), NOT six 15-min blocks.
        $r = $this->svc->forDay($this->ctx(['time_in_min' => 570]), $policy);
        $this->assertEqualsWithDelta(120 / 480, $r->unpaidFraction, 1e-9);
    }

    /** A 0-minute block can't be divided by. Falls back to flat instead of fataling a payroll run. */
    public function test_a_zero_width_recurring_block_falls_back_instead_of_dividing_by_zero(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 570]),
            $this->policy([
                'late_deduction_mode' => 'tiered',
                'late_tiers' => [['over' => 0, 'hours' => 1]],
                'late_tier_recurring' => true,
            ]),
        );

        // Flat rule, over 0: 90 > 0 → one hour. Not 90 blocks.
        $this->assertEqualsWithDelta(60 / 480, $r->unpaidFraction, 1e-9);
    }

    /** The existing safety rail still holds: multiplying blocks can never exceed one day's pay. */
    public function test_the_daily_cap_still_bounds_a_recurring_charge(): void
    {
        $r = $this->svc->forDay(
            // 04:00 late (12:00 in) under a 30 ⇒ 1h rule = eight blocks = 8h, which IS the
            // whole 8-hour day; the undertime on top would push it past one day's pay.
            $this->ctx(['time_in_min' => 720, 'time_out_min' => 1020]),
            $this->policy([
                'late_deduction_mode' => 'tiered',
                'late_tiers' => [['over' => 30, 'hours' => 1]],
                'late_tier_recurring' => true,
                'undertime_deduction_mode' => 'per_hour',
            ]),
        );

        $this->assertEqualsWithDelta(1.0, $r->unpaidFraction, 1e-9);
    }

    /**
     * Breaks are not a pay input — however long they run, this service charges nothing for
     * them. Detecting an OVERLONG break moved to ViolationService in (it is measured
     * against the employee's own schedule entitlement, and must work with every deduction mode
     * off); see ViolationBreakFlagTest. What stays guarded here is that it never costs money.
     */
    public function test_a_long_break_never_costs_pay(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['break_minutes' => 40, 'lunch_minutes' => 90]),
            $this->policy(), // all modes off
        );
        $this->assertSame(0.0, $r->unpaidFraction);

        // …and it stays zero even with both deduction modes on: only late/undertime are charged.
        $onTime = $this->svc->forDay(
            $this->ctx(['break_minutes' => 40, 'lunch_minutes' => 90]),
            $this->policy(['late_deduction_mode' => 'per_hour', 'undertime_deduction_mode' => 'per_hour']),
        );
        $this->assertSame(0.0, $onTime->unpaidFraction);
    }

    public function test_components_sum_to_the_total_fraction(): void
    {
        // Late 30 AND undertime 30, both modes on, no cap bind → components reconstruct total.
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 510, 'time_out_min' => 1050]),
            $this->policy(['late_deduction_mode' => 'per_hour', 'undertime_deduction_mode' => 'per_hour']),
        );
        $this->assertEqualsWithDelta($r->lateFraction + $r->undertimeFraction, $r->unpaidFraction, 1e-9);
        $this->assertEqualsWithDelta(60 / 480, $r->unpaidFraction, 1e-9);
    }

    // --- the unpaid meal, which is never worked and so is never charged ---

    public function test_the_unpaid_meal_is_not_charged_as_undertime(): void
    {
        // Left at noon on an 08:00–18:00 shift: 360 clock minutes short of the end, but an hour
        // of that is the 12:00–13:00 meal nobody was paying them for. 300 minutes of work lost.
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => 720, 'unpaid_break_minutes' => 60]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );

        $this->assertSame(360, $r->undertimeMinutes, 'the reported shortfall stays raw');
        $this->assertEqualsWithDelta(300 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_the_unpaid_meal_is_not_charged_as_lateness_either(): void
    {
        // Arrived at 14:00: 360 minutes late by the clock, over the same 300 minutes of work.
        $r = $this->svc->forDay(
            $this->ctx(['time_in_min' => 840, 'unpaid_break_minutes' => 60]),
            $this->policy(['late_deduction_mode' => 'per_hour', 'late_minutes_from' => 'start_time']),
        );

        $this->assertSame(360, $r->lateMinutes);
        $this->assertEqualsWithDelta(300 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_a_shortfall_that_never_reaches_the_meal_is_untouched(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => 1050, 'unpaid_break_minutes' => 60]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );

        $this->assertEqualsWithDelta(30 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_only_the_meal_the_schedule_declares_is_excused(): void
    {
        // The gap between the halves is 60 minutes, but this schedule pays for half of it.
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => 720, 'unpaid_break_minutes' => 30]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );

        $this->assertEqualsWithDelta(330 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_a_schedule_with_no_declared_meal_excuses_nothing(): void
    {
        $r = $this->svc->forDay(
            $this->ctx(['time_out_min' => 720]), // no unpaid_break_minutes at all
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );

        $this->assertEqualsWithDelta(360 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_a_half_days_shortfall_never_touches_the_meal(): void
    {
        // Afternoon off, so the owed window is 08:00–12:00 and the meal sits outside it entirely:
        // leaving at 11:30 is 30 minutes short, with nothing to excuse.
        $r = $this->svc->forDay(
            $this->ctx(['half_day_off' => 'PM', 'time_out_min' => 690, 'unpaid_break_minutes' => 60, 'cap_remaining' => 0.5]),
            $this->policy(['undertime_deduction_mode' => 'per_hour']),
        );

        $this->assertSame(30, $r->undertimeMinutes);
        $this->assertEqualsWithDelta(30 / 480, $r->unpaidFraction, 1e-9);
    }

    public function test_an_overnight_shift_charges_its_span_whole(): void
    {
        // 22:00–06:00 with no configured halves: there is no meal window to place, so the roll
        // is left to do its job and the shortfall is charged as measured.
        $r = $this->svc->forDay([
            'start_min' => 1320, 'end_min' => 360, 'grace_min' => 0,
            'am_start_min' => null, 'am_end_min' => null, 'pm_start_min' => null, 'pm_end_min' => null,
            'time_in_min' => 1320, 'time_out_min' => 300, // clocked out an hour early, at 05:00
            'half_day_off' => null, 'governed_by_early_out' => false, 'amnesty' => false,
            'cap_remaining' => 1.0, 'break_minutes' => 0, 'lunch_minutes' => 0,
            'unpaid_break_minutes' => 60,
        ], $this->policy(['undertime_deduction_mode' => 'per_hour']));

        $this->assertSame(60, $r->undertimeMinutes);
        $this->assertEqualsWithDelta(60 / 480, $r->unpaidFraction, 1e-9);
    }
}
