<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\DailyOverride;
use Illuminate\Support\Carbon;

class DailyOverrideService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Get the grace override for a date (null = no override set today).
     */
    public function get(?string $date = null): ?DailyOverride
    {
        $date ??= now(config('app.timezone_local'))->toDateString();
        return DailyOverride::where('date', $date)->first();
    }

    /**
     * Get the grace_minutes override for a date, or null if none set.
     */
    public function graceForDate(Carbon|string $date): ?int
    {
        $dateStr = $date instanceof Carbon ? $date->toDateString() : $date;
        $override = DailyOverride::where('date', $dateStr)->first();
        return $override?->grace_minutes;
    }

    /**
     * Upsert the grace override for a date. Pass null to clear the override.
     */
    public function upsert(string $date, ?int $graceMinutes): DailyOverride
    {
        if ($graceMinutes === null) {
            DailyOverride::where('date', $date)->delete();
            $this->audit->log('updated', 'Rules', "Grace-period override cleared for {$date}");
            return DailyOverride::make(['date' => $date, 'grace_minutes' => null]);
        }

        $override = DailyOverride::updateOrCreate(
            ['date' => $date],
            ['grace_minutes' => $graceMinutes],
        );

        $this->audit->log('updated', 'Rules', "Grace-period override set for {$date}", $override);

        return $override;
    }
}
