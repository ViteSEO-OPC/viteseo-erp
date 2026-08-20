<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Tests\TestCase;

/**
 * Holiday premium now reads the HR-editable rate columns. The headline guard is the NO-OP —
 * at the seeded DOLE-minimum defaults the multiplier equals the old hardcoded literals (regular
 * +1.0, special +0.3), so no payslip changes. Pure/static, no DB — runs everywhere.
 */
class HolidayPremiumRateTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function policy(array $overrides = []): AttendancePolicy
    {
        return new AttendancePolicy(array_merge(AttendancePolicyService::DEFAULTS, $overrides));
    }

    public function test_defaults_reproduce_the_legacy_hardcoded_premiums(): void
    {
        $p = $this->policy();
        $this->assertEqualsWithDelta(1.0, PayrollService::holidayPremiumMultiplier('regular', $p), 1e-9);
        $this->assertEqualsWithDelta(0.3, PayrollService::holidayPremiumMultiplier('special_non_working', $p), 1e-9);
        $this->assertSame(0.0, PayrollService::holidayPremiumMultiplier('special_working', $p));
    }

    public function test_raising_a_rate_raises_the_premium(): void
    {
        $p = $this->policy(['premium_regular_holiday_pct' => 250, 'premium_special_day_pct' => 150]);
        $this->assertEqualsWithDelta(1.5, PayrollService::holidayPremiumMultiplier('regular', $p), 1e-9);
        $this->assertEqualsWithDelta(0.5, PayrollService::holidayPremiumMultiplier('special_non_working', $p), 1e-9);
    }
}
