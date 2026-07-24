<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\DailyOverride;
use Illuminate\Support\Carbon;

class DailyOverrideService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Per-instance memo of grace lookups (F-10). effectiveFor() calls graceForDate()
     * once per employee-per-day; a payroll run is O(employees × days), so an uncached
     * query here meant thousands of identical hits. This service is resolved once per
     * run, so caching by date collapses them to one query per distinct date.
     *
     * @var array<string, ?int>
     */
    private array $graceCache = [];

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

        if (! array_key_exists($dateStr, $this->graceCache)) {
            $this->graceCache[$dateStr] = DailyOverride::where('date', $dateStr)->first()?->grace_minutes;
        }

        return $this->graceCache[$dateStr];
    }

    /**
     * Upsert the grace override for a date. Pass null to clear the override.
     */
    public function upsert(string $date, ?int $graceMinutes): DailyOverride
    {
        unset($this->graceCache[$date]); // a write invalidates the memo for that date

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
