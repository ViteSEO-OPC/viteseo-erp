// Nearby Clients types.
//
// ── Service-Area Registry (LIVE backend) ──────────────────────────────────────────────────────
// An administrative area (name at a region/province/city level) that counts as a "target service
// location" for the frozen Target Location (+10) signal. Business configuration only — no
// coordinates, no score computation here.

export type ServiceAreaLevel = "region" | "province" | "city";

export type ServiceArea = {
  id: string;
  name: string;
  level: ServiceAreaLevel;
  active: boolean;
};

// Server-side, data-only summary: how many leads currently fall in a target service area.
export type ServiceAreaSummary = {
  leadsInTargetLocation: number;
  totalLeads: number;
};

// ── Nearby clients (LIVE — discovered businesses from the enrichment scraper) ───────────────────
// This is the shape the Nearby Clients UI consumes, served by `GET /nearby-clients` — real businesses
// discovered by the provider-agnostic scraper (OpenStreetMap in v1). `latitude`/`longitude` are the
// real captured coordinates; they are STORED this milestone and rendered by the **Interactive Map**
// (next milestone).
//
// `distanceKm`, `mapX`, `mapY` are the coordinate-derived DISPLAY values the Interactive Map milestone
// computes from `latitude`/`longitude`. They are absent this milestone — the list is live now, the map
// visualization is next — so they are optional and every consumer degrades gracefully when unset.

export type NearbyPriority = "high" | "medium" | "low";

export type WebsiteStatus =
  | "has-website"
  | "needs-redesign"
  | "no-website"
  | "website-unknown"
  | "website-unreachable";

/**
 * Website Intelligence audit signals — real detections only (never fabricated). Every field is a
 * signal we actually audit today, so each may be shown as a Detected (true) or Missing (false) chip.
 * Social channels expose PRESENCE only (never the URL) to preserve provider independence.
 */
export type WebsiteSignals = {
  reachable: boolean;
  https: boolean;
  about: boolean;
  contact: boolean;
  services: boolean;
  careers: boolean;
  blog: boolean;
  emailDetected: boolean;
  phoneDetected: boolean;
  facebook: boolean;
  instagram: boolean;
  linkedin: boolean;
  youtube: boolean;
};

/** Compact, BD-facing Website Intelligence summary attached to a discovered business. */
/**
 * What kind of link the record actually holds.
 *
 * `own` is the only kind that may be presented as WEBSITE. The rest are real, useful links about the
 * business that are hosted on somebody else's platform — and a business whose only web presence is a
 * Facebook page is the prospect this CRM exists to find, so showing that page as its website inverts
 * the signal it was discovered for.
 */
export type WebsiteUrlKind = "own" | "social" | "directory" | "link_in_bio" | "invalid";

export type WebsiteSummary = {
  /** Null unless the stored URL could actually be the business's own site. */
  url: string | null;
  urlKind: WebsiteUrlKind | null;
  /** Social profiles, kept and labelled as such — never shown under WEBSITE. */
  social: { facebook?: string; instagram?: string };
  status: WebsiteStatus | null;
  audited: boolean;
  auditedAt: string | null;
  signals: WebsiteSignals | null;
};

export type NearbyClient = {
  id: string;
  business: string;
  initials: string;
  /** Business category (industry), e.g. "Cafe". */
  niche: string | null;
  /** Human area label — most specific admin unit available, e.g. "Makati". */
  area: string | null;
  contact: string;
  /** Contact role (optional; discovery rarely exposes a contact person). */
  role?: string;
  /** Real captured coordinates (WGS84). Rendered by the Interactive Map milestone. */
  latitude: number | null;
  longitude: number | null;
  /**
   * DERIVED prospecting priority (no-website → high, needs-redesign → medium, has-website → low,
   * website-unknown / website-unreachable → medium),
   * computed by the Nearby API from Website Intelligence. NOT the CRM `leads.priority` field. Drives
   * the list chip, map-pin colour, and the prospecting-priority filter — one shared value everywhere.
   */
  priority: NearbyPriority;
  /** Whether the prospect is active (not declined). */
  active: boolean;
  /** Lead status enum, e.g. "new". */
  status: string;
  /**
   * Which discovery LANE found this business — the deterministic OpenStreetMap sweep, the
   * AI-assisted expansion beside it, or the Google Places search. A closed set decided server-side
   * from per-record provenance; nothing in the UI branches on it beyond the filter.
   *
   * `unknown` is a provenance value the server could not place in a lane. It is surfaced rather than
   * folded into `openstreetmap`, so a lane is never credited with a lead it did not find.
   */
  foundBy: "openstreetmap" | "ai-assisted" | "google-places" | "unknown";
  /**
   * EVERY lane that found this business: `foundBy` first, then any further lane the server recorded
   * (today only "ai-assisted", when the AI lane also found an OpenStreetMap lead). One lead, one row —
   * this only lets the Found By filter list it under each lane that genuinely found it. Optional so a
   * response from a backend without it behaves exactly as before (treated as `[foundBy]`).
   */
  foundByAll?: string[];
  /**
   * Is this business inside the Philippines, per the same national boundary discovery enforces?
   *
   * `null` means it has no coordinates, which is NOT the same as "foreign" — a third of the pool has
   * no position, and absence of one is not evidence of a foreign one. A Philippines-only view keeps
   * `true` and `null` and hides only `false`.
   */
  inPhilippines: boolean | null;
  /**
   * CLAIM STATE — read-only, derived server-side from the qualification columns.
   *
   * The Nearby board is a SHARED pool: a prospect stays listed after a BD accepts it into the CRM,
   * so every BD can see the pool moved rather than watching rows silently disappear. These three
   * fields are what let the UI say so — mark the row, name the claimant, and stop offering an
   * Accept the API would refuse with a 409.
   */
  qualified: boolean;
  qualifiedAt: string | null;
  /**
   * Who claimed it. Optional because the API sends it via `whenLoaded`, and absent for an
   * unclaimed prospect — so read it only alongside `qualified`, never as the claim test itself.
   */
  qualifiedBy?: { id: string; name: string } | null;
  /** Coordinate-derived distance in km — computed by the Interactive Map milestone (absent now). */
  distanceKm?: number;
  /** Coordinate-derived map layout position (%) — computed by the Interactive Map milestone (absent now). */
  mapX?: number;
  mapY?: number;
  /** Website Intelligence summary (audit findings only). Present on every discovered business. */
  website?: WebsiteSummary;
};

// ── Website evidence chain (LIVE — GET /leads/{id}/website-evidence) ────────────────────────────
// The trail behind a website conclusion: which candidate URLs a search proposed, which tier proposed
// each, and why each was refused. Read by a BD before confirming that a business genuinely has no
// website — the ONE conclusion the system will not draw on its own.

/** Which tier proposed a candidate. `manual` is a BD typing one in; the rest are automated. */
export type WebsiteCandidateSource = "osm_tag" | "wikidata" | "search" | "ai" | "manual";

/** Why a checked candidate was refused. Absent while a candidate is still verified or unchecked. */
export type WebsiteCandidateRejection =
  | "unreachable"
  | "identity_mismatch"
  | "insufficient_margin"
  | "invalid_url";

export type WebsiteCandidate = {
  id: string;
  url: string;
  normalizedUrl: string | null;
  source: WebsiteCandidateSource;
  sourceDetail: string | null;
  /** `null` = proposed but not yet checked; `false` = checked and refused; `true` = this lead's site. */
  verified: boolean | null;
  confidence: number | null;
  rejectionReason: WebsiteCandidateRejection | null;
  checkedAt: string | null;
};

export type WebsiteSearch = {
  /** False when no search has ever run — different from a search that ran and found nothing. */
  searched: boolean;
  tiersRun: string[];
  candidatesFound: number;
  candidatesRejected: number;
  lastSearchedAt: string | null;
  /** Every available tier ran and nothing survived. EVIDENCE, not a verdict on its own. */
  exhausted: boolean;
  exhaustedAt: string | null;
  confirmedAbsent: boolean;
  confirmedAt: string | null;
  confirmedBy: string | null;
  note: string | null;
  /** A confirmation that a later deterministically-verified website contradicted. */
  confirmationWithdrawn: boolean;
  withdrawnAt: string | null;
  withdrawnReason: string | null;
  /** The server's own answer to "may this be confirmed now?" — never re-derived in the UI. */
  canConfirmAbsence: boolean;
};

export type WebsiteEvidence = {
  leadId: string;
  websiteStatus: WebsiteStatus | null;
  search: WebsiteSearch;
  candidates: WebsiteCandidate[];
};
