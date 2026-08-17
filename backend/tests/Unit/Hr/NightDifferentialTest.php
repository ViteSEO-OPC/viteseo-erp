<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Services\AttendanceService;
use Tests\TestCase;

/**
 * ND-1: which worked minutes fall in the 22:00–06:00 night window. Inputs are minute-of-day on the
 * work date; `out` exceeds 1440 when the shift crosses midnight. Pure/static — runs everywhere.
 */
class NightDifferentialTest extends TestCase
{
    private function mins(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $h * 60 + $m;
    }

    public function test_a_normal_day_shift_has_no_night_minutes(): void
    {
        // 08:00–17:00 — entirely outside the window.
        $this->assertSame(0, AttendanceService::nightOverlapMinutes($this->mins('08:00'), $this->mins('17:00')));
    }

    public function test_evening_overrun_into_the_window(): void
    {
        // 14:00–23:30 → only 22:00–23:30 counts = 90 min.
        $this->assertSame(90, AttendanceService::nightOverlapMinutes($this->mins('14:00'), $this->mins('23:30')));
    }

    public function test_shift_crossing_midnight(): void
    {
        // 22:00–02:00 (out = 02:00 + 24h) → the whole 4 hours are night.
        $this->assertSame(240, AttendanceService::nightOverlapMinutes($this->mins('22:00'), $this->mins('02:00') + 1440));
    }

    public function test_early_morning_start_before_six(): void
    {
        // 04:00–18:00 → only 04:00–06:00 counts = 120 min.
        $this->assertSame(120, AttendanceService::nightOverlapMinutes($this->mins('04:00'), $this->mins('18:00')));
    }

    public function test_full_graveyard_shift(): void
    {
        // 22:00–06:00 → the entire 8 hours are night.
        $this->assertSame(480, AttendanceService::nightOverlapMinutes($this->mins('22:00'), $this->mins('06:00') + 1440));
    }

    public function test_a_custom_cross_midnight_window_is_used(): void
    {
        // Worked 20:00–06:00; a configured 21:00–03:00 window contributes exactly six hours.
        $this->assertSame(360, AttendanceService::nightOverlapMinutes(
            $this->mins('20:00'),
            $this->mins('06:00') + 1440,
            $this->mins('21:00'),
            $this->mins('03:00'),
        ));
    }

    public function test_a_custom_same_day_window_is_supported(): void
    {
        // A company may expand/relocate the band without forcing it across midnight.
        $this->assertSame(120, AttendanceService::nightOverlapMinutes(
            $this->mins('08:00'),
            $this->mins('17:00'),
            $this->mins('13:00'),
            $this->mins('15:00'),
        ));
    }

    public function test_only_the_overlapping_edge_of_a_shift_counts(): void
    {
        // 21:00–23:00 straddles the 22:00 boundary — one hour of it is night.
        $this->assertSame(60, AttendanceService::nightOverlapMinutes($this->mins('21:00'), $this->mins('23:00')));
    }

    public function test_a_window_that_starts_at_midnight_still_catches_an_overnight_tail(): void
    {
        // A 00:00–06:00 band has no evening half: a 22:00–06:00 shift earns only its last six hours.
        $this->assertSame(360, AttendanceService::nightOverlapMinutes(
            $this->mins('22:00'),
            $this->mins('06:00') + 1440,
            $this->mins('00:00'),
            $this->mins('06:00'),
        ));
    }

    public function test_a_shift_inside_the_daytime_gap_of_a_cross_midnight_window_earns_nothing(): void
    {
        // 09:00–17:00 sits entirely between the 03:00 end and the 21:00 start.
        $this->assertSame(0, AttendanceService::nightOverlapMinutes(
            $this->mins('09:00'),
            $this->mins('17:00'),
            $this->mins('21:00'),
            $this->mins('03:00'),
        ));
    }

    public function test_a_degenerate_window_pays_nothing(): void
    {
        // The API rejects equal endpoints (zero minutes vs a full day is ambiguous); if one ever
        // reaches the arithmetic — a hand-edited row, an imported payload — it must not pay 24h.
        $this->assertSame(0, AttendanceService::nightOverlapMinutes(
            $this->mins('22:00'),
            $this->mins('06:00') + 1440,
            $this->mins('22:00'),
            $this->mins('22:00'),
        ));
    }

    public function test_a_long_shift_can_cross_the_window_twice(): void
    {
        // 18:00 → 08:00 the next day: 22:00–06:00 is eight hours, and nothing before or after counts.
        $this->assertSame(480, AttendanceService::nightOverlapMinutes(
            $this->mins('18:00'),
            $this->mins('08:00') + 1440,
        ));
    }
}
