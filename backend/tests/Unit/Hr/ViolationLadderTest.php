<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ViolationService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the violation colour ladder evaluation (finding P1-5) — pure, no DB. The
 * headline is the NO-OP GATE: the seeded default ladder reproduces exactly the old
 * hard-coded severities, so making ViolationService read the ladder changed no flags.
 */
class ViolationLadderTest extends TestCase
{
    /** @return array<string, array<int, array<string, mixed>>> */
    private function defaults(): array
    {
        return AttendancePolicyService::DEFAULT_VIOLATION_RULES;
    }

    // --- no-op: default ladder == the old literals ---

    public function test_default_late_ladder_climbs_the_four_levels(): void
    {
        $r = $this->defaults();
        // Low 1 · Caution 3 · Warning 4 · Critical 6 (lates this month).
        $this->assertSame('green', ViolationService::ladderMatch($r, 'late', ['monthly' => 1])['severity']);
        $this->assertSame('green', ViolationService::ladderMatch($r, 'late', ['monthly' => 2])['severity']);
        $this->assertSame('yellow', ViolationService::ladderMatch($r, 'late', ['monthly' => 3])['severity']);
        $this->assertSame('orange', ViolationService::ladderMatch($r, 'late', ['monthly' => 4])['severity']);
        $this->assertSame('orange', ViolationService::ladderMatch($r, 'late', ['monthly' => 5])['severity']);
        $this->assertSame('red', ViolationService::ladderMatch($r, 'late', ['monthly' => 6])['severity']);
    }

    public function test_default_absence_ladder_climbs_the_four_levels_with_types(): void
    {
        $r = $this->defaults();
        // Low 1 · Caution 2 · Warning 3 · Critical/AWOL 4 (consecutive days).
        $this->assertSame(['green', 'ua'], array_values(ViolationService::ladderMatch($r, 'absence', ['streak' => 1])));
        $this->assertSame(['yellow', 'ua'], array_values(ViolationService::ladderMatch($r, 'absence', ['streak' => 2])));
        $this->assertSame(['orange', 'ua'], array_values(ViolationService::ladderMatch($r, 'absence', ['streak' => 3])));
        $this->assertSame(['red', 'awol'], array_values(ViolationService::ladderMatch($r, 'absence', ['streak' => 4])));
    }

    public function test_missed_time_out_is_always_green(): void
    {
        $this->assertSame('green', ViolationService::ladderMatch($this->defaults(), 'missed_time_out', ['occurrence' => 1])['severity']);
    }

    // --- HR edits actually change the engine (the dead-field fix) ---

    public function test_raising_the_awol_threshold_delays_red(): void
    {
        // AWOL now needs 5 in a row: a 3-day streak is Warning, not Critical.
        $rules = $this->defaults();
        $rules['absence'][0]['streak_min'] = 5;

        $this->assertSame('orange', ViolationService::ladderMatch($rules, 'absence', ['streak' => 3])['severity']);
        $this->assertSame('red', ViolationService::ladderMatch($rules, 'absence', ['streak' => 5])['severity']);
    }

    public function test_first_matching_rung_wins_top_down(): void
    {
        // Most-severe first: a value clearing the top rung takes it, not a lower one.
        $rules = ['late' => [
            ['monthly_min' => 5, 'severity' => 'red'],
            ['monthly_min' => 1, 'severity' => 'green'],
        ]];
        $this->assertSame('red', ViolationService::ladderMatch($rules, 'late', ['monthly' => 6])['severity']);
        $this->assertSame('green', ViolationService::ladderMatch($rules, 'late', ['monthly' => 2])['severity']);
    }

    public function test_empty_ladder_never_flags(): void
    {
        $this->assertNull(ViolationService::ladderMatch(['late' => []], 'late', ['streak' => 9, 'monthly' => 9]));
        $this->assertNull(ViolationService::ladderMatch([], 'absence', ['streak' => 9]));
    }
}
