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
export type WebsiteSummary = {
  url: string | null;
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
  /** Coordinate-derived distance in km — computed by the Interactive Map milestone (absent now). */
  distanceKm?: number;
  /** Coordinate-derived map layout position (%) — computed by the Interactive Map milestone (absent now). */
  mapX?: number;
  mapY?: number;
  /** Website Intelligence summary (audit findings only). Present on every discovered business. */
  website?: WebsiteSummary;
};

export type VisitPurpose = "follow-up" | "intro" | "proposal" | "assessment";
export type ReminderOption = "none" | "30m" | "1h" | "1d";
