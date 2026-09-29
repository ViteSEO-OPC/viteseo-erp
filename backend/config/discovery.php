<?php

use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;

/**
 * Stage 2 on-demand discovery — EXECUTION settings for invoking the canonical Python engine.
 *
 * This file describes HOW to run the discovery engine (where the interpreter is, where the engine
 * lives, how long a task may take). It deliberately contains:
 *
 *   - NO API credential of any kind. The Google Places key lives in `crm_nerby_provider_settings`
 *     and is read through {@see NerbyProviderSettingService}.
 *     There is exactly one Google credential store in this system and this is not it.
 *   - NO area list, NO city list, NO niche list. Market settings live in `config/nerby.php`, which
 *     already explains why pinning geography or categories in config recreates the hardcoding the
 *     product exists to avoid. Scope arrives per request.
 *   - NO STANDING CREDENTIAL for calling back into this CRM. This file once named a `crm_token`,
 *     the long-lived Sanctum bearer the engine used for `POST /leads/discovered`. That key is gone:
 *     the engine's identity is now a short-lived, org-bound token minted per run and revoked when
 *     the work ends. Only its settings live here, under `service_account` below — never a token.
 *
 * The minted token is still handed to the subprocess as an environment variable, read at execution
 * time inside the worker: never placed in a queue payload, never logged, never returned to the
 * browser.
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

    /*
     * The engine's identity when it calls back into the CRM — a dedicated, least-privilege service
     * account (NerbyServiceIdentity), NOT a standing token.
     *
     * There is deliberately no `crm_token` any more. The engine used to run with one long-lived
     * wildcard token owned by an orgless super_admin, which made every callback land in the first
     * org and put full platform administrator in the child's environment. Now each discovery run and
     * each enrichment pass mints its own token — bound to ONE org, confined to the scraper callback
     * routes, revoked when the work ends — and `run_token_ttl` is only the backstop expiry for a run
     * that dies without cleaning up. Operators who run the scraper CLI by hand issue a token with
     * `php artisan crm:nerby-service-token <org>`.
     */
    'service_account' => [
        'email' => env('NERBY_SERVICE_EMAIL', 'nerby-service@crm.local'),
        'name' => env('NERBY_SERVICE_NAME', 'NERBY Discovery Service'),
        'run_token_ttl' => (int) env('NERBY_SERVICE_RUN_TOKEN_TTL', 7200),
    ],

    /*
     * RATE REFUSALS (H-4). A search refused for rate — Google's quota, or the CRM's own callback
     * throttle — is retried at most `max_retries` times, waiting `base_seconds` × 2^n (at least the
     * server's Retry-After), never more than `max_seconds` per wait. Never a provider degradation, and
     * never counted as an empty or exhausted area. Worst added wait per search with the defaults:
     * 10 + 20 + 40 = 70s.
     */
    'rate_limit' => [
        'max_retries' => (int) env('DISCOVERY_RATE_LIMIT_RETRIES', 3),
        'base_seconds' => (int) env('DISCOVERY_RATE_LIMIT_BASE_SECONDS', 10),
        'max_seconds' => (int) env('DISCOVERY_RATE_LIMIT_MAX_SECONDS', 60),
    ],

    /*
     * How long the CRM remembers which Google places it returned to a service token, so the ingest
     * can refuse a `places/...` identity the CRM never handed out (GooglePlacesAttestation).
     */
    'attestation_ttl' => (int) env('NERBY_ATTESTATION_TTL', 21600),

    'allow_remote_commit' => (bool) env('DISCOVERY_ALLOW_REMOTE_COMMIT', false),

    /*
     * ── AI SEARCH STRATEGY ─────────────────────────────────────────────────────────────────────
     *
     * Whether a run started by this CRM may consult the optional AI strategist before searching.
     *
     * WHAT IT DOES NOT DO. It does not make AI a source of businesses, and it cannot: the strategist
     * answers only with SEARCH INSTRUCTIONS — tag selectors, query phrases, place types and
     * candidate sub-area names — and the factual provider (Google Places, or the OSM baseline) still
     * supplies every business, identifier, coordinate, phone and website that reaches a lead. With
     * this flag on, a run searches differently; it does not learn anything new about the world from
     * the model. See Services/Discovery/Contracts/DiscoveryStrategist.
     *
     * FALSE BY DEFAULT, AND FAIL-CLOSED BY CONSTRUCTION. The engine's own `use_ai_strategy` is also
     * false unless its environment says otherwise, so BOTH sides must agree before a single model
     * call is made. An environment that never sets DISCOVERY_AI_STRATEGY gets today's behaviour
     * byte for byte: no strategy request, no token spend, no change to which businesses are found.
     * Turning it on is a deliberate operational act, per environment, and it stays off in staging
     * and production until someone decides otherwise.
     *
     * IT BUYS CAPABILITY, NEVER A DEPENDENCY. A strategy call that fails — no credential, no credit,
     * a timeout — leaves the run executing exactly the search it would have executed anyway.
     */
    'ai_strategy' => (bool) env('DISCOVERY_AI_STRATEGY', false),

    /*
     * ── QUERY YIELD PROBE ──────────────────────────────────────────────────────────────────────
     *
     * Measurement, not discovery. A probe asks Google what ONE search phrase reaches, using the
     * cheapest field mask the API offers (`places.id`): no name, no address, no coordinate, no
     * phone, no website. A probe result therefore cannot become a CRM record — there is no business
     * data in it to ingest.
     *
     * WHY IT IS A SEPARATE SPEND, STATED OUT LOUD. Each probe is one extra Text Search request. The
     * production search is untouched and costs exactly what it always did; this buys per-phrase
     * measurement that the production path structurally cannot provide, because that path merges
     * every phrase's results into one de-duplicated set.
     *
     * OFF BY DEFAULT, like every other optional capability here: it spends money, so switching it on
     * is a deliberate operational act. An environment that never sets these gets today's behaviour
     * and today's bill.
     */
    'probe' => [
        'enabled' => (bool) env('DISCOVERY_QUERY_PROBE', false),

        /*
         * THE SAMPLING POLICY, EXPLICIT RATHER THAN IMPLIED. At most this many phrases are measured
         * per strategy cycle, taken from the front of the AI's ranking — the phrases that would
         * actually be executed anyway. Defaulted to the provider's own query ceiling so a cycle can
         * never probe more phrases than a run could search.
         *
         * A cycle ends when the plan changes, which is what makes this "one probe per phrase per
         * cycle": a phrase already measured in the current cycle is not measured again.
         */
        'max_phrases_per_cycle' => (int) env('DISCOVERY_QUERY_PROBE_MAX_PHRASES', 3),

        /*
         * Results per probe. One page, never paged: a measurement that paged would multiply its own
         * cost. A full page means the count is a FLOOR, and the record says so.
         */
        'page_size' => (int) env('DISCOVERY_QUERY_PROBE_PAGE_SIZE', 20),
    ],

    /*
     * ── ADAPTIVE FEEDBACK ──────────────────────────────────────────────────────────────────────
     *
     * Whether measurements are allowed to INFLUENCE the next strategy, as opposed to merely being
     * collected. Independent of `probe` on purpose: an operator can measure for a while and look at
     * what accumulates before letting any of it change what the model is told.
     *
     * OFF BY DEFAULT. With it off the strategy payload is byte-identical to its pre-feedback shape,
     * so the model is asked exactly the question it was asked before this existed.
     *
     * WHAT IT COSTS WHEN ON, stated plainly: feedback participates in the plan cache key, because a
     * question asked with new measurements is a different question. So a plan is re-asked whenever
     * measurements change — roughly one extra model call per target per measurement cycle. That is
     * the price of adaptivity, and it is the reason this is a switch rather than a default.
     */
    'feedback' => [
        'enabled' => (bool) env('DISCOVERY_FEEDBACK', false),
    ],

    /*
     * Per-TASK wall clock. The queue worker's own ceiling is 900s (see docker-compose.yml), so a
     * single hung area must not be allowed to consume the whole run's budget: one task that stalls
     * is one task that fails, and the run continues.
     */
    'task_timeout' => (int) env('DISCOVERY_TASK_TIMEOUT', 180),

    /*
     * Per-task wall clock for the providers that are not Google, BY PROVIDER — because the cost of a
     * task is a fact about the provider, not about the run that asked for it.
     *
     * WHY THIS IS NOT ONE GLOBAL NUMBER. Measured over every task this database has recorded:
     * Google Places averages 3.2s and has never exceeded 47s, while the AI-assisted lane averages
     * ~42s and has been seen at 118s. They differ by more than an order of magnitude because the
     * AI lane does three sweeps where Google does one, and then reverse-geocodes each business at
     * Nominatim's required one-request-per-second. Raising the GLOBAL timeout to fit the slow lane
     * would hand a hung Google task 300s of worker time to fit a lane whose worst case is 47s — it
     * would buy nothing and cost exactly the protection this ceiling exists to provide.
     *
     * WHY 300 AND NOT MORE. The slice deadline is checked BETWEEN searches, never inside one (see
     * DiscoveryRunOrchestrator::workQueue), so a task starting one second before the deadline still
     * runs to completion. The worst-case job therefore costs `nationwide.slice_seconds` plus one
     * whole task timeout, and that must stay inside RunDiscoveryJob's 900s:
     *
     *     540 + 180 = 720   the old global value, 180s of headroom
     *     540 + 300 = 840   this value, 60s of headroom
     *     540 + 360 = 900   no headroom at all — the job dies exactly as the task ends
     *     540 + 420 = 960   the job is killed mid-task, losing the slice
     *
     * 360 and 420 are therefore not "more generous", they are unsafe. {@see DiscoveryTaskTimeoutTest}
     * enforces this arithmetic so a later env change cannot quietly cross the line.
     *
     * A timeout is a CEILING, not a duration: giving the AI lane 300s does not make any task slower,
     * it only stops killing the ones that were going to finish.
     */
    'task_timeout_by_provider' => [
        'openai' => (int) env('DISCOVERY_TASK_TIMEOUT_OPENAI', 300),
    ],

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
     * ── AI WEBSITE AUDIT ───────────────────────────────────────────────────────────────────────
     *
     * Whether the optional AI layer may reason about an AMBIGUOUS website-identity verdict — one
     * strong signal that nothing corroborated, which the lexical verifier must leave unconfirmed but
     * a reader often would not. Measured on this pool: 205 leads in exactly that band.
     *
     * ITS OWN SWITCH, SEPARATE FROM `ai_strategy`, and that separation is the point rather than
     * tidiness. The strategist is asked once per discovery TASK; this is asked once per LEAD, so it
     * is by far the higher-volume caller and has to be affordable to turn off on its own. Both draw
     * on the one credential in `crm_nerby_provider_settings`, so without a second switch enabling
     * website auditing would quietly spend the discovery budget.
     *
     * ADVISORY, AND THAT IS ENFORCED ELSEWHERE. Turning this on cannot change a `website_status`, a
     * score or a priority: the adjudicator returns evidence, never a verdict, and nothing consumes
     * it. See Services/Website/LlmIdentityAdjudicator.
     *
     * OFF BY DEFAULT, like every other optional capability here — it spends money, so switching it
     * on is a deliberate operational act.
     */
    'website_audit' => [
        /*
         * BEFORE THIS IS TURNED ON IN PRODUCTION: verify the CURRENT OpenRouter privacy, data
         * retention and training / data-use terms that apply to API traffic.
         *
         * Real business names, cities and page text leave our infrastructure when this is true. No
         * assumption is made here about what the provider keeps or trains on, and none should be
         * inherited from a previous reading of their terms — it is an external dependency whose
         * policy can change without us. The default is false so the check cannot be skipped by
         * accident, and flipping it is the moment to make it.
         */
        'enabled' => (bool) env('DISCOVERY_WEBSITE_AI_AUDIT', false),

        /*
         * NOT the strategist's 8s. That timeout is tight because the strategist sits in front of a
         * search the user is waiting on; this runs inside a background enrichment tick where a slow
         * answer costs nothing but the tick's own budget, and an 8s ceiling on a reasoned answer
         * would turn ordinary latency into a stream of `unreachable`.
         */
        'timeout' => (float) env('DISCOVERY_WEBSITE_AI_TIMEOUT', 25),

        /* One classification, one short sentence. Anything longer is not the contract. */
        'max_tokens' => (int) env('DISCOVERY_WEBSITE_AI_MAX_TOKENS', 200),

        /*
         * How long one answer is reused for identical evidence. Keyed on the evidence itself, so a
         * page that changed is a different question and is asked again regardless of this.
         */
        'cache_ttl' => (int) env('DISCOVERY_WEBSITE_AI_CACHE_TTL', 604800),
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
         * The largest budget a nationwide run may use WITHOUT Google Places — the Overpass fence.
         *
         * Nationwide used to demand Google Places outright, for a good reason: the OpenStreetMap
         * baseline answers from Overpass, a free endpoint funded and run by volunteers, and a full
         * sweep aimed at it is abuse of someone else's infrastructure rather than a fallback. But
         * "how many queries" is a question about SIZE, not about which provider is configured, and
         * refusing every size denied the AI-assisted path the only planner that covers the country.
         *
         * A task costs about three Overpass queries — the baseline selector sweep, the expanded-tag
         * sweep, and the resolver's batched name lookup — because `_overpass` unions selectors into
         * one request instead of issuing one per tag. So this default is roughly 180 queries for a
         * whole run, spread across a run that is already rate-limit-aware (exponential backoff) and
         * already holds itself to Nominatim's one-request-per-second policy.
         *
         * Google Places is not subject to this: it is a paid endpoint the spend gate meters in
         * dollars, so its fence is `GooglePlacesSpendGate`, not this one.
         */
        'max_tasks_without_google' => (int) env('DISCOVERY_NATIONWIDE_MAX_TASKS_WITHOUT_GOOGLE', 60),

        /*
         * SLICED EXECUTION (H-5). A nationwide sweep outlives any single queued job, so each job works
         * the queue for `slice_seconds`, checkpoints what is left on the run and queues its successor.
         * Must stay well under RunDiscoveryJob::$timeout (900s) and the worker's --timeout: the margin
         * is for the one search already in flight at the deadline — `task_timeout` plus the worst
         * rate-limit waits (`rate_limit.*`). `lock_seconds` guards one-slice-at-a-time per run and must
         * outlive a job, so a crashed slice cannot leave the run locked forever.
         */
        'slice_seconds' => (int) env('DISCOVERY_NATIONWIDE_SLICE_SECONDS', 540),
        'lock_seconds' => (int) env('DISCOVERY_NATIONWIDE_LOCK_SECONDS', 1200),

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

        /*
         * AI-ASSISTED CAPACITY — the combined `openai` provider only, and OFF unless set.
         *
         * How many businesses one nationwide search may return once the AI lanes have added to the
         * OpenStreetMap sweep. The sweep itself stays capped at `limit_per_task`, and a cell is
         * still subdivided only when the SWEEP fills it, so OpenStreetMap coverage is exactly what it
         * is without this. With it set, the AI lanes also run once per (area, niche) per run instead
         * of in every cell that shares the area's name, and their candidates are geocoded only when
         * returned. Unset (null) — the default — leaves every run exactly as it was. OpenStreetMap
         * and Google Places runs never read it.
         */
        'ai_total_limit' => env('DISCOVERY_NATIONWIDE_AI_TOTAL_LIMIT') !== null && env('DISCOVERY_NATIONWIDE_AI_TOTAL_LIMIT') !== ''
            ? (int) env('DISCOVERY_NATIONWIDE_AI_TOTAL_LIMIT')
            : null,

        /*
         * VOLUME MODE — the combined `openai` provider only, nationwide only, and OFF unless enabled.
         *
         * The default sweep reads the first 20 raw OpenStreetMap elements of a cell, in id order, so
         * every run of a cell sees the same 20, and a cell whose first 20 include a few unnamed
         * elements is recorded as EXHAUSTED while it holds hundreds (measured: a 0.25° Quezon City
         * cell, 174 named dentists, 17 in the CRM, recorded exhausted after 17). Volume mode instead:
         *
         *   - reads up to `raw_cap` raw elements per cell in the same single Overpass query;
         *   - asks the CRM which of them it already holds (the ingest still deduplicates);
         *   - returns up to `task_cap` NEW businesses per search, likely-no-website first, and
         *     reverse-geocodes only those (R2) through the shared 1/second Nominatim gate;
         *   - subdivides on the RAW read (`raw_cap` reached) or on more new businesses than the cap —
         *     never on the named count — so unnamed elements can no longer end a dense cell;
         *   - orders cells by how many businesses the CRM does NOT hold yet (density probe ids minus
         *     the org's place_ids), so the budget goes where new leads are;
         *   - does not run the AI name lane (the tag-expansion lane still feeds the sweep).
         *
         * `new_target` is what counts a task as PRODUCTIVE in reporting; it is not a stop rule. Google
         * Places, Google + AI, OpenStreetMap and the default AI-assisted path never read this block.
         *
         * TIME BUDGET: task_cap × 1.1s of Nominatim + the sweep's 75s client timeout must stay inside
         * `task_timeout_by_provider.openai` (300s): 100 × 1.1 + 75 = 185. {@see NationwideVolumeModeTest}
         * enforces it.
         */
        'volume' => [
            'enabled' => (bool) env('DISCOVERY_NATIONWIDE_VOLUME', false),
            'raw_cap' => (int) env('DISCOVERY_NATIONWIDE_VOLUME_RAW_CAP', 400),
            'task_cap' => (int) env('DISCOVERY_NATIONWIDE_VOLUME_TASK_CAP', 100),
            'new_target' => (int) env('DISCOVERY_NATIONWIDE_VOLUME_NEW_TARGET', 50),

            /*
             * WEBSITE INTELLIGENCE FOR THE RUN'S NEW LEADS, as one queued job after the run finishes —
             * never the scheduler (it would also fire the stale scheduled-email sender). Scoped to the
             * leads this run created, capped at `enrich_limit`, each step bounded by
             * `enrich_step_timeout` so the job fits inside the worker's 900s.
             *
             * 500 covers a run's own new leads up to 500 (a 10-search benchmark created 464). The work is
             * still done 50 leads per job (EnrichRunLeadsJob::CHUNK), each chunk its own bounded job, so
             * a larger limit means more chunks queued behind the run — measured ~3.5 min per chunk —
             * never a longer single job.
             */
            'enrich_after_run' => (bool) env('DISCOVERY_NATIONWIDE_VOLUME_ENRICH', true),
            'enrich_limit' => (int) env('DISCOVERY_NATIONWIDE_VOLUME_ENRICH_LIMIT', 500),
            'enrich_step_timeout' => (int) env('DISCOVERY_NATIONWIDE_VOLUME_ENRICH_STEP_TIMEOUT', 400),
        ],

        /*
         * ORDER THE TILING BY WHAT HAS ALREADY BEEN SEARCHED, instead of by latitude.
         *
         * The cells are emitted from the southern edge of the national extent upward, and the island
         * groups are interleaved so a truncated plan stays national in character. That balances the
         * three groups against each other; it does not balance latitude inside any of them. A budget
         * that cannot afford all 736 cells therefore buys the southern part of the country and calls
         * it nationwide — measured on run 89, 600 tasks reached 91 cells and never crossed 13.16°N,
         * while the same southern squares were swept again and Northern Luzon was never searched.
         *
         * Turning this on puts the cells nobody has searched for the requested niche first. It
         * changes ORDER ONLY: the same cells, the same count, the same groups, the same geometry.
         *
         * DEFAULT OFF, because it is being validated against run 89 as its control and a reordering
         * of where a paid sweep goes should be switched on deliberately rather than inherited.
         */
        'coverage_ordering' => (bool) env('DISCOVERY_COVERAGE_ORDERING', false),

        /*
         * SEARCH WHERE THE BUSINESSES ARE FIRST. Before planning, ask OpenStreetMap — once, for the
         * whole country — where the requested niche's named businesses sit, and order each island
         * group's cells densest-first. Measured: dentists occupy 145 of the 736 cells and restaurants
         * 428, so a raster order spends most of a 60-task budget on empty ground (run 106: 60 tasks,
         * 3 leads, first after 9m36s). The probe's per-cell counts matched the engine's own per-cell
         * searches exactly (657/657, 25/25, 3/3).
         *
         * ORDER ONLY, EXACTLY LIKE coverage_ordering. Same cells, same count, same groups, same
         * geometry, same Luzon/Visayas/Mindanao round-robin; a cell with no known business is moved
         * to the end of its group, never dropped. The probe is positions only — it finds no lead and
         * ingests nothing. Any failure (Overpass busy after its retries, a niche with no OSM tag
         * mapping) leaves today's order untouched, and so does a Google Places run: the probe describes
         * OpenStreetMap, which is not what Google searches.
         *
         * DEFAULT OFF: a reordering of where a sweep goes is switched on deliberately, not inherited.
         */
        'density_ordering' => (bool) env('DISCOVERY_DENSITY_ORDERING', false),

        /*
         * THE FIRST PASS: how many searches go to the densest cells of Luzon, Visayas and Mindanao,
         * round-robin, before any of them is subdivided. Without it the densest cell (Metro Manila:
         * 471 dentists, 4,680 restaurants) is split to the depth limit first — ~21 searches, ~14
         * minutes — before the other island groups are touched. Children of those cells are held and
         * then queued at the FRONT exactly as today, so the descent is delayed, never skipped. Counted
         * in searches: a multi-niche run gets fewer cells, but never fewer than one per island group.
         * Only used when density_ordering is on.
         */
        'quick_pass_tasks' => (int) env('DISCOVERY_QUICK_PASS_TASKS', 6),

        /*
         * How long a niche's density map is reused. OpenStreetMap does not move meaningfully in a day,
         * and the probe is the slowest step of a run's start (restaurants: 49–68s), so only the first
         * run of a niche each day pays it. Failures are never cached.
         */
        'density_cache_ttl' => (int) env('DISCOVERY_DENSITY_CACHE_TTL', 86400),

        /*
         * Ceiling on the probe process: the engine's own request timeout (200s) plus its retries' waits
         * would outlast this only on a server refusing every attempt, which should fail over to
         * today's order promptly rather than hold the run's start.
         */
        'density_timeout' => (int) env('DISCOVERY_DENSITY_TIMEOUT', 240),
    ],
];
