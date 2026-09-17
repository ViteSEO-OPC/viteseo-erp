<?php

/**
 * Stage 2 on-demand discovery — EXECUTION settings for invoking the canonical Python engine.
 *
 * This file describes HOW to run the discovery engine (where the interpreter is, where the engine
 * lives, how long a task may take). It deliberately contains:
 *
 *   - NO API credential of any kind. The Google Places key lives in `crm_nerby_provider_settings`
 *     and is read through {@see \App\Modules\Crm\Services\Discovery\NerbyProviderSettingService}.
 *     There is exactly one Google credential store in this system and this is not it.
 *   - NO area list, NO city list, NO niche list. Market settings live in `config/nerby.php`, which
 *     already explains why pinning geography or categories in config recreates the hardcoding the
 *     product exists to avoid. Scope arrives per request.
 *
 * `crm_token` is the one secret named here, and it is NOT a provider credential: it is the Sanctum
 * bearer token the engine uses to call BACK into this CRM (`POST /leads/discovered`). It is read at
 * execution time inside the worker and passed to the subprocess as an environment variable — never
 * placed in a queue payload, never logged, never returned to the browser.
 */
return [
    /*
     * The interpreter and the engine. Both are baked into the runtime image by docker/php/Dockerfile
     * and mounted read-only respectively, so these defaults are correct inside the container and are
     * overridable for any other host.
     */
    'python' => env('DISCOVERY_PYTHON_BIN', '/opt/scraper-venv/bin/python'),
    'path' => env('DISCOVERY_SCRAPER_PATH', '/scraper'),

    /*
     * Where the engine sends what it discovers. Defaults to this API over loopback, which is also
     * what keeps `--commit` permitted: the engine REFUSES to commit to a non-loopback host unless
     * `allow_remote_commit` is explicitly set (scraper/scraper/safety.py). That refusal is a
     * production guard, not an obstacle — leave it on unless an operator has decided otherwise.
     */
    'crm_base_url' => env('DISCOVERY_CRM_BASE_URL', 'http://localhost:8001/api/v1/crm'),
    'crm_token' => env('DISCOVERY_CRM_TOKEN', ''),
    'allow_remote_commit' => (bool) env('DISCOVERY_ALLOW_REMOTE_COMMIT', false),

    /*
     * Per-TASK wall clock. The queue worker's own ceiling is 900s (see docker-compose.yml), so a
     * single hung area must not be allowed to consume the whole run's budget: one task that stalls
     * is one task that fails, and the run continues.
     */
    'task_timeout' => (int) env('DISCOVERY_TASK_TIMEOUT', 180),

    /*
     * Volume fences. These are BILLING and INFRASTRUCTURE limits, not discovery boundaries: they cap
     * how much one click may spend, never which businesses are reachable. `limit_per_task` is capped
     * at 60 because that is the structured provider's own ceiling (PAGE_SIZE 20 × MAX_PAGES 3) —
     * asking for more buys nothing.
     */
    'max_tasks_per_run' => (int) env('DISCOVERY_MAX_TASKS_PER_RUN', 100),
    'default_limit_per_task' => (int) env('DISCOVERY_DEFAULT_LIMIT_PER_TASK', 20),
    'max_limit_per_task' => 60,

    /*
     * ── COUNTRY ────────────────────────────────────────────────────────────────────────────────
     *
     * The countries discovery may generate leads in. THE PHILIPPINES IS THE ONLY SUPPORTED ONE, and
     * this list is what says so — one place, in data, rather than a string repeated across a
     * validator, a UI and an engine flag.
     *
     * IT IS A LIST BECAUSE THE RESTRICTION IS A PRODUCT DECISION, NOT A LIMITATION OF THE CODE.
     * Everything downstream reads the country from the run: the geocoder is constrained to it, the
     * national boundary is loaded for it, and the UI offers it. Supporting a second country is a
     * decision to make — it needs that country's boundary dataset vendored and checksummed before it
     * could mean anything — not a refactor. Adding a code here without that dataset would produce
     * runs with no boundary to enforce, which is why the validator checks THIS list and the boundary
     * is loaded separately.
     */
    'countries' => ['PH'],
    'default_country' => 'PH',

    /*
     * ── WEBSITE INTELLIGENCE LIFECYCLE ─────────────────────────────────────────────────────────
     *
     * How much of each queue one scheduled tick may work through. Bounded so the amount of outbound
     * traffic a tick produces is fixed however large the backlog grows: `audit_limit` is one page
     * fetch per lead, `search_limit` is DNS probes plus at most one shared Overpass query per batch.
     *
     * 25 hourly is ~600 leads a day per queue — enough to drain a backlog of a few thousand in days
     * rather than weeks, and gentle enough to be a good citizen on the free infrastructure the
     * evidence tiers depend on. Raising these is an operational decision about traffic, never about
     * correctness: the queues are ordered never-checked-first, so a smaller number is slower, never
     * less complete.
     */
    'enrichment' => [
        /*
         * HOW MUCH ONE TICK DOES. Both numbers are ceilings measured against this pool rather than
         * chosen: an audit costs 2.6-8.2s per lead (one page fetch each) and a search 9.2-12.7s (DNS
         * probes, an Overpass lookup per 25 leads, then a fetch of every candidate found). At the
         * WORST observed rate a full tick is 75*8.2 + 50*12.7 = about 21 minutes, which fits inside
         * both `enrichment_timeout` below and the 30-minute schedule with room to spare.
         *
         * They were 25 and 25, set before there was a backlog to drain. Against 1,600+ discovered
         * leads that rate needed roughly two days to reach the end of the queue, so "Not audited" was
         * the resting state of the board rather than a passing one. These values are still a
         * deliberate ceiling on real outbound traffic — just one calibrated to the work in front of
         * it. Raising them further means re-measuring, not guessing: the timeout is the backstop, not
         * the plan.
         */
        'audit_limit' => (int) env('DISCOVERY_ENRICHMENT_AUDIT_LIMIT', 75),
        'search_limit' => (int) env('DISCOVERY_ENRICHMENT_SEARCH_LIMIT', 50),
    ],

    /*
     * Wall clock for one enrichment sub-command. Longer than a single discovery task because a tick
     * crawls up to `audit_limit` sites in sequence; the scheduler's own overlap guard means a slow
     * tick delays the next one rather than stacking with it.
     *
     * 1500s is roughly 2.4x the slowest measured run of the largest step (50 searches at 12.7s each
     * = 635s), so an ordinary slow batch finishes and a genuinely stuck one is still cut off. It is a
     * BACKSTOP for a hung process, never the budget the limits above are planned against — if a tick
     * is routinely approaching it, the limits are wrong, not the timeout.
     */
    'enrichment_timeout' => (int) env('DISCOVERY_ENRICHMENT_TIMEOUT', 1500),

    /*
     * ── NATIONWIDE ─────────────────────────────────────────────────────────────────────────────
     *
     * A SEPARATE SET OF DIALS, deliberately. The values above belong to Advanced Lead Discovery —
     * the on-demand tool a user points at places they named — and nothing here changes them. In
     * particular `default_limit_per_task` stays 20 and remains what that button uses; the nationwide
     * numbers below are a different generation path with a different unit of success.
     */
    'nationwide' => [
        /*
         * Tile size in degrees for the first pass over the national polygon. ~0.25° is roughly 27 km
         * — coarse enough that the whole country is a few hundred cells rather than tens of
         * thousands, and the descent refines whatever turns out to be dense.
         *
         * This decides RESOLUTION, never scope: the ground covered is the polygon either way.
         */
        'cell_degrees' => (float) env('DISCOVERY_NATIONWIDE_CELL_DEGREES', 0.25),

        /*
         * THE SAFETY BUDGET: how many provider calls ONE nationwide run may make by DEFAULT.
         *
         * A CAUTIOUS STARTING POINT, NOT A STATEMENT ABOUT THE COUNTRY. Measured against the real
         * boundary, a first pass over the Philippines costs one call per cell per niche:
         *
         *     cell size   cells   calls for one niche
         *     1.00°          82                    82
         *     0.50°         244                   244
         *     0.25°         736                   736   <- the default resolution
         *     0.10°        3362                  3362
         *
         * So this default covers about half of one first pass at 0.25°, and a run that uses it will
         * finish `budget_limited` with most of the country unvisited. That is a deliberate default —
         * the first click should be cheap — and it is why the run reports its stop reason explicitly
         * rather than letting a half-covered sweep read as a finished one.
         *
         * It is a SPENDING fence, never a discovery boundary: the Philippines does not get smaller
         * when the budget does; the run stops and says so.
         */
        'max_tasks' => (int) env('DISCOVERY_NATIONWIDE_MAX_TASKS', 400),

        /*
         * The largest budget the API will accept for one run — the fence proper.
         *
         * SEPARATE FROM THE DEFAULT ON PURPOSE, because they answer different questions: the default
         * is "what should one click cost?", this is "what is the most anyone may spend at once?".
         * They used to be the same number, which meant a nationwide run covering the whole country
         * could not be REQUESTED at all: 400 was simultaneously the suggestion and the ceiling, and
         * 736 calls were needed for a single first pass. A ceiling that quietly makes the feature's
         * headline capability unreachable is not a safety fence, it is a bug wearing one's clothes.
         *
         * Set high enough that a genuine nationwide sweep — a first pass plus its subdivisions, over
         * a few niches — is expressible as a deliberate choice, and low enough that no single request
         * can spend without limit.
         */
        'max_tasks_ceiling' => (int) env('DISCOVERY_NATIONWIDE_MAX_TASKS_CEILING', 25000),

        /*
         * How many times one cell may be subdivided when it comes back FULL. 2 means a first-pass
         * cell can become at most 16 pieces. This is the depth of the dynamic expansion: raising it
         * digs further into dense cities and costs requests geometrically, which is why it is a
         * number an operator sets rather than something the descent decides for itself. A cell still
         * truncated at this limit is recorded as `capped`, never as `exhausted`.
         */
        'max_depth' => (int) env('DISCOVERY_NATIONWIDE_MAX_DEPTH', 2),

        /*
         * Per-CELL result ceiling, and the signal the whole descent runs on. 20 is one
         * structured-provider page: the cheapest useful question. A cell that returns all 20 is
         * TRUNCATED — there is more there — and gets subdivided; a cell that returns fewer is
         * exhausted. Raising this buys deeper pages per request and makes truncation a weaker signal.
         */
        'limit_per_task' => (int) env('DISCOVERY_NATIONWIDE_LIMIT_PER_TASK', 20),
    ],
];
