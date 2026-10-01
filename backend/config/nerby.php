<?php

/**
 * Nearby discovery — MARKET SETTINGS, not business logic.
 *
 * Everything here is a deployment fact rather than a product decision, and it lives in config for
 * exactly one reason: no place name, niche or search phrase may be pinned inside application logic.
 * A discovery product that opens a second market must be a configuration change, never a code change.
 *
 * There is deliberately no area list, no city list and no category list here. Which areas get swept
 * is runtime data (the discovery target source); which categories exist is the provider's own
 * vocabulary. Putting either here would recreate the hardcoded geography this file exists to prevent.
 */
return [
    /*
     * Region/language biasing for structured place providers (Google Places `regionCode` /
     * `languageCode`). A market setting: it shapes how a provider interprets an ambiguous query,
     * and it is NOT a target area — the area always arrives per request.
     */
    'region_code' => env('NERBY_REGION_CODE', 'PH'),
    'language_code' => env('NERBY_LANGUAGE_CODE', 'en'),

    /*
     * The credential probe's query.
     *
     * A connection test only has to prove that a key authenticates and that the endpoint answers, so
     * this is a deliberately GENERIC word carrying no place and no product category. It used to be a
     * real demo search ("pharmacy in <city>"), which quietly turned a test input into a runtime
     * constant — the thing a discovery system must never do, because the sample then reads like an
     * intended default to everyone who finds it later.
     */
    'probe_query' => env('NERBY_PROBE_QUERY', 'business'),

    /*
     * ── SPEND GUARDRAIL (Google Places) ────────────────────────────────────────────────────────
     *
     * DEPLOYMENT FENCES ONLY. The budget itself is NOT here and must never be: it is
     * `crm_nerby_provider_settings.options.spend_limit_usd`, set by a platform super admin in
     * Settings → Integrations and read live on every request, so management can move $10 to $50
     * without a deploy, a restart, or a line of code. What lives here is what an ENVIRONMENT is
     * allowed to permit — the bound on that dial, and the pricing model it is translated through.
     *
     * The division matters. A budget in config would need a deploy to change, which is how a
     * guardrail ends up disabled "temporarily". A ceiling in the database would be editable by the
     * same person it is meant to bound.
     */
    'spend' => [
        /*
         * The most a super admin may set `spend_limit_usd` to from the UI. A ceiling, NOT a budget.
         *
         * $500 is ~3.4x the heaviest day this system has ever run (5,203 Enterprise requests,
         * ~$147 on 2026-09-22) and 50x a $10 budget, so no realistic approval is blocked by it —
         * while a decimal slip that turns $50 into $5,000 is refused. Raising it is a deploy, which
         * is the right amount of friction for "we now intend to spend more than this on discovery".
         */
        'limit_max_usd' => (float) env('NERBY_SPEND_LIMIT_MAX_USD', 500),

        /*
         * Applied when no budget has been configured. NULL — unlimited — is deliberate and is what
         * makes shipping the gate a no-op: enforcement begins when a human sets a number, not when
         * this code deploys. An environment that wants a floor sets this; it is still bounded by
         * `limit_max_usd`.
         */
        'limit_default_usd' => is_numeric(env('NERBY_SPEND_LIMIT_DEFAULT_USD'))
            ? (float) env('NERBY_SPEND_LIMIT_DEFAULT_USD')
            : null,

        /*
         * THE TRANSLATION RATE: what 1,000 billable Text Search requests cost, in USD.
         *
         * The gate enforces dollars, but nothing can stop a dollar — it can only refuse a REQUEST.
         * This is the constant that turns one into the other, and it is the single number that makes
         * the guardrail wrong if it goes stale. $35.00 is Google's published Places API Text Search
         * ENTERPRISE rate, which is the tier our production field mask buys (`places.websiteUri` and
         * `places.nationalPhoneNumber` are Enterprise fields). Per-provider override:
         * `options.price_per_1000_usd`.
         */
        'price_per_1000_usd' => (float) env('NERBY_SPEND_PRICE_PER_1000_USD', 35.00),

        /*
         * Requests the provider does not charge for each period. Folded into the effective limit
         * rather than tracked separately, which keeps the reservation one statement.
         *
         * 1,000/month is Google's Enterprise free allowance. It is not a rounding detail: at a $10
         * budget it is the difference between 285 requests and 1,285.
         */
        'free_tier_requests' => (int) env('NERBY_SPEND_FREE_TIER_REQUESTS', 1000),

        /*
         * Multiplier applied to the price before the budget is divided by it. >= 1.
         *
         * Absorbs everything the model cannot know exactly — SKU tier drift, rounding, a rate change
         * we have not noticed yet. 1.10 costs a 9% smaller allowance and buys the guarantee that the
         * error is in the direction of stopping EARLY. A guard that over-estimates spend refuses a
         * request it could have afforded; one that under-estimates spends money it was told not to.
         */
        'safety_factor' => (float) env('NERBY_SPEND_SAFETY_FACTOR', 1.10),

        /*
         * The timezone the period boundary is computed in.
         *
         * A ROLLOVER BUG OPENS THE GATE — a period key that advances early hands out a fresh budget
         * — so this is pinned rather than inherited from `app.timezone` (Asia/Manila), which would
         * roll the meter over ~8 hours before Google's month does and grant a bonus budget every
         * month. UTC matches how the provider's free allowance resets.
         */
        'period_timezone' => (string) env('NERBY_SPEND_PERIOD_TIMEZONE', 'UTC'),
    ],
];
