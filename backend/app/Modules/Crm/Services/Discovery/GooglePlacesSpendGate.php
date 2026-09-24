<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\ProviderSpendMeter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * May this Google Places request be paid for? — asked once per outbound request, answered before it
 * leaves.
 *
 * THE BUDGET IS NEVER IN THIS FILE. Not $10, not $50, not any amount: the figure enforced is read
 * LIVE from `crm_nerby_provider_settings.options.spend_limit_usd` on every single reservation, so a
 * super admin moving $10 to $50 in Settings → Integrations changes what the very next request is
 * held to — no deploy, no restart, no cache warm-up, no waiting for the period to roll. What this
 * code contains is the MECHANISM; the amount is configuration, and the only number config may hold
 * is the ceiling on what may be configured ({@see config/nerby.php} `nerby.spend.limit_max_usd`).
 *
 * FOUR CONFIGURED STATES, ALL OF THEM MEANINGFUL:
 *
 *   absent / NULL  unlimited — today's behaviour, byte for byte. Requests are still COUNTED, because
 *                  "what would we have spent" is exactly the question an operator needs answered
 *                  before setting a first budget.
 *   0              stop. Every Google Places request is refused, including the free ids-only ones:
 *                  zero means the integration is off at the spend layer, not "spend only what is
 *                  free". This is the environment-level kill switch the system has never had.
 *   > 0            enforced, translated to requests through the pricing model below.
 *   unreadable     refused. A budget we cannot parse is not permission to spend.
 *
 * WHY A DOLLAR LIMIT IS ENFORCED IN REQUESTS. Nothing can stop a dollar — a provider bills after the
 * fact, and Google publishes no low-latency spend API (its own billing export carries no latency
 * guarantee and can lag by days). The only thing that can be refused is a REQUEST. So the budget is
 * divided by a configured price to yield an allowance, and the allowance is what the meter holds.
 * That makes the dollar figure a MODEL, and the model is deliberately biased to stop early: see
 * `safety_factor`.
 *
 * FREE-TIER HANDLING IS A LIMIT ADJUSTMENT, NOT A SECOND COUNTER. The provider's free monthly
 * allowance is folded into the effective limit (`budget + free x unit_price`) rather than tracked as
 * a separate column of free-vs-paid requests. Both produce the identical allowance, and this one
 * keeps the reservation a SINGLE STATEMENT — which is the whole concurrency design.
 *
 * CONCURRENCY IS THE POINT, AND IT IS ONE UPDATE. Every concurrent discovery run, every queue slice
 * and every rate-limit retry serialises on one row via MySQL's row lock. There is no application
 * lock and no read-then-write window, because the check and the spend are the same statement: the
 * budget condition lives in the WHERE clause, which MySQL evaluates against pre-update values. Two
 * runs cannot both see "$9.99 spent" and both proceed.
 *
 * RETRIES COUNT BECAUSE OF WHERE THIS SITS, NOT BECAUSE ANYTHING COUNTS THEM. The gate is called
 * from the HTTP send path, so the orchestrator's rate-limit retry loop reserves again on every
 * attempt without knowing this class exists. A retry re-issues real billable requests; the meter
 * sees them because it is downstream of the decision to retry, not upstream.
 *
 * FAIL-CLOSED, WITH ONE DELIBERATE ASYMMETRY. If the meter cannot be read or written while a budget
 * IS configured, the request is refused: a guard that cannot count must not let spending through.
 * If no budget is configured, the same failure ALLOWS, because there is no budget to protect and
 * refusing would break a working feature to defend a limit nobody set. Fail-closed protects a
 * budget; it is not a reason to break discovery for deployments that have not opted in.
 *
 * GOOGLE PLACES ONLY. Nothing here is reachable from the OpenStreetMap path, which needs no
 * credential, costs nothing and resolves entirely inside the scraper. The default discovery
 * behaviour cannot be changed by anything in this file.
 */
class GooglePlacesSpendGate
{
    /** The request may proceed. The spend has already been reserved. */
    public const ALLOWED = 'allowed';

    /** The configured budget cannot cover this request. Nothing was reserved. */
    public const REFUSED_LIMIT = 'spend_limit_reached';

    /** A budget is configured but the meter could not be consulted. Nothing was reserved. */
    public const REFUSED_UNAVAILABLE = 'meter_unavailable';

    /** The metered provider. One credential, one Google Cloud project, one bill, one meter. */
    public const PROVIDER = 'google_places';

    /**
     * Field masks that buy nothing.
     *
     * Places API (New) prices a Text Search by the FIELDS it returns, and an ids-only response sits
     * in the Essentials tier, which carries no charge. Both probes in this module use exactly that
     * mask, so a connection test and a query-yield probe are counted but never billed.
     *
     * ANYTHING NOT LISTED HERE IS TREATED AS BILLABLE — including a mask this code does not
     * recognise. That is the conservative direction: mistaking a paid request for a free one spends
     * money the operator forbade, while mistaking a free request for a paid one merely stops early.
     */
    private const FREE_MASK_FIELDS = ['places.id', 'places.name', 'nextpagetoken'];

    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * Reserve the cost of one outbound request, or refuse it.
     *
     * Called BEFORE the HTTP call, never after: a reservation that happened after the money was
     * spent would be a record, not a guard.
     *
     * @param  string  $fieldMask  the outgoing `X-Goog-FieldMask`, which is what decides the SKU tier
     */
    public function reserve(string $fieldMask): string
    {
        try {
            $options = $this->options();
        } catch (Throwable $e) {
            // The configuration itself is unreachable. We cannot know whether a budget exists, and
            // "we could not check" must never read as "there is no limit".
            Log::warning('Google Places spend gate could not read configuration', ['class' => $e::class]);

            return self::REFUSED_UNAVAILABLE;
        }

        $limitUsd = $this->configuredLimitUsd($options);

        // An unreadable stored budget. Validation prevents it on write, so this is a hand-edited or
        // corrupted row — and a budget nobody can parse is not authorisation to spend.
        if ($limitUsd === false) {
            Log::warning('Google Places spend limit is not a readable amount; refusing.');

            return self::REFUSED_UNAVAILABLE;
        }

        // THE EXPLICIT KILL SWITCH, checked before pricing so it also stops the free ids-only
        // requests. Zero means "issue no Google Places requests", not "issue the free ones".
        if ($limitUsd !== null && $limitUsd <= 0.0) {
            return self::REFUSED_LIMIT;
        }

        $priceMicros = $this->priceMicros($fieldMask, $options);

        if ($priceMicros === null) {
            // The pricing model is unusable (a zero or negative price would make any budget buy
            // infinite requests). Harmless with no budget; fatal to a budget we were asked to hold.
            if ($limitUsd === null) {
                $priceMicros = 0;
            } else {
                Log::warning('Google Places price model is unusable; refusing while a budget is set.');

                return self::REFUSED_UNAVAILABLE;
            }
        }

        $limitMicros = $limitUsd === null ? null : $this->effectiveLimitMicros($limitUsd, $options);

        try {
            return $this->spend($priceMicros, $limitMicros) ? self::ALLOWED : self::REFUSED_LIMIT;
        } catch (Throwable $e) {
            Log::warning('Google Places spend meter unavailable', ['class' => $e::class]);

            // The asymmetry documented on the class: protect a budget that exists, do not invent one.
            return $limitMicros === null ? self::ALLOWED : self::REFUSED_UNAVAILABLE;
        }
    }

    /**
     * Give back a reservation for a request the provider did not bill.
     *
     * A 429 was refused before it was served and a connection failure never arrived, so charging for
     * either would let self-throttling quietly consume a budget. Deliberately NOT called for 4xx
     * rejections such as 400/401/403: whether those are billed is not something this code can know,
     * and over-counting spends less than allowed.
     *
     * BEST-EFFORT AND NEVER FATAL. A refund that fails leaves the meter over-counting, which errs
     * toward stopping early. It must never turn a provider error into a different error.
     */
    public function refund(string $fieldMask): void
    {
        try {
            $priceMicros = $this->priceMicros($fieldMask, $this->options()) ?? 0;

            // CASE rather than GREATEST(CAST(... AS SIGNED)): the counters are UNSIGNED, so
            // subtracting past zero is an out-of-range error rather than a negative number, and the
            // guard has to sit BEFORE the subtraction rather than around it. Portable too — these
            // suites run on SQLite while production is MySQL, and `CAST(x AS SIGNED)` is MySQL-only
            // spelling that SQLite rejects outright, so a MySQL-flavoured refund would have failed
            // silently in every test run.
            DB::update(
                'UPDATE crm_provider_spend_meter
                    SET requests_used = CASE WHEN requests_used >= 1 THEN requests_used - 1 ELSE 0 END,
                        micros_used   = CASE WHEN micros_used >= ? THEN micros_used - ? ELSE 0 END,
                        updated_at    = ?
                  WHERE provider = ? AND period = ?',
                [$priceMicros, $priceMicros, now(), self::PROVIDER, $this->period()]
            );
        } catch (Throwable $e) {
            Log::warning('Google Places spend refund failed', ['class' => $e::class]);
        }
    }

    /**
     * What the meter says right now — for the settings screen and diagnostics. Never for enforcement.
     *
     * Reports the budget in BOTH units on purpose. An operator who can see "$7.40 of $10.00 — 1,211
     * of 1,286 requests" can notice a wrong price constant; one who sees only dollars cannot, because
     * a stale price is invisible in the very number it distorts.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $options = $this->options();
        $limitUsd = $this->configuredLimitUsd($options);
        $period = $this->period();

        $meter = ProviderSpendMeter::query()
            ->where('provider', self::PROVIDER)
            ->where('period', $period)
            ->first();

        $unit = $this->billablePriceMicros($options);
        $usedMicros = (int) ($meter->micros_used ?? 0);
        $limitMicros = is_float($limitUsd) ? $this->effectiveLimitMicros($limitUsd, $options) : null;

        $free = max(0, (int) ($options['free_tier_requests'] ?? config('nerby.spend.free_tier_requests', 0)));
        $requestsUsed = (int) ($meter->requests_used ?? 0);
        $requestsAllowed = $limitMicros !== null && $unit !== null && $unit > 0
            ? intdiv($limitMicros, $unit)
            : null;

        // WHAT HAS ACTUALLY BEEN CHARGED, which is not what the meter holds. `micros_used` is GROSS
        // — every request at the unit price — because folding the free allowance into the limit is
        // what keeps the reservation one statement. The money question a human asks is net of that
        // allowance, so the subtraction happens here, once, at the display boundary. A screen that
        // showed gross would report spending during the free tier that no invoice will ever show.
        $billedMicros = max(0, $usedMicros - ($free * ($unit ?? 0)));

        return [
            'provider' => self::PROVIDER,
            'period' => $period,
            'unlimited' => $limitUsd === null,
            'unreadable' => $limitUsd === false,
            'limitUsd' => is_float($limitUsd) ? round($limitUsd, 2) : null,
            'billedUsd' => round($billedMicros / 1_000_000, 4),
            'remainingUsd' => is_float($limitUsd)
                ? round(max(0, (int) round($limitUsd * 1_000_000) - $billedMicros) / 1_000_000, 4)
                : null,
            'requestsUsed' => $requestsUsed,
            'requestsRemaining' => $requestsAllowed !== null ? max(0, $requestsAllowed - $requestsUsed) : null,
            // Surfaced so a screen can explain why requests climb while the charge stays at zero.
            'freeTierRequests' => $free,
            'requestsAllowed' => $requestsAllowed,
            // BOTH PRICES, because they are different numbers and only one of them is Google's.
            // `list` is what the provider charges; `effective` is that multiplied by the safety
            // factor, which is what the allowance was actually divided by. Reporting only the
            // effective rate would show $38.50 beside a $35.00 invoice line and read as an error;
            // reporting only the list rate would hide why the allowance is smaller than the
            // arithmetic suggests.
            'listPricePer1000Usd' => round(
                (float) ($options['price_per_1000_usd'] ?? config('nerby.spend.price_per_1000_usd', 0)),
                4,
            ),
            'effectivePricePer1000Usd' => $unit !== null ? round($unit / 1000, 4) : null,
            'periodEndsAt' => now($this->timezone())->endOfMonth()->toIso8601String(),
        ];
    }

    /**
     * THE RESERVATION. One conditional UPDATE; the budget test lives in the WHERE clause.
     *
     * MySQL evaluates WHERE against pre-update values and holds the row lock for the statement, so
     * the check and the spend cannot be separated by another transaction. Returns false when the
     * budget cannot cover the request — never when the row is merely missing, which is the first
     * request of a period and is handled by opening it and retrying exactly once.
     */
    private function spend(int $priceMicros, ?int $limitMicros): bool
    {
        $period = $this->period();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $affected = $limitMicros === null
                ? DB::update(
                    'UPDATE crm_provider_spend_meter
                        SET requests_used  = requests_used + 1,
                            micros_used    = micros_used + ?,
                            micros_allowed = NULL,
                            updated_at     = ?
                      WHERE provider = ? AND period = ?',
                    [$priceMicros, now(), self::PROVIDER, $period]
                )
                : DB::update(
                    // `micros_allowed` is refreshed to the live budget on every reservation, so the
                    // column always shows what this meter is currently being held to. It is a record
                    // of the limit, never the source of it — the binding value is the bound `?`.
                    'UPDATE crm_provider_spend_meter
                        SET requests_used  = requests_used + 1,
                            micros_used    = micros_used + ?,
                            micros_allowed = ?,
                            updated_at     = ?
                      WHERE provider = ? AND period = ?
                        AND micros_used + ? <= ?',
                    [$priceMicros, $limitMicros, now(), self::PROVIDER, $period, $priceMicros, $limitMicros]
                );

            if ($affected > 0) {
                return true;
            }

            // Zero rows means one of two very different things: the budget is spent, or this is the
            // first request of the period and there is no row yet. Distinguish by existence —
            // guessing would either leak a budget or refuse every first request of every month.
            if ($this->meterExists($period)) {
                return false;
            }

            $this->openPeriod($period);
        }

        return false;
    }

    private function meterExists(string $period): bool
    {
        return ProviderSpendMeter::query()
            ->where('provider', self::PROVIDER)
            ->where('period', $period)
            ->exists();
    }

    /**
     * Open this period's meter at zero.
     *
     * A duplicate-key collision here is the EXPECTED outcome under concurrency, not an error: two
     * requests raced to open the same period and the unique index did its job. The loser swallows it
     * and retries the UPDATE, which the winner's row now satisfies.
     */
    private function openPeriod(string $period): void
    {
        try {
            ProviderSpendMeter::query()->create([
                'provider' => self::PROVIDER,
                'period' => $period,
            ]);
        } catch (QueryException) {
            // Another process created it first. Intentionally silent.
        }
    }

    /**
     * The budget in force RIGHT NOW, in USD.
     *
     * Returns null for unlimited, false for a stored value that cannot be read as an amount, and a
     * float otherwise. Read fresh on every call — that is the dynamic-configuration requirement, and
     * it costs one indexed SELECT against a table with one row per provider.
     *
     * THE ENVIRONMENT CEILING IS APPLIED ON READ, not only on write. Validation bounds what a super
     * admin may save, but a row saved before the ceiling was lowered — or edited outside the
     * application — would otherwise escape it. Clamping here makes `NERBY_SPEND_LIMIT_MAX_USD` a
     * real bound on what can be SPENT rather than merely on what can be typed.
     *
     * @param  array<string, mixed>  $options
     */
    private function configuredLimitUsd(array $options): float|false|null
    {
        $raw = $options['spend_limit_usd'] ?? null;

        if ($raw === null || $raw === '') {
            $raw = config('nerby.spend.limit_default_usd');
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            return false;
        }

        $limit = max(0.0, (float) $raw);
        $ceiling = (float) config('nerby.spend.limit_max_usd', 0);

        return $ceiling > 0 ? min($limit, $ceiling) : $limit;
    }

    /**
     * The budget converted to micros, with the provider's free allowance folded in.
     *
     * `budget + (free_requests x unit_price)` is algebraically the allowance formula
     * `free + budget/price`, expressed so that every request can be charged the same unit price and
     * the reservation stays one statement.
     *
     * @param  array<string, mixed>  $options
     */
    private function effectiveLimitMicros(float $limitUsd, array $options): int
    {
        $budgetMicros = (int) round($limitUsd * 1_000_000);

        $free = max(0, (int) ($options['free_tier_requests'] ?? config('nerby.spend.free_tier_requests', 0)));
        $unit = $this->billablePriceMicros($options) ?? 0;

        return $budgetMicros + ($free * $unit);
    }

    /**
     * What this specific request costs, in micros — 0 for a mask the provider does not charge for.
     *
     * Null means the price model is unusable, which the caller treats very differently depending on
     * whether a budget exists.
     *
     * @param  array<string, mixed>  $options
     */
    private function priceMicros(string $fieldMask, array $options): ?int
    {
        return $this->isFreeMask($fieldMask) ? 0 : $this->billablePriceMicros($options);
    }

    /**
     * The unit price of one billable request, in micros.
     *
     * USD per 1,000 → micros per 1 is x1,000,000 / 1,000 = x1,000, so $35.00 at a 1.10 safety factor
     * is 38,500 micros ($0.0385). Integers throughout: this is the arithmetic `decimal(12,2)` cannot
     * do, since $0.035 rounds to a cent and the provider's high-volume rate rounds to nothing at all.
     *
     * @param  array<string, mixed>  $options
     */
    private function billablePriceMicros(array $options): ?int
    {
        $per1000 = (float) ($options['price_per_1000_usd'] ?? config('nerby.spend.price_per_1000_usd', 0));
        $safety = (float) ($options['safety_factor'] ?? config('nerby.spend.safety_factor', 1.0));

        // A price of zero or less would make any budget buy an unbounded number of requests, and a
        // safety factor below 1 would enlarge the allowance instead of protecting it.
        if ($per1000 <= 0 || $safety < 1.0) {
            return null;
        }

        return (int) round($per1000 * $safety * 1000);
    }

    /**
     * Does this field mask buy only things the provider gives away?
     *
     * An empty mask is NOT treated as free — an unspecified request is not a provably free one.
     */
    private function isFreeMask(string $fieldMask): bool
    {
        $fields = array_values(array_filter(
            array_map(static fn (string $f): string => mb_strtolower(trim($f)), explode(',', $fieldMask)),
            static fn (string $f): bool => $f !== '',
        ));

        if ($fields === []) {
            return false;
        }

        foreach ($fields as $field) {
            if (! in_array($field, self::FREE_MASK_FIELDS, true)) {
                return false;
            }
        }

        return true;
    }

    /** The provider's live configuration bag. Re-read per reservation; see `configuredLimitUsd()`. */
    private function options(): array
    {
        return $this->settings->for(NerbyProvider::GOOGLE_PLACES)->options ?? [];
    }

    /**
     * The meter's period key.
     *
     * Pinned to its own timezone rather than `app.timezone`: Asia/Manila rolls the month over about
     * eight hours before the provider's own reset, and a period key that advances early hands out a
     * second budget. A rollover bug OPENS the gate, which is why this is configuration and not an
     * expression.
     */
    private function period(): string
    {
        return now($this->timezone())->format('Y-m');
    }

    private function timezone(): string
    {
        return (string) config('nerby.spend.period_timezone', 'UTC');
    }
}
