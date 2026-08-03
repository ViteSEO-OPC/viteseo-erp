<?php

namespace App\Modules\Crm\Enums;

use Illuminate\Support\Carbon;

/**
 * The cadence a performance target is set for. Monthly drives Personal Target, quarterly drives the
 * Quarter Targets tiles — one domain, two cadences, discriminated here rather than by two tables.
 */
enum PerformanceTargetPeriod: string
{
    case MONTH = 'month';
    case QUARTER = 'quarter';

    /**
     * The canonical anchor date for the period containing $moment — the 1st of its month or the 1st
     * of its quarter. Storing the anchor (rather than a range) makes "which target applies now" an
     * equality lookup and keeps the unique key meaningful.
     */
    public function anchorFor(Carbon $moment): Carbon
    {
        return match ($this) {
            self::MONTH => $moment->copy()->startOfMonth()->startOfDay(),
            self::QUARTER => $moment->copy()->firstOfQuarter()->startOfDay(),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
