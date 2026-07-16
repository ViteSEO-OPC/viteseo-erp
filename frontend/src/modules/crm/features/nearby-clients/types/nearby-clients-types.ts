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
};

export type VisitPurpose = "follow-up" | "intro" | "proposal" | "assessment";
export type ReminderOption = "none" | "30m" | "1h" | "1d";
