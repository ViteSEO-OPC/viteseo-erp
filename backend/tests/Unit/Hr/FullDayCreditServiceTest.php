<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Services\FullDayCreditService;
use Tests\TestCase;

/**
 * FullDayCreditService is pure — no DB. These pin the "minimum hours = full day" math before
 * it can move anybody's pay. Times are Manila minute-of-day (08:00 = 480, 18:00 = 1080).
 *
 * The baseline day throughout is 08:00–18:00 with a 12:00–13:00 unpaid meal, so the paid day
 * is nine hours and the declared bar is six.
 */
class FullDayCreditServiceTest extends TestCase
{
    private FullDayCreditService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FullDayCreditService;
    }

    private function ctx(array $overrides = []): array
    {
        return array_merge([
            'threshold_min' => 360, // six hours
            'start_min' => 480, 'end_min' => 1080,
            'am_end_min' => 720, 'pm_start_min' => 780,
            'time_in_min' => 480, 'time_out_min' => 900,
            'unpaid_break_minutes' => 60, 'lunch_minutes' => 0,
            'overtime_approved' => false,
        ], $overrides);
    }

    // --- the no-op gate: no bar declared, nothing happens ---

    public function test_no_threshold_is_never_met(): void
    {
        $this->assertFalse($this->svc->met($this->ctx(['threshold_min' => null])));
        $this->assertNull($this->svc->reachesAt($this->ctx(['threshold_min' => null])));
    }

    public function test_a_zero_or_negative_threshold_is_never_met(): void
    {
        $this->assertFalse($this->svc->met($this->ctx(['threshold_min' => 0])));
        $this->assertFalse($this->svc->met($this->ctx(['threshold_min' => -60])));
    }

    // --- the headline cases ---

    public function test_eight_to_three_is_six_hours_and_clears_a_six_hour_bar(): void
    {
        // 08:00–15:00 is seven hours on the clock; the unpaid meal makes it six.
        $ctx = $this->ctx(['time_in_min' => 480, 'time_out_min' => 900]);
        $this->assertSame(360, $this->svc->workedMinutes($ctx));
        $this->assertTrue($this->svc->met($ctx));
    }

    public function test_a_late_arrival_still_clears_the_bar_by_working_the_hours(): void
    {
        // In at 09:00, out at 16:00 — still seven clock hours, still six worked.
        $ctx = $this->ctx(['time_in_min' => 540, 'time_out_min' => 960]);
        $this->assertSame(360, $this->svc->workedMinutes($ctx));
        $this->assertTrue($this->svc->met($ctx));
    }

    public function test_one_minute_short_does_not_clear_it(): void
    {
        $ctx = $this->ctx(['time_out_min' => 899]);
        $this->assertSame(359, $this->svc->workedMinutes($ctx));
        $this->assertFalse($this->svc->met($ctx));
    }

    // --- the meal, which is where this is easiest to get wrong ---

    public function test_leaving_before_lunch_is_not_charged_for_a_lunch_never_taken(): void
    {
        // Out at 11:00, an hour before the meal window opens: three hours, not two.
        $ctx = $this->ctx(['time_out_min' => 660]);
        $this->assertSame(180, $this->svc->workedMinutes($ctx));
    }

    public function test_leaving_mid_meal_is_charged_only_the_part_actually_taken(): void
    {
        // Out at 12:30 — half an hour into a one-hour meal, so thirty minutes come out.
        $ctx = $this->ctx(['time_out_min' => 750]);
        $this->assertSame(240, $this->svc->workedMinutes($ctx));
    }

    public function test_arriving_after_lunch_loses_no_meal_at_all(): void
    {
        // In at 13:00, out at 18:00: five clean hours, the meal already behind them.
        $ctx = $this->ctx(['time_in_min' => 780, 'time_out_min' => 1080]);
        $this->assertSame(300, $this->svc->workedMinutes($ctx));
    }

    public function test_the_meal_deduction_cannot_exceed_the_schedules_unpaid_break(): void
    {
        // A three-hour am_end→pm_start gap with only 60 unpaid minutes still costs 60.
        $ctx = $this->ctx(['am_end_min' => 660, 'pm_start_min' => 840, 'time_out_min' => 1080]);
        $this->assertSame(600 - 60, $this->svc->workedMinutes($ctx));
    }

    public function test_tapped_lunch_is_used_only_when_no_meal_window_exists(): void
    {
        $ctx = $this->ctx([
            'am_end_min' => null, 'pm_start_min' => null,
            'time_out_min' => 900, 'lunch_minutes' => 45,
        ]);
        $this->assertSame(420 - 45, $this->svc->workedMinutes($ctx));
    }

    public function test_skipping_the_lunch_button_cannot_clear_the_bar_early(): void
    {
        // The schedule's meal comes out whether or not they tapped it — otherwise the bar
        // could be cleared by forgetting to press a button.
        $ctx = $this->ctx(['time_out_min' => 900, 'lunch_minutes' => 0]);
        $this->assertSame(360, $this->svc->workedMinutes($ctx));
    }

    // --- the authorised window ---

    public function test_arriving_early_does_not_count_toward_the_bar(): void
    {
        // In at 06:00, out at 14:00: credited from 08:00, so five hours, not seven.
        $ctx = $this->ctx(['time_in_min' => 360, 'time_out_min' => 840]);
        $this->assertSame(300, $this->svc->workedMinutes($ctx));
        $this->assertFalse($this->svc->met($ctx));
    }

    public function test_staying_past_the_scheduled_end_does_not_count_without_approved_overtime(): void
    {
        // Out at 20:00 is credited only to 18:00 — nine hours, not eleven.
        $ctx = $this->ctx(['time_in_min' => 480, 'time_out_min' => 1200]);
        $this->assertSame(540, $this->svc->workedMinutes($ctx));
    }

    public function test_approved_overtime_lets_time_past_the_end_count(): void
    {
        $ctx = $this->ctx([
            'time_in_min' => 480, 'time_out_min' => 1200, 'overtime_approved' => true,
        ]);
        $this->assertSame(660, $this->svc->workedMinutes($ctx));
    }

    public function test_an_afternoon_only_employee_cannot_reach_a_six_hour_bar_unaided(): void
    {
        // 13:00–18:00 is five hours; the bar needs approved overtime to be reachable at all.
        $ctx = $this->ctx(['time_in_min' => 780, 'time_out_min' => 1080]);
        $this->assertFalse($this->svc->met($ctx));
        $this->assertNull($this->svc->reachesAt($ctx));

        $withOt = $this->ctx([
            'time_in_min' => 780, 'time_out_min' => 1140, 'overtime_approved' => true,
        ]);
        $this->assertTrue($this->svc->met($withOt));
        $this->assertSame(1140, $this->svc->reachesAt($withOt)); // 19:00
    }

    // --- missing punches ---

    public function test_a_day_without_both_punches_cannot_be_judged(): void
    {
        $this->assertNull($this->svc->workedMinutes($this->ctx(['time_in_min' => null])));
        $this->assertNull($this->svc->workedMinutes($this->ctx(['time_out_min' => null])));
        $this->assertFalse($this->svc->met($this->ctx(['time_out_min' => null])));
    }

    // --- reachesAt: the number the employee actually acts on ---

    public function test_reaches_at_names_the_clock_time_the_bar_is_hit(): void
    {
        $this->assertSame(900, $this->svc->reachesAt($this->ctx(['time_in_min' => 480]))); // 15:00
        $this->assertSame(960, $this->svc->reachesAt($this->ctx(['time_in_min' => 540]))); // 16:00
    }

    public function test_reaches_at_does_not_depend_on_when_they_actually_left(): void
    {
        $early = $this->svc->reachesAt($this->ctx(['time_out_min' => 600]));
        $late = $this->svc->reachesAt($this->ctx(['time_out_min' => 1080]));
        $this->assertSame($early, $late);
    }

    public function test_reaches_at_is_null_when_the_bar_is_out_of_reach(): void
    {
        // A ten-hour bar on a nine-hour paid day is unreachable without overtime.
        $this->assertNull($this->svc->reachesAt($this->ctx(['threshold_min' => 600])));
    }

    // --- overnight ---

    public function test_an_overnight_shift_still_measures_worked_time_correctly(): void
    {
        // 22:00–06:00, no meal window; in 22:00, out 05:00 ⇒ seven hours.
        $ctx = [
            'threshold_min' => 360, 'start_min' => 1320, 'end_min' => 360,
            'am_end_min' => null, 'pm_start_min' => null,
            'time_in_min' => 1320, 'time_out_min' => 300,
            'unpaid_break_minutes' => 0, 'lunch_minutes' => 0, 'overtime_approved' => false,
        ];
        $this->assertSame(420, $this->svc->workedMinutes($ctx));
        $this->assertTrue($this->svc->met($ctx));
        // The hint is deliberately withheld rather than printed wrong.
        $this->assertNull($this->svc->reachesAt($ctx));
    }
}
