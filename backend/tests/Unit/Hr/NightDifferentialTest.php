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
}
