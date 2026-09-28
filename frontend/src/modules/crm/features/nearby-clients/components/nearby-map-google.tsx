"use client";

import { useEffect, useRef, useState } from "react";
import { googleMapsBrowserKey } from "@/modules/crm/features/nearby-clients/lib/map-provider";
import {
  loadGoogleMaps,
  type GMap,
  type GMapObject,
  type GMarker,
} from "@/modules/crm/features/nearby-clients/lib/google-maps-loader";
import type { NearbyClient, NearbyPriority } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";
import type { NearbyOrigin } from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";

type Props = {
  clients: NearbyClient[];
  origin: NearbyOrigin | null;
  radiusKm: number;
  selectedId: string | null;
  onSelect: (id: string) => void;
  onPlanVisit: (client: NearbyClient) => void;
  onDirections: (client: NearbyClient) => void;
  onQualify: (client: NearbyClient) => void;
  /**
   * Called when Google's map cannot be drawn — no browser key, or the Maps script failed to load.
   * The seam responds by rendering the OpenStreetMap map instead. It is a RENDERER signal only and
   * never changes the discovery provider.
   */
  onUnavailable: () => void;
};

/** Same priority colours the OpenStreetMap lane uses, so a pin means one thing on either map. */
const PIN_COLOR: Record<NearbyPriority, string> = {
  high: "#ef4444",
  medium: "#f59e0b",
  low: "#3b82f6",
};

/** Shown only when there is nothing to frame — never used to place a business. */
const FALLBACK_CENTER = { lat: 12.8797, lng: 121.774 };

/**
 * The Google map lane of the Nearby board.
 *
 * RENDERED ONLY WHEN THE STRUCTURED PLACES PROVIDER IS EFFECTIVE (see nearby-map.tsx), so an
 * OpenStreetMap run never loads Google's script, never contacts Google, and never spends quota.
 *
 * EVERY MARKER IS A PROVIDER FACT. Positions come from `latitude`/`longitude` on discovered leads —
 * the coordinates the factual provider returned and the ingest stored. Nothing here geocodes a name,
 * derives a position from an address, or accepts a coordinate from any other source: a business
 * without stored coordinates is simply not drawn, because inventing where it is would be worse than
 * omitting it.
 *
 * FAILURE IS A MESSAGE, NEVER A BROKEN PAGE. A missing key, a blocked script or a referrer rejection
 * each render as their own panel; the prospect list beside the map is unaffected either way.
 */
export function NearbyMapGoogle({ clients, origin, radiusKm, selectedId, onSelect, onUnavailable }: Props) {
  const key = googleMapsBrowserKey();
  const container = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<GMap | null>(null);
  const drawn = useRef<GMapObject[]>([]);
  const [status, setStatus] = useState<"loading" | "ready" | "error">("loading");

  const located = clients.filter((c) => c.latitude !== null && c.longitude !== null);

  // 1. Load the API and create the map once.
  useEffect(() => {
    if (key === null) {
      // Unreachable in practice — the seam only mounts this component when a key exists — but kept
      // honest: if it ever is, say so rather than render an empty frame.
      onUnavailable();
      return;
    }
    let cancelled = false;

    loadGoogleMaps(key)
      .then((google) => {
        if (cancelled || !container.current || mapRef.current) return;
        mapRef.current = new google.maps.Map(container.current, {
          center: FALLBACK_CENTER,
          zoom: 6,
          mapTypeControl: false,
          streetViewControl: false,
          fullscreenControl: false,
        });
        setStatus("ready");
      })
      .catch(() => {
        if (cancelled) return;
        setStatus("error");
        // Hand the board back to the OpenStreetMap map rather than sitting on a failed frame.
        onUnavailable();
      });

    return () => {
      cancelled = true;
    };
  }, [key, onUnavailable]);

  // 2. Redraw markers whenever the result set, origin, radius or selection changes.
  useEffect(() => {
    const map = mapRef.current;
    if (key === null || status !== "ready" || !map) return;

    const google = globalThis.google;
    if (!google?.maps) return;

    for (const object of drawn.current) object.setMap(null);
    drawn.current = [];

    const bounds = new google.maps.LatLngBounds();

    for (const client of located) {
      const position = { lat: client.latitude as number, lng: client.longitude as number };
      const selected = client.id === selectedId;
      const marker = new google.maps.Marker({
        map,
        position,
        title: client.business,
        icon: {
          path: 0, // google.maps.SymbolPath.CIRCLE
          scale: selected ? 11 : 7,
          fillColor: PIN_COLOR[client.priority],
          fillOpacity: 1,
          strokeColor: "#ffffff",
          strokeWeight: 2,
        },
      }) as GMarker;
      marker.addListener("click", () => onSelect(client.id));
      drawn.current.push(marker);
      bounds.extend(position);
    }

    if (origin) {
      const here = { lat: origin.lat, lng: origin.lng };
      drawn.current.push(
        new google.maps.Marker({
          map,
          position: here,
          title: origin.label,
          icon: { path: 0, scale: 8, fillColor: "#14b8a6", fillOpacity: 1, strokeColor: "#ffffff", strokeWeight: 3 },
        }),
      );
      // The radius the list is actually filtered by, drawn so the two always agree.
      drawn.current.push(
        new google.maps.Circle({
          map,
          center: here,
          radius: radiusKm * 1000,
          strokeColor: "#14b8a6",
          strokeOpacity: 0.7,
          strokeWeight: 1,
          fillColor: "#14b8a6",
          fillOpacity: 0.08,
        }),
      );
      bounds.extend(here);
    }

    if (!bounds.isEmpty()) map.fitBounds(bounds, 48);
    else {
      map.setCenter(FALLBACK_CENTER);
      map.setZoom(6);
    }
  }, [key, status, located, origin, radiusKm, selectedId, onSelect]);

  // NO DEAD-END PANELS. Both unavailable states are reported upward instead, and the seam swaps in
  // the OpenStreetMap map — the records are Google's either way, so a missing map credential must
  // never leave a working pool without a map. `null` here is momentary: the parent re-renders with
  // the OSM map on the same tick it is told.
  if (key === null || status === "error") {
    return null;
  }

  return (
    <div className="relative h-full min-h-[480px] w-full">
      <div ref={container} className="h-full min-h-[480px] w-full" data-testid="nearby-google-map" />
      {status === "loading" && (
        <div className="absolute inset-0 animate-pulse bg-muted" aria-hidden />
      )}
    </div>
  );
}
