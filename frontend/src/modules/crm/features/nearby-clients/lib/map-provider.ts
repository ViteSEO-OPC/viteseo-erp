/**
 * Which MAP the Nearby board renders, decided from which DISCOVERY provider is actually running.
 *
 * WHY THESE ARE TWO DIFFERENT THINGS. Discovery decides where businesses come FROM; the map decides
 * how they are DRAWN. They are related but not the same, and collapsing them would be wrong in a way
 * that matters: the AI-assisted lane resolves every candidate against an OpenStreetMap element, so
 * its records carry OSM identities and belong on the OSM map. Only the structured places provider
 * supplies records whose identity is Google's, and only those get the Google map.
 *
 * THE DEFAULT IS OPENSTREETMAP, ALWAYS. Anything unrecognised — a provider added by a newer build, a
 * response that never arrived, a caller who passes nothing — lands on the map that needs no
 * credential and always works. A map is how a BD reads their pool; it must never be the thing that
 * breaks because a configuration lookup failed.
 *
 * Kept as a pure function, separate from the hook that fetches the state, so the decision can be
 * driven directly by the gates harness rather than only through React.
 */

/** The map implementations this board can render. Deliberately a closed set. */
export type MapProvider = "openstreetmap" | "google";

/** The discovery provider whose records are drawn with Google's own map. */
export const GOOGLE_DISCOVERY_PROVIDER = "google_places";

/**
 * The map for an effective discovery provider.
 *
 * Takes the EFFECTIVE provider, never the configured one: a provider that was selected but cannot
 * run has already fallen back to OpenStreetMap server-side, and the records on the board are then
 * OSM records. Drawing them on a Google map because someone once selected Google would label the
 * pool with a provider that never produced it.
 */
export function mapProviderFor(effectiveProvider: string | null | undefined): MapProvider {
  return effectiveProvider === GOOGLE_DISCOVERY_PROVIDER ? "google" : "openstreetmap";
}

/**
 * The browser Maps key, or null when none is configured.
 *
 * SEPARATE FROM THE SERVER'S PLACES CREDENTIAL, AND THAT IS THE WHOLE POINT. The discovery key lives
 * encrypted in the database, is used only for server-side Places calls, and must never reach a
 * browser. A Maps JavaScript map needs its own key, referrer-restricted to the app's origins, which
 * is a different credential with different restrictions — so it is read from public frontend
 * configuration and nothing here ever reaches for the other one.
 *
 * Returning null is a supported state, not an error: the board says so plainly rather than pretending
 * to be a map it cannot draw.
 */
export function googleMapsBrowserKey(): string | null {
  const key = process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY;

  return typeof key === "string" && key.trim() !== "" ? key : null;
}

/**
 * WHICH RENDERER ACTUALLY DRAWS THE BOARD — a narrower question than which provider found the data.
 *
 * A MAP FALLBACK IS NOT A PROVIDER FALLBACK. When the structured places provider is effective but
 * its map cannot run — no browser key, or the Maps script failed to load — the board draws the
 * OpenStreetMap map instead. The discovery provider does not change, the records do not change, and
 * `foundBy` still reports Google: only the tiles differ. Previously this state showed a
 * configuration panel where a map should be, which left a working prospect pool looking broken.
 *
 * Deliberately pure, and separate from {@see mapProviderFor}: that one answers "whose data is this",
 * this one answers "what can we draw right now". Collapsing them would make an unavailable renderer
 * look like a provider change.
 */
export function mapRendererFor({
  effectiveProvider,
  hasBrowserKey,
  googleFailed = false,
}: {
  effectiveProvider: string | null | undefined;
  /** Whether a browser-restricted Maps JavaScript key is configured. */
  hasBrowserKey: boolean;
  /** Whether the Maps script has already failed to load this session. */
  googleFailed?: boolean;
}): MapProvider {
  if (mapProviderFor(effectiveProvider) !== "google") {
    return "openstreetmap";
  }

  // Google is the provider, so Google's map is wanted — but only if it can actually be drawn.
  return hasBrowserKey && !googleFailed ? "google" : "openstreetmap";
}
