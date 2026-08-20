<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\DailyOverride;
use Illuminate\Support\Carbon;

/**
 * The day's rule overrides — one row per date, company-wide, both fields optional:
 *
 *   - grace_minutes         how long after the schedule start an arrival still counts on time
 *   - min_full_day_minutes  how many minutes actually worked make the day count WHOLE
 *
 * They are the same record because they are the same decision ("today's rules"), taken by the
 * same person, for the same date, usually in the same breath — a typhoon morning gets a longer
 * grace AND a shorter compensable day.
 */
class DailyOverrideService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Per-instance memo of the day's rules (F-10). effectiveFor() reads these once per
     * employee-per-day, and a payroll run is O(employees × days), so an uncached query here
     * meant thousands of identical hits. This service is resolved once per run, so caching by
     * date collapses them to one query per distinct date.
     *
     * Holds the whole row (not one field) so that adding a second rule did not double the
     * queries it was written to eliminate.
     *
     * @var array<string, ?DailyOverride>
     */
    private array $cache = [];

    /**
     * Get the override row for a date (null = no override set that day).
     */
    public function get(?string $date = null): ?DailyOverride
    {
        $date ??= now(config('app.timezone_local'))->toDateString();

        return $this->cached($date);
    }

    /**
     * Get the grace_minutes override for a date, or null if none set.
     */
    public function graceForDate(Carbon|string $date): ?int
    {
        return $this->cached($this->key($date))?->grace_minutes;
    }

    /**
     * The minutes that must actually be WORKED for this date to count as a whole day, or null
     * when no threshold was declared (the overwhelmingly common case — and the one where this
     * whole feature is a no-op).
     */
    public function minFullDayForDate(Carbon|string $date): ?int
    {
        return $this->cached($this->key($date))?->min_full_day_minutes;
    }

    /**
     * Upsert the day's rules. A null field clears that rule; when BOTH are null the row itself
     * goes, because a row asserting nothing is not the same as a row that was never written and
     * would otherwise accumulate one per date HR ever opened the dialog on.
     */
    public function upsert(string $date, ?int $graceMinutes, ?int $minFullDayMinutes = null): DailyOverride
    {
        unset($this->cache[$date]); // a write invalidates the memo for that date

        if ($graceMinutes === null && $minFullDayMinutes === null) {
            DailyOverride::where('date', $date)->delete();
            $this->audit->log('updated', 'Rules', "Daily rule overrides cleared for {$date}");

            return DailyOverride::make(['date' => $date, 'grace_minutes' => null, 'min_full_day_minutes' => null]);
        }

        $override = DailyOverride::updateOrCreate(
            ['date' => $date],
            ['grace_minutes' => $graceMinutes, 'min_full_day_minutes' => $minFullDayMinutes],
        );

        // Spell out BOTH rules in the audit line. "Rules updated" on its own is unauditable;
        // the whole reason this is logged is that it moves money for everyone on that date.
        $parts = [];
        $parts[] = $graceMinutes === null ? 'grace: default' : "grace: {$graceMinutes}m";
        $parts[] = $minFullDayMinutes === null
            ? 'full day: full schedule'
            : 'full day at: ' . $this->describeMinutes($minFullDayMinutes);

        $this->audit->log('updated', 'Rules', "Daily rule overrides set for {$date} (" . implode(', ', $parts) . ')', $override);

        return $override;
    }

    // --- helpers ---

    /** One query per distinct date, however many fields get asked for. */
    private function cached(string $date): ?DailyOverride
    {
        if (! array_key_exists($date, $this->cache)) {
            $this->cache[$date] = DailyOverride::where('date', $date)->first();
        }

        return $this->cache[$date];
    }

    private function key(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : $date;
    }

    /** 360 → "6h", 390 → "6h 30m". For the audit line only. */
    private function describeMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h}h" : "{$h}h {$m}m";
    }
}
