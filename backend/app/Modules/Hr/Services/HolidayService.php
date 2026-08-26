<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Holiday sets per country + year. Dates come from Nager.Date (free, no key), but
 * Nager can't supply the PH Regular / Special (Non-Working) / Special Working
 * classification — so we classify PH holidays ourselves (the Regular list is fixed
 * by law) and default + flag other countries for HR to review. HR can edit any of
 * it; "refresh" re-pulls a year WITHOUT overwriting HR's edits or manual additions.
 */
class HolidayService
{
    /** The company's home country — drives the leave-days holiday exclusion. */
    public const HOME_COUNTRY = 'PH';

    public const TYPES = ['regular', 'special_non_working', 'special_working'];

    /** Only these types are non-working days (special_working is a working day). */
    public const NON_WORKING_TYPES = ['regular', 'special_non_working'];

    /**
     * PRECISE names of the PH legally-fixed Regular Holidays (RA 9492 / EO 292) as
     * Nager spells them. A match → `regular` (100% confident). Anything NOT matched
     * is left UNCLASSIFIED for HR to set — we never guess Special vs Special-Working.
     * Keywords are precise on purpose: "christmas day" (not "christmas", which would
     * wrongly catch "Christmas Eve"); "ramadhan"/"sacrifice" catch Nager's names for
     * Eid'l Fitr ("Feast of Ramadhan") / Eid'l Adha ("Feast of Sacrifice").
     */
    private const PH_REGULAR_KEYWORDS = [
        "new year's day", 'maundy thursday', 'good friday', 'day of valor', 'kagitingan',
        'labour day', 'labor day', 'independence day', 'national heroes day',
        'bonifacio day', 'christmas day', 'rizal day', 'ramadhan', 'sacrifice',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Per-request memo of typedHolidaysBetween(), keyed by range. A payroll run asks
     * for the same cycle's holidays once per employee, plus once each in AttendanceService and
     * ViolationService. Every write below clears it.
     *
     * @var array<string, array<string, string|null>>
     */
    private array $typedMemo = [];

    /**
     * Per-request memo of namesBetween(), keyed the same way and cleared alongside it.
     *
     * @var array<string, array<string, string>>
     */
    private array $nameMemo = [];

    /** Drop the memo — called from every path that changes holiday data. */
    private function forgetMemo(): void
    {
        $this->typedMemo = [];
        $this->nameMemo = [];
    }

    public function listSets(): Collection
    {
        return HolidaySet::withCount('holidays')->orderByDesc('year')->orderBy('country_code')->get();
    }

    public function holidaysFor(HolidaySet $set): Collection
    {
        return $set->holidays()->get();
    }

    /** Create a set for a country+year, loading dates from Nager.Date. */
    public function createSet(string $country, int $year): HolidaySet
    {
        $country = strtoupper($country);

        if (HolidaySet::where('country_code', $country)->where('year', $year)->exists()) {
            throw ValidationException::withMessages([
                'country_code' => ["A {$country} {$year} set already exists — open it or refresh it instead."],
            ]);
        }

        $rows = $this->fetchNager($country, $year);

        $set = HolidaySet::create([
            'country_code' => $country,
            'year' => $year,
            'source' => 'nager',
            'refreshed_at' => now(),
        ]);

        foreach ($rows as $row) {
            [$type, $needsReview] = $this->classify($country, $row['name'] ?? '');
            Holiday::create([
                'holiday_set_id' => $set->id,
                'name' => $row['localName'] ?? $row['name'] ?? 'Holiday',
                'date' => $row['date'],
                'type' => $type,
                'source' => 'nager',
                'needs_review' => $needsReview,
            ]);
        }

        $this->forgetMemo();

        $this->audit->log('created', 'Holidays', "Loaded {$set->country_code} {$set->year} holidays", $set);

        $this->recomputeFlagsForYear($set->year);

        return $set->load('holidays')->loadCount('holidays');
    }

    /**
     * Re-pull the year from Nager. Adds any newly-declared holidays but NEVER
     * overwrites a holiday HR has edited or one they added manually (firstOrCreate).
     */
    public function refresh(HolidaySet $set): HolidaySet
    {
        $rows = $this->fetchNager($set->country_code, $set->year);

        foreach ($rows as $row) {
            [$type, $needsReview] = $this->classify($set->country_code, $row['name'] ?? '');
            // Match by date so a re-pull never duplicates or overwrites an existing
            // holiday (incl. HR edits/renames) — it only ADDS newly-declared dates.
            Holiday::firstOrCreate(
                ['holiday_set_id' => $set->id, 'date' => $row['date']],
                [
                    'name' => $row['localName'] ?? $row['name'] ?? 'Holiday',
                    'type' => $type,
                    'source' => 'nager',
                    'needs_review' => $needsReview,
                ],
            );
        }

        $set->update(['refreshed_at' => now()]);

        $this->forgetMemo();

        $this->audit->log('updated', 'Holidays', "Refreshed {$set->country_code} {$set->year} holiday set", $set);

        $this->recomputeFlagsForYear($set->year);

        return $set->load('holidays')->loadCount('holidays');
    }

    public function deleteSet(HolidaySet $set): void
    {
        // HARD delete (deliberate exception to the soft-delete rule): a holiday set
        // is regenerable external reference data — one "Load holidays" re-pulls the
        // same dates from Nager. Soft-deleting would leave the (country_code, year)
        // unique row in place and block reloading that same country/year. The FK's
        // cascadeOnDelete removes the child holidays with it.
        $year = $set->year; // read before the delete — the model is gone after it

        $set->forceDelete();

        $this->forgetMemo();

        $this->audit->log('removed', 'Holidays', "Removed the {$set->country_code} {$set->year} holiday set", $set);

        $this->recomputeFlagsForYear($year);
    }

    /** @param array<string, mixed> $data */
    public function addHoliday(HolidaySet $set, array $data): Holiday
    {
        $holiday = $set->holidays()->create([
            'name' => $data['name'],
            'date' => $data['date'],
            'type' => $data['type'],
            'source' => 'manual',
            'needs_review' => false,
        ]);

        $this->forgetMemo();

        $this->audit->log('created', 'Holidays', "Added holiday '{$holiday->name}'", $holiday);

        $this->recomputeFlagsForDates([$holiday->date->toDateString()]);

        return $holiday;
    }

    /** @param array<string, mixed> $data */
    public function updateHoliday(Holiday $holiday, array $data): Holiday
    {
        // An edit can MOVE the date. The month it left has to be rebuilt as well as the one it
        // landed in, or the old date keeps behaving like a holiday.
        $wasOn = $holiday->date->toDateString();

        $holiday->update([
            'name' => $data['name'],
            'date' => $data['date'],
            'type' => $data['type'],
            'needs_review' => false, // HR reviewed it by editing
        ]);

        $this->forgetMemo();

        $this->audit->log('updated', 'Holidays', "Updated holiday '{$holiday->name}'", $holiday);

        $this->recomputeFlagsForDates([$wasOn, $holiday->date->toDateString()]);

        return $holiday;
    }

    public function deleteHoliday(Holiday $holiday): void
    {
        $date = $holiday->date->toDateString();

        $holiday->delete();

        $this->forgetMemo();

        $this->audit->log('removed', 'Holidays', "Removed holiday '{$holiday->name}'", $holiday);

        $this->recomputeFlagsForDates([$date]);
    }

    /**
     * Item 11 — nobody is expected in on a non-working holiday, so adding, moving or removing
     * one turns absences into non-events on that date and back. Rebuilt at the write; the
     * attendance board that used to recompute on every read no longer does.
     *
     * Resolved lazily rather than injected: ViolationService takes this service as a
     * constructor argument, so injecting it back would close a container cycle.
     *
     * @param  list<string>  $dates
     */
    private function recomputeFlagsForDates(array $dates): void
    {
        $violations = app(ViolationService::class);

        foreach (array_unique(array_map(fn (string $d) => substr($d, 0, 7), $dates)) as $month) {
            $violations->recompute($month);
        }
    }

    /** A whole year's worth of months — set-level loads, refreshes and removals. */
    private function recomputeFlagsForYear(int $year): void
    {
        // recompute() returns immediately for a month that has not started, so a set loaded
        // for next year costs twelve cheap no-ops rather than twelve scans.
        app(ViolationService::class)->recomputeRange("{$year}-01-01", "{$year}-12-31");
    }

    /**
     * Non-working holiday dates (home country) in a range — for leave-days math.
     * Special-working days are excluded (they're normal working days).
     *
     * @return array<int, string>  'Y-m-d' strings
     */
    public function nonWorkingDatesBetween(Carbon $start, Carbon $end): array
    {
        return Holiday::whereIn('type', self::NON_WORKING_TYPES)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('set', fn ($q) => $q->where('country_code', self::HOME_COUNTRY))
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    /**
     * Home-country holidays in the range as [date 'Y-m-d' => type] — used by payroll to
     * price the premium for working on a holiday (regular vs special_non_working).
     *
     * @return array<string, string>
     */
    public function typedHolidaysBetween(Carbon $start, Carbon $end): array
    {
        $key = $start->toDateString() . '|' . $end->toDateString();
        if (isset($this->typedMemo[$key])) {
            return $this->typedMemo[$key];
        }

        return $this->typedMemo[$key] = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('set', fn ($q) => $q->where('country_code', self::HOME_COUNTRY))
            ->get(['date', 'type'])
            ->mapWithKeys(fn ($h) => [Carbon::parse($h->date)->toDateString() => $h->type])
            ->all();
    }

    /**
     * Home-country holidays in the range as [date 'Y-m-d' => name] — the WORDING payroll needs
     * to say WHICH holiday a premium was earned on. Kept apart from typedHolidaysBetween()
     * rather than folded into it: that map's values are the type string itself, matched with
     * `match` and `in_array` in a dozen places, and widening it to a pair would touch all of them
     * to serve one caller. Memoized on the same per-request basis, so a payroll run asking once
     * per employee still costs one query.
     *
     * @return array<string, string>
     */
    public function namesBetween(Carbon $start, Carbon $end): array
    {
        $key = $start->toDateString() . '|' . $end->toDateString();
        if (isset($this->nameMemo[$key])) {
            return $this->nameMemo[$key];
        }

        return $this->nameMemo[$key] = Holiday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('set', fn ($q) => $q->where('country_code', self::HOME_COUNTRY))
            ->get(['date', 'name'])
            ->mapWithKeys(fn ($h) => [Carbon::parse($h->date)->toDateString() => (string) $h->name])
            ->all();
    }

    /**
     * Home-country holidays in the range that HR has NOT classified yet (type NULL), as
     * [date 'Y-m-d' => name]. Payroll prices a day off this classification — a regular
     * holiday pays a different premium from a special day, and an unclassified one pays
     * nothing at all — so a run covering one of these would quietly under-pay. The pay
     * run refuses to start while any exist (see RunPayrollRequest).
     *
     * @return array<string, string>
     */
    public function unclassifiedBetween(Carbon $start, Carbon $end): array
    {
        return Holiday::whereNull('type')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('set', fn ($q) => $q->where('country_code', self::HOME_COUNTRY))
            ->orderBy('date')
            ->get(['date', 'name'])
            ->mapWithKeys(fn ($h) => [Carbon::parse($h->date)->toDateString() => $h->name])
            ->all();
    }

    /** How many home-country holidays still need HR's review (for the Holidays page badge). */
    public function needsReviewCount(): int
    {
        return Holiday::where(fn ($q) => $q->whereNull('type')->orWhere('needs_review', true))
            ->whereHas('set', fn ($q) => $q->where('country_code', self::HOME_COUNTRY))
            ->count();
    }

    // --- helpers ---

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchNager(string $country, int $year): array
    {
        try {
            $res = Http::timeout(15)->acceptJson()
                ->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/" . strtoupper($country));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'country_code' => ['Could not reach the holiday provider. Check the connection or add holidays manually.'],
            ]);
        }

        if ($res->failed()) {
            throw ValidationException::withMessages([
                'country_code' => ["No holidays found for '{$country}' {$year} (check the country code), or the provider is down."],
            ]);
        }

        // Nationwide holidays only (skip region/county-specific ones).
        return collect($res->json())->where('global', true)->values()->all();
    }

    /**
     * Only assert a type we're 100% sure of: a PH holiday whose name matches the
     * legally-fixed Regular list → `regular`. Everything else — unrecognised PH
     * holidays AND all non-PH holidays (Nager gives no PH-style type) — is left
     * UNCLASSIFIED (null) and flagged for HR to set. No guessing.
     *
     * @return array{0:?string, 1:bool}  [type|null, needs_review]
     */
    private function classify(string $country, string $name): array
    {
        if (strtoupper($country) === self::HOME_COUNTRY) {
            $lower = strtolower($name);
            foreach (self::PH_REGULAR_KEYWORDS as $kw) {
                if (str_contains($lower, $kw)) {
                    return ['regular', false];
                }
            }
        }

        return [null, true]; // unclassified — HR decides
    }
}
