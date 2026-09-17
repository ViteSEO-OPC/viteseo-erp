/**
 * Why a browser did or did not give us a position.
 *
 * THIS EXISTS AS ITS OWN MODULE FOR ONE REASON: the decision used to live inside a React callback
 * that threw its input away — `() => setGeoStatus("denied")` — so every failure, including ones
 * nobody could have caused, was reported as a refused permission. A BD following that message goes
 * to a browser setting they never touched, while the real cause (usually an insecure origin in
 * development) stays invisible. Pulled out here, the mapping is a pure function that the project's
 * gates harness can drive directly, which is what stops it silently collapsing back into one answer.
 *
 * NOTHING HERE PRODUCES COORDINATES. It only classifies outcomes; the position always comes from the
 * browser itself.
 */

/** Why locating did or did not work — distinguishable causes, each with a different remedy. */
export type GeolocationStatus =
  | "idle"
  | "locating"
  | "granted"
  /** The user, or a browser policy, refused the permission. */
  | "denied"
  /** The device tried and could not fix a position — no GPS, no network location service. */
  | "unavailable"
  /** The device did not answer within the timeout. Retrying is reasonable. */
  | "timeout"
  /**
   * The page is not a SECURE CONTEXT, so the browser will not share a position at all.
   *
   * Browsers gate geolocation to HTTPS and localhost. A plain-HTTP LAN address — the ordinary
   * development setup — is neither, and Chrome answers with PERMISSION_DENIED, which is exactly why
   * this was mistaken for a refused permission for so long. Detected BEFORE asking, while the real
   * cause is still knowable.
   */
  | "insecure"
  /** No Geolocation API in this browser at all. */
  | "unsupported";

/**
 * The spec's own error codes.
 *
 * Compared numerically rather than through `error.PERMISSION_DENIED`, because those constants live
 * on the error INSTANCE: a plain object — which is what a test, or a polyfill, hands you — has the
 * code but not the constants, and reading them off it would silently fall through to one branch.
 */
export const GEOLOCATION_PERMISSION_DENIED = 1;

export const GEOLOCATION_POSITION_UNAVAILABLE = 2;

export const GEOLOCATION_TIMEOUT = 3;

/**
 * Whether a locate attempt can be made at all, or the reason it cannot.
 *
 * `null` means "go ahead and ask". Anything else is a cause the browser would otherwise report
 * misleadingly, and which no permission prompt can fix.
 */
export function geolocationPreflight(
  nav: { geolocation?: unknown } | undefined,
  win: { isSecureContext?: boolean } | undefined,
): Extract<GeolocationStatus, "unsupported" | "insecure"> | null {
  if (!nav || !nav.geolocation) {
    return "unsupported";
  }

  // Explicitly `=== false`: an environment that does not define `isSecureContext` (an old browser,
  // a non-DOM test context) must not be declared insecure on the strength of an absent property.
  if (win?.isSecureContext === false) {
    return "insecure";
  }

  return null;
}

/** The browser's own error code, mapped one-to-one onto the cause it names. */
export function geolocationStatusFor(error: { code?: number } | undefined): GeolocationStatus {
  switch (error?.code) {
    case GEOLOCATION_PERMISSION_DENIED:
      return "denied";
    case GEOLOCATION_TIMEOUT:
      return "timeout";
    case GEOLOCATION_POSITION_UNAVAILABLE:
      return "unavailable";
    default:
      // An unrecognised or absent code is a position we did not get, and nothing more specific can
      // honestly be claimed about it. Deliberately NOT "denied": inventing a cause is what this
      // module exists to stop.
      return "unavailable";
  }
}
