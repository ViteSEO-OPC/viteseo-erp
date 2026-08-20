<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'hr_payroll_settings';

    protected $fillable = [
        'frequency', 'deduction_timing', 'tax_timing', 'anchor_date', 'pay_delay_days',
        // Which cutoff the monthly allowance lands on. Same three values as the two
        // timings above; `every` is the even split that was previously the only behaviour.
        'allowance_timing',
        // Annual ceiling above which allowances become taxable (null = none).
        'other_benefits_annual_cap',
        // The regional minimum DAILY wage. Null = not configured, warns about nothing.
        // Versioned with the rest of this row, so a wage order's date is respected.
        'minimum_daily_wage',
    ];

    protected $casts = [
        'anchor_date' => 'date',
        'pay_delay_days' => 'integer',
        'other_benefits_annual_cap' => 'decimal:2',
        'minimum_daily_wage' => 'decimal:2',
    ];

    /**
     * The date the NEXT write should take effect from, consumed by the `updated` hook below.
     *
     * Set it immediately before saving when HR has given an explicit date; it is cleared as soon
     * as it is used, so it can never leak into an unrelated later write.
     */
    protected static ?string $pendingEffectiveFrom = null;

    /** Declare the effective date for the write that is about to happen. */
    public static function effectiveFrom(?string $date): void
    {
        static::$pendingEffectiveFrom = $date;
    }

    /** Every write to this row records a version — automatically. */
    protected static function booted(): void
    {
        static::updated(function (): void {
            $date = static::$pendingEffectiveFrom;
            static::$pendingEffectiveFrom = null;

            static::recordVersion($date);
        });

        // Any write invalidates the resolved-settings memo, including the `created` that
        // `current()` performs on a fresh install. `recordVersion()` covers the versioned path; this
        // covers the rest.
        static::saved(fn () => static::flushAsOfMemo());
    }

    /** The singleton company payroll settings row (created with defaults if absent). */
    public static function current(): self
    {
        // The exemption is stated HERE as well as in the column default, deliberately.
        // `firstOrCreate` returns a model built from the attributes it inserted — a DB-side
        // default is applied to the ROW but the in-memory instance still reads null, and the
        // caller is holding that instance. On a fresh install the first payroll run therefore saw
        // "no exemption" and withheld tax on the whole 13th month. Caught by B17's tests.
        return static::firstOrCreate([], [
            'frequency' => 'semi_monthly',
            'pay_delay_days' => 0,
            // ₱90,000, statutory (TRAIN): 13th month pay and other benefits.
            'other_benefits_annual_cap' => 90000,
        ]);
    }

    /** The settings that were in force on a date. */
    public static function asOf(?string $date = null): self
    {
        if ($date === null) {
            return static::current();
        }

        if (isset(static::$asOfMemo[$date])) {
            return static::$asOfMemo[$date];
        }

        $version = PayrollSettingVersion::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        // The migration backfills an opening row at 2000-01-01, so this should be unreachable.
        // Falling back to the live row is exactly the behaviour that existed before this feature,
        // so it can only ever be as wrong as the old code was — and a run must never fail here.
        if ($version === null) {
            return static::$asOfMemo[$date] = static::current();
        }

        // newFromBuilder(), NOT new/fill(): the payload is already in database shape, so the
        // casts must apply on the way OUT only.
        return static::$asOfMemo[$date] = (new static)->newFromBuilder($version->payload);
    }

    /**
     * Per-request memo. A payroll run resolves the settings once per employee for a single,
     * unchanging row; `recordVersion()` clears it so it cannot go stale within a request.
     *
     * @var array<string, self>
     */
    protected static array $asOfMemo = [];

    /**
     * Drop the memo. "Per-request" is only true in a request: anything with a longer life — a
     * queue worker, an artisan command, a test process — outlives it, and there the memo is a
     * cache that nothing was invalidating on a plain `->update()`. `recordVersion()` already
     * cleared it; this covers the writes that do not go through versioning.
     */
    public static function flushAsOfMemo(): void
    {
        static::$asOfMemo = [];
    }

    /**
     * Write the current row as the version in force from `$effectiveFrom` (today if omitted).
     *
     * `updateOrCreate` on the date so correcting a setting twice in one day amends that day's
     * version rather than leaving two rows racing on `orderByDesc('id')`.
     */
    public static function recordVersion(?string $effectiveFrom = null): string
    {
        $date = $effectiveFrom ?: now(config('app.timezone_local'))->toDateString();

        $payload = static::current()->getAttributes();
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        PayrollSettingVersion::updateOrCreate(
            ['effective_from' => $date],
            ['payload' => $payload, 'created_by' => auth()->id()],
        );

        static::$asOfMemo = [];

        return $date;
    }
}
