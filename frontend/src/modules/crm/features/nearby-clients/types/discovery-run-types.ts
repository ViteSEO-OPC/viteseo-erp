/**
 * Advanced Lead Discovery — the Stage 2 on-demand run.
 *
 * A SEPARATE FILE from `nearby-clients-types.ts` on purpose. That file describes the prospect POOL,
 * which is frozen; this one describes a run that fills it. Keeping them apart means the pool's types
 * never have to change for discovery to gain a field.
 *
 * Mirrors `DiscoveryRunResource` on the backend exactly. Nothing here is computed client-side.
 */

/** Terminal states end polling; see `isRunTerminal`. */
export type DiscoveryRunStatus =
  | "pending"
  | "running"
  | "completed"
  | "failed"
  | "cancelled";

/**
 * Which factual provider ran, versus which one was configured.
 *
 * REPORTED SEPARATELY, ALWAYS. `effective` is what actually answered; `requested` is what settings
 * asked for. When they differ the run fell back, and `reason` / `degradedReason` say why — so the
 * board can never imply Google Places produced businesses that OpenStreetMap actually produced.
 */
export type DiscoveryRunProvider = {
  requested: string | null;
  effective: string | null;
  reason: string | null;
  mode: string | null;
  isFallback: boolean;
  /** Set only when a credential that WAS configured got rejected mid-run. */
  degradedAt: string | null;
  degradedReason: string | null;
};

/**
 * Which generation path a run belongs to.
 *
 * `scoped` is Advanced Lead Discovery as it has always been: places the user typed, at its own
 * 20-per-search default. `nationwide` is a different path with a different unit of success — the
 * national polygon, tiled, at about 40 valid unique businesses per area. Neither one's numbers are
 * derived from the other's.
 */
export type DiscoveryRunScope = "scoped" | "nationwide";

export type DiscoveryRunProgress = {
  targetsTotal: number;
  targetsCompleted: number;
  targetsFailed: number;
  percent: number;

  /**
   * Nationwide only — zero on a scoped run, which tiles nothing.
   *
   * THESE ARE CELLS, NOT CITIES. Coverage is the Philippine boundary tiled into search boxes; the
   * city names attached to those boxes are labels for reading a run and appear in no number here.
   */
  cellsPlanned: number;
  cellsFirstPassDone: number;
  /** Includes cells created by subdivision, so it exceeds `cellsPlanned` where the country was dense. */
  cellsSearched: number;
  cellsSubdivided: number;
  /**
   * The two ways a cell finishes, never collapsed.
   * `cellsExhausted` — the provider returned fewer results than allowed: nothing more is there.
   * `cellsCapped` — it returned a full page and the descent limit stopped the split: there IS more.
   */
  cellsExhausted: number;
  cellsCapped: number;
  /** The safety budget in provider calls — what a run that stopped early stopped because of. */
  budgetTotal: number;
  budgetUsed: number;
};

/**
 * One label's share of a nationwide run — LABELLING, NOT ACCOUNTING.
 *
 * Grouped from the searches after the fact so a person can read "Cebu" instead of "cell 412". There
 * is no per-area target, no per-area budget and no per-area status: remove this type and not one
 * search would move. That is the test of a label.
 */
export type DiscoveryRunArea = {
  area: string;
  islandGroup: string;
  cells: number;
  discovered: number;
  inserted: number;
  duplicates: number;
  maxDepth: number;
};

/**
 * `inserted` is what became CRM prospects; `duplicates` is what the existing ingest recognised as
 * already known. Both are shown, because a run that finds 40 businesses and inserts 2 has done real
 * work and must not read as a failure.
 */
export type DiscoveryRunCounters = {
  discovered: number;
  inserted: number;
  duplicates: number;
  rejected: number;
};

/** One (location, niche) search within the run. */
export type DiscoveryRunTarget = {
  area: string;
  category: string;
  status: "pending" | "running" | "completed" | "failed" | "skipped";
  /** Which provider answered THIS search — a degraded run contains more than one kind. */
  providerUsed: string | null;
  executionStatus: string | null;
  discovered: number;
  inserted: number;
  duplicates: number;
  errorReason: string | null;
  /** The box this search actually covered. `null` on a scoped search, which named a place instead. */
  bbox: [number, number, number, number] | null;
  islandGroup: string | null;
  /** 0 is a first-pass cell; deeper means an ancestor came back truncated and was subdivided. */
  depth: number;
};

/**
 * WHY a run is where it is — the field to read before drawing any conclusion about coverage.
 *
 * `completed` alone is not an answer: a nationwide run that searched 400 of 736 map areas and ran
 * out of budget is completed, and so is one that searched every area. Identical status, opposite
 * meanings, and only one of them licenses the thought "there are no bakeries there".
 */
export type DiscoveryRunStopReason =
  | "queued"
  | "running"
  | "completed"
  | "exhausted"
  | "budget_limited"
  | "cancelled"
  | "failed";

/**
 * What AI search planning actually did for a run — recorded from what the engine reported.
 *
 * `applied` only once a search was genuinely shaped by a plan the model returned; `not_applied` when
 * AI was not requested, or was consulted and changed nothing; `insufficient_credit` when a run that
 * asked for AI was refused by the AI account and stopped rather than continuing as plain Google.
 */
export type DiscoveryRunAiOutcome = "applied" | "not_applied" | "insufficient_credit";

export type DiscoveryRun = {
  runId: string;
  status: DiscoveryRunStatus;
  stopReason: DiscoveryRunStopReason;
  phase: string | null;
  scopeClass: DiscoveryRunScope | string;
  scope: {
    /** Empty for a nationwide run: the user named no places, the country is the scope. */
    locations: string[];
    niches: string[];
    limitPerTask: number;
    cellDegrees?: number;
    /** ISO 3166-1 alpha-2. Philippines-only for now; see SUPPORTED_COUNTRIES. */
    country?: string;
  };
  provider: DiscoveryRunProvider;
  /** Optional so a response from a backend older than AI Discovery still type-checks as a run. */
  ai?: {
    requested: boolean;
    outcome: DiscoveryRunAiOutcome;
  };
  progress: DiscoveryRunProgress;
  counters: DiscoveryRunCounters;
  errorMessage: string | null;
  isTerminal: boolean;
  startedAt: string | null;
  finishedAt: string | null;
  createdAt: string | null;
  /** Present on the poll response; omitted from the list response. */
  targets?: DiscoveryRunTarget[];
  /** Present on the poll response for a nationwide run; empty for a scoped one. */
  areas?: DiscoveryRunArea[];
};

/**
 * What the user asked for.
 *
 * A DISCRIMINATED UNION, so the two modes cannot be mixed up by accident. A nationwide run has no
 * `locations` and no `limitPerTask` to send — not "an empty list of locations", but no such field:
 * its coverage comes from the national polygon and its per-cell limit from server configuration. The
 * compiler is what stops a nationwide request from quietly carrying a scoped run's 20.
 */
export type StartDiscoveryRunInput =
  | {
      scope: "scoped";
      /** Free text — no city list. Resolved only INSIDE `country`; see SUPPORTED_COUNTRIES. */
      locations: string[];
      niches: string[];
      limitPerTask: number;
      country: CountryCode;
      /** Makes a double-click, a refresh and a network retry resolve to the SAME run. */
      idempotencyKey: string;
      /** AI Discovery. Sent only when true; see `buildStartRunBody`. */
      aiStrategy?: boolean;
    }
  | {
      scope: "nationwide";
      /** Free text — no niche whitelist. The only thing a nationwide run needs to be told. */
      niches: string[];
      country: CountryCode;
      idempotencyKey: string;
      aiStrategy?: boolean;
    };

export const DISCOVERY_RUN_TERMINAL: readonly DiscoveryRunStatus[] = [
  "completed",
  "failed",
  "cancelled",
];

export function isRunTerminal(status: DiscoveryRunStatus | undefined): boolean {
  return status !== undefined && DISCOVERY_RUN_TERMINAL.includes(status);
}

/**
 * The structured provider's own per-search ceiling (page size 20 × 3 pages).
 * Asking for more spends requests that cannot return anything new, and the backend rejects it.
 */
export const MAX_LIMIT_PER_TASK = 60;

/** Matches the backend's `max_tasks_per_run` fence — a billing guard, never a discovery boundary. */
export const MAX_TASKS_PER_RUN = 100;

/**
 * The country's own name, in the spellings people type.
 *
 * NOT A LOCATION BLOCKLIST. The whole country is its own mode with its own coverage model, and
 * typing the name into the locations box asks a provider for one box around the entire archipelago —
 * a few dozen results presented as national coverage, which is the exact failure the nationwide mode
 * exists to replace. So the name routes to the mode. Every other place name on earth stays free
 * text. The backend enforces the same rule; this is the copy that can say so before anything is
 * spent. Kept in sync with `StoreDiscoveryRunRequest::COUNTRY_NAMES`.
 */
const COUNTRY_NAMES = [
  "philippines",
  "the philippines",
  "republic of the philippines",
  "ph",
  "phl",
  "pilipinas",
];

export function isCountryName(value: string): boolean {
  return COUNTRY_NAMES.includes(value.trim().toLowerCase());
}

/** Human labels for the island groups the API reports. Derived server-side from the national polygon. */
const ISLAND_GROUP_LABELS: Record<string, string> = {
  luzon: "Luzon",
  visayas: "Visayas",
  mindanao: "Mindanao",
};

export function islandGroupLabel(id: string | null): string {
  if (id === null || id === "") return "—";
  return ISLAND_GROUP_LABELS[id] ?? id;
}

/**
 * The countries discovery can generate leads in.
 *
 * ONE ENTRY, AND THAT IS A PRODUCT DECISION RATHER THAN AN OVERSIGHT. Coverage is enforced by a
 * vendored, checksummed national boundary and by a geocoder confined to that country; a second
 * country would need its own boundary dataset before the restriction could mean anything, so it is
 * added deliberately, not by typing a code into a list. Mirrors `config('discovery.countries')`.
 */
export const SUPPORTED_COUNTRIES = [{ code: "PH", label: "Philippines" }] as const;

export type CountryCode = (typeof SUPPORTED_COUNTRIES)[number]["code"];

/** What a fresh, unconfigured discovery form looks like. Used on open and by Reset. */
export const DEFAULT_COUNTRY: CountryCode = "PH";

export function countryLabel(code: string): string {
  return SUPPORTED_COUNTRIES.find((c) => c.code === code)?.label ?? code;
}

/** Human labels for the provider ids the API returns. Unknown ids render as themselves, never guessed. */
// ONE VOCABULARY FOR THE LANES, matching the integrations card. `openai` is the AI-assisted
// OpenStreetMap lane — its businesses are OSM nodes reached through AI-proposed searches — so the
// bare word "AI-assisted" read as though AI were itself the factual source. Spelling out the source
// keeps this row answering "where did the businesses come from"; which AI planned the search is the
// AI engine row's question, and {@see AI_ENGINE_NAME} answers it.
const PROVIDER_LABELS: Record<string, string> = {
  google_places: "Google Places",
  openstreetmap: "OpenStreetMap",
  openai: "AI-assisted OpenStreetMap",
};

export function providerLabel(id: string | null): string {
  if (id === null || id === "") return "—";
  return PROVIDER_LABELS[id] ?? id;
}

/**
 * Split a free-text field into values.
 *
 * Commas and newlines separate; nothing else is interpreted. Deliberately NO canonicalization,
 * NO lookup against a known vocabulary, NO spelling correction — "vape shop" and "Roxas City" must
 * survive exactly as typed, because the factual provider owns that vocabulary, not this form.
 */
export function splitFreeText(value: string): string[] {
  const seen = new Map<string, string>();
  for (const raw of value.split(/[,\n]/)) {
    const trimmed = raw.trim();
    if (trimmed === "") continue;
    const key = trimmed.toLowerCase();
    if (!seen.has(key)) seen.set(key, trimmed);
  }
  return [...seen.values()];
}
