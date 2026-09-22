<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\DailyOverride;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** The day's rule overrides — one row per date, company-wide, both fields optional: */
class DailyOverrideService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PayrollFreezeService $freeze,
    ) {}

    /**
     * Per-instance memo of the day's rules. effectiveFor() reads these once per
     * employee-per-day, and a payroll run is O(employees × days), so an uncached query here
     * meant thousands of identical hits. This service is resolved once per run, so caching by
     * date collapses them to one query per distinct date.
     *
     * @var array<string, ?DailyOverride>
     */
    private array $cache = [];

    /** Get the override row for a date (null = no override set that day). */
    public function get(?string $date = null): ?DailyOverride
    {
        $date ??= now(config('app.timezone_local'))->toDateString();

        return $this->cached($date);
    }

    /**
     * Warm the memo for a whole date RANGE in one query.
     *
     * The memo below already collapses repeat reads of the SAME date, but a scan that walks a
     * month still pays one query per distinct day — the violation recompute walks ~30 days and
     * spent ~25 queries here just answering "no override that day". Dates with no row are cached
     * as null too, so the misses stop costing anything as well.
     *
     * Callers that walk a range should warm it first; everything else keeps working unchanged,
     * because `cached()` still falls back to a single-date lookup on a miss.
     */
    public function warmRange(Carbon|string $from, Carbon|string $to): void
    {
        $start = Carbon::parse($this->key($from));
        $end = Carbon::parse($this->key($to));

        if ($start->gt($end)) {
            return;
        }

        $found = DailyOverride::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (DailyOverride $o) => $this->key($o->date));

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();

            // Never overwrite an entry already in the memo: a write during this request cleared
            // it deliberately, and re-seeding from a stale read would undo that.
            if (! array_key_exists($key, $this->cache)) {
                $this->cache[$key] = $found->get($key);
            }
        }
    }

    /** Get the grace_minutes override for a date, or null if none set. */
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
     *
     * A PAST date is allowed, and is the point: a typhoon morning, a transit strike or a
     * DOLE-declared suspension is known only after people have already clocked in. That is why
     * this is not gated by `AppliesFromToday` the way the blanket rules are — a policy backdate
     * reprices every payslip in reach at once and silently, while this is one date, one knob, and
     * the day HR is looking straight at. What it borrows from those rules instead is the
     * discipline of saying what would move BEFORE it moves: see the confirm-once below.
     *
     * @param  bool  $acknowledgeRepricing  HR has seen which completed runs this would reprice
     */
    public function upsert(
        string $date,
        ?int $graceMinutes,
        ?int $minFullDayMinutes = null,
        bool $acknowledgeRepricing = false,
    ): DailyOverride {
        // Read BEFORE the memo is dropped, and before anything is written: what changed decides
        // what has to be rebuilt afterwards, and a rebuild of something that did not move is a
        // write nobody asked for.
        $existing = $this->cached($date);
        $graceMoved = ($existing?->grace_minutes) !== $graceMinutes;
        $fullDayMoved = ($existing?->min_full_day_minutes) !== $minFullDayMinutes;

        if (! $graceMoved && ! $fullDayMoved) {
            // Nothing is moving. Return the row as it stands rather than rewriting it, logging a
            // change that did not happen, and re-judging a day on the strength of it.
            return $existing ?? DailyOverride::make([
                'date' => $date,
                'grace_minutes' => null,
                'min_full_day_minutes' => null,
            ]);
        }

        $this->assertRepricingSeen($date, $acknowledgeRepricing);

        unset($this->cache[$date]); // a write invalidates the memo for that date

        if ($graceMinutes === null && $minFullDayMinutes === null) {
            DailyOverride::where('date', $date)->delete();
            $this->audit->log('updated', 'Rules', "Daily rule overrides cleared for {$date}");
            $this->rebuild($date, $graceMoved);

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

        $this->rebuild($date, $graceMoved);

        return $override;
    }

    /**
     * Name the completed runs this date sits inside, once, and let HR decide.
     *
     * A refusal would be the wrong answer here — the legitimate use of a past-dated override IS
     * the period that has already been run — but so would silence: the payslips those runs
     * produced were priced under the old grace, and only a re-run would pick this up. The same
     * confirm-once shape a backdated contribution rate and a backdated salary already use.
     *
     * Note what this does NOT do: it reprices nothing. A disbursed payslip is never recomputed
     * and a locked run refuses outright, both of which still hold after this returns.
     */
    private function assertRepricingSeen(string $date, bool $acknowledged): void
    {
        if ($acknowledged) {
            return;
        }

        $affected = $this->freeze->completedRunsCovering($date);

        if ($affected->isEmpty()) {
            return;
        }

        $periods = $affected
            ->map(fn ($r) => $r->cycle_start->format('M j') . '–' . $r->cycle_end->format('M j, Y'))
            ->implode(', ');

        throw ValidationException::withMessages([
            'date' => [
                'This date falls inside ' . $affected->count() . ' completed payroll run'
                    . ($affected->count() === 1 ? '' : 's') . " ({$periods}), which "
                    . ($affected->count() === 1 ? 'was' : 'were') . ' priced under the old rules. '
                    . 'Changing it now re-judges the day, but the payslips only move if you '
                    . 're-run that period — and already-disbursed ones never will. Confirm to '
                    . 'set it anyway.',
            ],
        ]);
    }

    /**
     * Re-judge the day now that its rules have moved.
     *
     * ONLY THE GRACE NEEDS THIS. It decides who was late, and lateness is stored on the
     * attendance row as well as derived for pay — so the stored side has to be rebuilt or the
     * two disagree (see `AttendanceService::restatusDate()` for what that looks like). The
     * minimum-hours bar has no such mirror: `creditedFullDay()` re-derives it from the punches
     * on every ask and nothing records the verdict, so it is already retroactive with no help
     * from here.
     *
     * The violation ladders (late, absence, missed_time_out, long_break) judge none of this
     * directly — they read the stored `status`. So they can only reach a different answer when a
     * status actually moved, which is the gate below rather than the mere fact of a save: a
     * recompute walks the whole month for every employee, and neither a future day's grace nor
     * a change to the full-day bar can alter a single rung of it.
     *
     * Resolved through the container rather than injected: ScheduleService takes THIS service as
     * a constructor argument and AttendanceService takes ScheduleService, so injecting it back
     * would close a cycle. Same reason — and the same shape — as AttendancePolicyService's own
     * violation rebuild after a policy save.
     */
    private function rebuild(string $date, bool $graceMoved): void
    {
        if (! $graceMoved) {
            return;
        }

        $changed = app(AttendanceService::class)->restatusDate($date);

        if ($changed === 0) {
            return;
        }

        $this->audit->log(
            'updated',
            'Attendance',
            "Re-judged {$changed} attendance record(s) on {$date} under the day's new grace period",
        );

        app(ViolationService::class)->recomputeRange($date, $date);
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
