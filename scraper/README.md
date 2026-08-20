# Lead Enrichment Scraper

A **strict data provider** for CRM-Enterprise, with two responsibilities:

1. **Discovery** (`discover`) — find real businesses and land them as leads. **Provider-agnostic**:
   OpenStreetMap is the v1 provider; Google Places or others are a config-only change later.
2. **Website Intelligence** (`audit`) — audit the website of any lead that has a URL, recording its
   digital-presence intelligence (availability, structure, business info, socials). **Independent of
   the discovery provider** — it only needs "a lead with a website URL", regardless of where that URL
   came from.

Both are strict data providers: they never compute a Lead Score/KPI, assign a BD, or change Lead
Status / workflow fields. Business interpretation stays in the future KPI Engine.

## What it does — and never does

**Does:** discover businesses · normalize them · capture business name, address, region/province/city,
website presence, phone, coordinates, and business category · submit them to the CRM.

**Never does:** compute Lead Scores · compute KPIs · assign Business Developers · set or change
engagement fields (`priority`, `status`, `email_outreach`, `cold_calling`) · run frontend or CRM
business logic. The ingest payload physically cannot carry those fields.

## Architecture

```
                Discovery Provider (provider-agnostic)
                ├── OpenStreetMap  (v1: Overpass + Nominatim)   ✅
                ├── Google Places  (future — config only)
                └── others         (future)
                          │
                          ▼   RawBusiness (provider-neutral)
                     Normalizer
                          │
                          ▼   NormalizedBusiness (CRM fit-data)
                 CRM Ingest  →  POST /leads/discovered
                          │
                          ▼
                   Nearby Clients UI
```

- `scraper/providers/` — `DiscoveryProvider` ABC + `OpenStreetMapProvider`; `get_provider()` selects
  the provider from config. Nothing else in the codebase knows which provider is active.
- `scraper/normalizer.py` — `RawBusiness` → `NormalizedBusiness` (incl. website detection).
- `scraper/ingest.py` — the only channel into the CRM (the enrichment ingest endpoint).
- `scraper/cli.py` — `python -m scraper discover ...`.

## Setup

```bash
cd apps/scraper
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements.txt      # runtime
.venv/bin/python -m pip install -r requirements-dev.txt  # + pytest
cp .env.example .env    # then set CRM_TOKEN (a Sanctum token for an Admin/Super Admin user)
```

> On Debian/Ubuntu without `ensurepip`, bootstrap pip once:
> `curl -sS https://bootstrap.pypa.io/get-pip.py | .venv/bin/python`

## Usage

```bash
# Preview discovery without touching the CRM:
.venv/bin/python -m scraper discover --area "Makati, Philippines" --category cafe --limit 10 --print-only

# Dry run against the CRM (validates + reports, persists nothing):
.venv/bin/python -m scraper discover --area "Makati, Philippines" --category restaurant --limit 10

# Commit — persist discovered businesses as leads:
.venv/bin/python -m scraper discover --area "Cebu City, Philippines" --category pharmacy --limit 10 --commit
```

Re-running discovery over the same area is **idempotent** — the CRM dedups by `place_id` (and email),
so businesses already ingested are skipped, not duplicated.

### Automatic discovery (`discover-all`)

`discover` requires a human to pick an area and category every time. `discover-all` is the scheduled
counterpart: it reads a **version-controlled target list** and runs the next few targets in
round-robin order, so the pool fills itself without anyone choosing.

```bash
# Preview the next target — no CRM contact, and the rotation cursor does NOT advance:
.venv/bin/python -m scraper discover-all --print-only

# Dry run the next target against the CRM (validates, persists nothing):
.venv/bin/python -m scraper discover-all

# Commit — what the scheduler runs:
.venv/bin/python -m scraper discover-all --commit
```

| Flag | Default | Purpose |
|---|---|---|
| `--targets N` | file, else `3` | How many `(area, category)` pairs this run may touch (clamped to 10) |
| `--limit N` | file, else `30` | Override the per-target discovery limit (clamped to 50) |
| `--commit` | off | Persist. **Without it the run is a dry run**, exactly like `discover` |
| `--print-only` | off | Print only; skip the CRM and leave the cursor untouched |
| `--targets-file` / `--state-file` | see below | Path overrides (used by tests) |

**Targets** live in `discovery-targets.json` (version-controlled — deploy it, do not hand-edit it on
a server). Each entry expands to one pair per category:

```json
{
  "defaults": { "limit": 30, "targets_per_run": 3 },
  "targets": [
    { "area": "Makati City, Philippines", "categories": ["pharmacy", "dentist"] }
  ]
}
```

`area` is free text resolved by Nominatim; `category` must be a word the active provider knows (see
`CATEGORY_SELECTORS`) or a raw `key=value` selector. These are **provider** categories, deliberately
separate from the CRM's Business Niche registry.

**Rotation state** lives in `.discovery-state.json` beside it — runtime state, git-ignored, and
self-healing (a missing or corrupt file simply restarts the rotation). It holds the cursor plus each
pair's last run. Round-robin guarantees **no pair is queried again until every other pair has had a
turn**.

**Volume** is `targets_per_run × limit`. Shipped defaults are **3 × 30 = up to 90 businesses/run**,
and fewer in practice because the CRM dedups on `place_id`/email — the pool is meant to build
continuously, not trickle.

Both dimensions are configurable in `discovery-targets.json` (`defaults.limit`,
`defaults.targets_per_run`) and overridable per run (`--limit`, `--targets`). Precedence is
**flag → file → built-in default**.

Two ceilings are **not** product knobs and stay put: `MAX_LIMIT` (50 per target) and
`MAX_TARGETS_PER_RUN` (10 per run), in `scraper/targets.py`. Overpass and Nominatim are shared
community infrastructure and this tool is a guest on them. **Widen coverage by adding targets, not by
raising the caps** — more targets means a longer rotation, not a bigger burst.

**Failure is isolated per target** — one unresolvable area logs and the run continues. A run only
exits non-zero when *every* selected target failed, so a flaky area never alarms the scheduler.

**Scheduling** (not installed by this repo — an ops step):

```
30 2 * * *  cd /var/www/vsp-release6/scraper && flock -n .discovery.lock \
              .venv/bin/python -m scraper discover-all --commit >> logs/discovery.log 2>&1
```

`flock` is the overlap guard — cron has no equivalent of the Laravel scheduler's
`withoutOverlapping()`.

Categories accept a friendly word (`cafe`, `restaurant`, `pharmacy`, `bakery`, `salon`, `hotel`,
`dentist`, `real_estate`, …) or a raw OSM selector like `amenity=cafe`.

### Website Intelligence (audit)

```bash
# Dry run (default) — reads the queue, crawls, reports findings, writes NOTHING to the CRM:
.venv/bin/python -m scraper audit --limit 50

# Commit — submit the audit findings:
.venv/bin/python -m scraper audit --limit 50 --commit
```

Like `discover`, **every CRM write is opt-in**: without `--commit` the audit performs zero
`PUT`/`POST`/`DELETE` requests against the CRM. Reading the queue (`GET`) still happens, so a dry run
shows exactly what would be submitted.

For each queued business, a Scrapy crawl fetches the homepage and detects availability, HTTPS, page
structure (About/Contact/Services/Careers/Blog/…), business info (emails/phones), and social links.
It submits the findings to `PUT /leads/{id}/website-enrichment`; the CRM records them on
`crm_lead_website_enrichments` and derives the coarse `website_status`. Provider-independent — it
audits a URL no matter which discovery provider supplied it. The audit is homepage-based today; deeper
multi-page crawling is a future capability the architecture already supports.

The payload also reports `final_url` (where the request LANDED, after redirects) and `canonical_url`
(what the page calls itself, via `<link rel="canonical">` or `og:url`). The CRM uses those to check
that the page actually belongs to the business — matching its name against the domain and title, its
phone against the numbers on the page, its email domain, its city — before it will record a website as
that business's own. **A reachable page is not enough.** That decision is deliberately the CRM's: it
is business logic, and it needs the lead's phone/email/city, which this provider never receives.

### Website status is the CRM's to decide

`discover` reports `website-unknown` for every business, always. Observing that a directory record
carries a `website` tag is a fact about the DIRECTORY, not about the business — OpenStreetMap omits
the tag routinely, including for chains with obvious web presences — so treating its absence as "this
business has no website" was wrong, and expensive: `no-website` is the CRM's largest lead-scoring
signal. The provider's shape can no longer express the claim (`WebsiteStatus` is a one-value
`Literal`), so the guess cannot come back by accident. The URL itself is still captured when the
provider supplies one — it is the audit's input, not a verdict.

## Configuration (`.env`)

| Variable | Purpose | Default |
|---|---|---|
| `DISCOVERY_PROVIDER` | Active provider | `openstreetmap` |
| `CRM_BASE_URL` | CRM API base (unified platform: `…/api/v1/crm`) | `http://localhost:8001/api/v1/crm` |
| `CRM_TOKEN` | Sanctum token (user with `import` permission) | — |
| `OVERPASS_URL` | Overpass endpoint | `https://overpass-api.de/api/interpreter` |
| `NOMINATIM_URL` | Nominatim endpoint | `https://nominatim.openstreetmap.org` |
| `USER_AGENT` | HTTP User-Agent | `CRM-Enterprise-Scraper/1.0` |
| `REQUEST_TIMEOUT` | Seconds | `60` |

## Tests

```bash
.venv/bin/python -m pytest
```

Unit tests run against **real, recorded** OSM responses (`tests/fixtures/`) — no network, no fabricated
business data.

## Adding a provider (e.g. Google Places)

1. Add `scraper/providers/google_places.py` implementing `DiscoveryProvider` (returns `RawBusiness`).
2. Register it in `scraper/providers/__init__.py::get_provider`.
3. Set `DISCOVERY_PROVIDER=google_places` + the provider's key in `.env`.

Nothing else — the normalizer, ingest, CLI, CRM backend, and Nearby Clients UI are unchanged.

## OSM etiquette

A descriptive `User-Agent` is sent; Nominatim calls are throttled to ≤1 request/second; Overpass
rate-limit/gateway responses are retried with exponential backoff. Data © OpenStreetMap contributors
(ODbL).
