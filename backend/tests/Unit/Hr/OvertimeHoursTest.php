<?php

namespace Tests\Unit\Hr;

use App\Modules\Hr\Services\OvertimeRequestService;
use Tests\TestCase;

/**
 * Overtime is filed as a time WINDOW and the hours that become pay are derived from it
 * server-side — the client never types the number. A window ending at/before its start crosses
 * midnight. Pure/static, no DB — runs everywhere.
 */
class OvertimeHoursTest extends TestCase
{
    public function test_same_day_window(): void
    {
        $this->assertSame(3.0, OvertimeRequestService::hoursBetween('18:00', '21:00'));
        $this->assertSame(1.5, OvertimeRequestService::hoursBetween('17:30', '19:00'));
        $this->assertSame(0.25, OvertimeRequestService::hoursBetween('20:00', '20:15'));
    }

    public function test_window_crossing_midnight(): void
    {
        $this->assertSame(4.0, OvertimeRequestService::hoursBetween('22:00', '02:00'));
        $this->assertSame(8.0, OvertimeRequestService::hoursBetween('20:00', '04:00'));
    }
}
