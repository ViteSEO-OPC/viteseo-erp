/**
 * Loads the Google Maps JavaScript API — once, on demand, with no package dependency.
 *
 * WHY NO LIBRARY. The Maps JS API is a script tag and a global; a wrapper package would add a
 * dependency, a version to track and a supply-chain surface to justify, for something this file does
 * in forty lines. If richer Maps features are wanted later, that is the moment to argue for a
 * package — not before.
 *
 * WHY ON DEMAND. The script is fetched only when the Google map lane actually renders, so an
 * OpenStreetMap run — the default — never contacts Google at all. That matters for a board most
 * users will see in OSM mode: no request, no quota, no third-party connection they did not ask for.
 *
 * THE KEY IS THE BROWSER KEY. It is read from public frontend configuration and nothing here ever
 * reaches for the server-side discovery credential, which stays encrypted in the database and is
 * used only for server-side Places calls.
 */

/** The narrow slice of the Maps API this board uses. Hand-typed to avoid a types package. */
export type LatLngLiteral = { lat: number; lng: number };

export interface GLatLngBounds {
  extend(point: LatLngLiteral): GLatLngBounds;
  isEmpty(): boolean;
}

export interface GMap {
  fitBounds(bounds: GLatLngBounds, padding?: number): void;
  setCenter(center: LatLngLiteral): void;
  setZoom(zoom: number): void;
}

export interface GMapObject {
  setMap(map: GMap | null): void;
}

export interface GMarker extends GMapObject {
  addListener(event: string, handler: () => void): void;
}

export interface GoogleMapsApi {
  maps: {
    Map: new (element: HTMLElement, options: Record<string, unknown>) => GMap;
    Marker: new (options: Record<string, unknown>) => GMarker;
    Circle: new (options: Record<string, unknown>) => GMapObject;
    LatLngBounds: new () => GLatLngBounds;
  };
}

declare global {
  var google: GoogleMapsApi | undefined;
}

const SCRIPT_ID = "nearby-google-maps-js";

/** One in-flight load shared by every caller — React strict mode mounts twice, quotas do not. */
let pending: Promise<GoogleMapsApi> | null = null;

export function loadGoogleMaps(apiKey: string): Promise<GoogleMapsApi> {
  if (typeof window === "undefined") {
    return Promise.reject(new Error("Google Maps can only load in a browser."));
  }
  if (globalThis.google?.maps) {
    return Promise.resolve(globalThis.google);
  }
  if (pending) {
    return pending;
  }

  pending = new Promise<GoogleMapsApi>((resolve, reject) => {
    const existing = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null;

    const settle = () => {
      if (globalThis.google?.maps) resolve(globalThis.google);
      else reject(new Error("Google Maps loaded without the maps namespace."));
    };

    if (existing) {
      existing.addEventListener("load", settle, { once: true });
      existing.addEventListener("error", () => reject(new Error("Google Maps failed to load.")), { once: true });
      return;
    }

    const script = document.createElement("script");
    script.id = SCRIPT_ID;
    // `encodeURIComponent` so a malformed key can never break out of the query string. The key is a
    // browser key by definition — restricted by HTTP referrer — and is visible to the page, exactly
    // as Google's own documentation expects. The SERVER credential is a different secret entirely.
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&loading=async`;
    script.async = true;
    script.defer = true;
    script.addEventListener("load", settle, { once: true });
    script.addEventListener(
      "error",
      () => {
        // Allow a retry after a transient failure rather than pinning the error forever.
        pending = null;
        reject(new Error("Google Maps failed to load."));
      },
      { once: true },
    );
    document.head.appendChild(script);
  });

  return pending;
}
