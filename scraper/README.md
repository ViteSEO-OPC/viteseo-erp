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
`lead_website_enrichments` and derives the coarse `website_status`. Provider-independent — it audits a
URL no matter which discovery provider supplied it. The audit is homepage-based today; deeper
multi-page crawling is a future capability the architecture already supports.

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
