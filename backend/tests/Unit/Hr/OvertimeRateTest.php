<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Tests\TestCase;

/**
 * Which OT rate applies for a given day. Pure/static, no DB — runs everywhere.
 * Defaults are the DOLE minimums: ordinary 125, rest/special 169, regular holiday 260.
 */
class OvertimeRateTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function policy(array $overrides = []): AttendancePolicy
    {
        return new AttendancePolicy(array_merge(AttendancePolicyService::DEFAULTS, $overrides));
    }

    public function test_default_rate_by_day_type(): void
    {
        $p = $this->policy();
        $this->assertSame(125, PayrollService::overtimeMultiplierPct(null, true, $p));  // ordinary workday
        $this->assertSame(169, PayrollService::overtimeMultiplierPct(null, false, $p)); // rest day (non-workday)
        $this->assertSame(169, PayrollService::overtimeMultiplierPct('special_non_working', true, $p));
        $this->assertSame(260, PayrollService::overtimeMultiplierPct('regular', true, $p));
    }

    public function test_editable_rate_flows_through(): void
    {
        $p = $this->policy(['overtime_ordinary_pct' => 130, 'overtime_regular_holiday_pct' => 300]);
        $this->assertSame(130, PayrollService::overtimeMultiplierPct(null, true, $p));
        $this->assertSame(300, PayrollService::overtimeMultiplierPct('regular', true, $p));
    }
}
