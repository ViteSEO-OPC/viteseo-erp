<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use Carbon\CarbonImmutable;

/**
 * The business-date clock for Accounting. Storage stays UTC; date BOUNDARIES
 * (report period defaults, "today" checks, entry dates) are computed in the
 * local timezone so a late-night Manila posting doesn't land on yesterday's
 * (UTC) date.
 */
final class AccountingClock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone_local'));
    }

    /** Today's business date, Y-m-d. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }
}
