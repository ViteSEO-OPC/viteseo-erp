"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { MapContainer, TileLayer, Marker, Popup, Circle, ZoomControl, useMap, useMapEvents } from "react-leaflet";
import MarkerClusterGroup from "react-leaflet-cluster";
import L from "leaflet";
import { LocateFixed } from "lucide-react";
import "leaflet/dist/leaflet.css";
import "leaflet.markercluster/dist/MarkerCluster.css";
import "leaflet.markercluster/dist/MarkerCluster.Default.css";
import { Button } from "@/modules/crm/components/ui/button";
import { useTheme } from "@/modules/crm/providers/theme-provider";
import { centroid, type LatLng } from "@/modules/crm/features/nearby-clients/lib/distance";
import { WebsiteIntelBadge } from "@/modules/crm/features/nearby-clients/components/website-intel-badge";
import type { NearbyClient, NearbyPriority } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";
import type { NearbyOrigin } from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";

const PIN_COLOR: Record<NearbyPriority, string> = {
  high: "#ef4444",
  medium: "#f59e0b",
  low: "#3b82f6",
};

// CARTO basemaps — theme-aware (Positron light / Dark Matter dark). OSM + CARTO attribution.
// The map provider lives entirely behind this component (see nearby-map.tsx): the rest of the Nearby
// module speaks only in coordinates + NearbyClient, so this Leaflet/OSM layer can be swapped for
// another provider (e.g. Google Maps) without touching the hook, filters, list, or page.
//
// CARTO ended keyless raster access and now stamps "API KEY REQUIRED" into the tile image itself
// (a 200 with a defaced PNG, not an error), so the free basemap key is appended when configured.
// Absent the key the URLs stay byte-identical to before rather than requesting `?key=undefined`,
// mirroring how echo.ts declines to start realtime when its key is missing.
const CARTO_KEY_PARAM = process.env.NEXT_PUBLIC_CARTO_BASEMAP_KEY
  ? `?key=${process.env.NEXT_PUBLIC_CARTO_BASEMAP_KEY}`
  : "";
const TILES = {
  light: `https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png${CARTO_KEY_PARAM}`,
  dark: `https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png${CARTO_KEY_PARAM}`,
};
const ATTRIBUTION =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>';

// National fallback centre (roughly the middle of the Philippines) for the empty/no-origin view.
const PH_FALLBACK: LatLng = { lat: 12.8797, lng: 121.774 };

function pinIcon(priority: NearbyPriority, selected: boolean): L.DivIcon {
  const size = selected ? 30 : 22;
  const color = PIN_COLOR[priority];
  const ring = selected ? "box-shadow:0 0 0 4px rgba(20,184,166,0.55);" : "";
  return L.divIcon({
    className: "nearby-pin",
    html: `<span style="display:block;width:${size}px;height:${size}px;border-radius:9999px;background:${color};border:2px solid #fff;${ring}"></span>`,
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
    popupAnchor: [0, -size / 2],
  });
}

// Distinct crosshair marker for the search origin (where the radius is measured from).
const ORIGIN_ICON = L.divIcon({
  className: "nearby-origin",
  html: `<span style="display:block;width:18px;height:18px;border-radius:9999px;background:#0d9488;border:3px solid #fff;box-shadow:0 0 0 4px rgba(13,148,136,0.35);"></span>`,
  iconSize: [18, 18],
  iconAnchor: [9, 9],
});

type Props = {
  clients: NearbyClient[];
  origin: NearbyOrigin | null;
  radiusKm: number;
  selectedId: string | null;
  onSelect: (id: string) => void;
  onPlanVisit: (client: NearbyClient) => void;
  onDirections: (client: NearbyClient) => void;
  onQualify: (client: NearbyClient) => void;
  /** Id of the prospect whose claim is in flight — guards the popup button, as the list card does. */
  qualifyingId: string | null;
};

/**
 * Re-frames the view whenever `fitKey` changes. With an origin, it frames the radius circle so the
 * whole search area is visible; without one, it fits all result markers (or the whole country when
 * empty). `fitKey` folds in the origin, radius, result-set identity, and a manual-reset nonce.
 */
function FitBounds({
  points,
  origin,
  radiusKm,
  fitKey,
}: {
  points: LatLng[];
  origin: NearbyOrigin | null;
  radiusKm: number;
  fitKey: string;
}) {
  const map = useMap();
  const lastKey = useRef<string | null>(null);
  useEffect(() => {
    if (lastKey.current === fitKey) return;
    lastKey.current = fitKey;
    const id = setTimeout(() => {
      map.invalidateSize();
      if (origin) {
        // Frame the radius circle (a square of side 2·radius centred on the origin).
        const bounds = L.latLng(origin.lat, origin.lng).toBounds(radiusKm * 2000);
        map.fitBounds(bounds, { padding: [36, 36] });
      } else if (points.length === 1) {
        const first = points[0]!;
        map.setView([first.lat, first.lng], 15);
      } else if (points.length > 1) {
        map.fitBounds(points.map((p) => [p.lat, p.lng]), { padding: [48, 48] });
      }
    }, 150);
    return () => clearTimeout(id);
  }, [fitKey, points, origin, radiusKm, map]);
  return null;
}

/** Tracks whether any result marker is within the current map viewport (for the out-of-view hint). */
function ViewportWatcher({ points, onChange }: { points: LatLng[]; onChange: (anyInView: boolean) => void }) {
  const map = useMapEvents({
    moveend: () => {
      if (points.length === 0) {
        onChange(true);
        return;
      }
      const bounds = map.getBounds();
      onChange(points.some((p) => bounds.contains([p.lat, p.lng])));
    },
  });
  return null;
}

/**
 * List → map sync: when the selection *changes*, fly to that business (declustering if needed) and
 * open its popup. Keyed off `selectedId` only (via the marker's own position).
 */
function SelectionController({
  selectedId,
  markerRefs,
  clusterRef,
}: {
  selectedId: string | null;
  markerRefs: React.RefObject<Record<string, L.Marker>>;
  clusterRef: React.RefObject<L.MarkerClusterGroup | null>;
}) {
  const map = useMap();
  const prev = useRef<string | null>(null);
  useEffect(() => {
    if (selectedId === prev.current) return;
    prev.current = selectedId;
    if (!selectedId) return;
    const marker = markerRefs.current[selectedId];
    if (!marker) return;

    // Defer openPopup a tick so it lands after any pending re-render.
    const open = () => window.setTimeout(() => marker.openPopup(), 90);
    const cluster = clusterRef.current;
    if (cluster && typeof cluster.zoomToShowLayer === "function") {
      cluster.zoomToShowLayer(marker, open);
    } else {
      map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 16));
      map.once("moveend", open);
    }
  }, [selectedId, markerRefs, clusterRef, map]);
  return null;
}

export function NearbyMapInner({ clients, origin, radiusKm, selectedId, onSelect, onPlanVisit, onDirections, onQualify, qualifyingId }: Props) {
  const { theme } = useTheme();
  const markerRefs = useRef<Record<string, L.Marker>>({});
  const clusterRef = useRef<L.MarkerClusterGroup | null>(null);
  const [fitNonce, setFitNonce] = useState(0);
  const [anyInView, setAnyInView] = useState(true);

  const located = useMemo(
    () => clients.filter((c) => c.latitude !== null && c.longitude !== null),
    [clients],
  );
  const points = useMemo<LatLng[]>(
    () => located.map((c) => ({ lat: c.latitude as number, lng: c.longitude as number })),
    [located],
  );
  const initialCenter = origin ?? centroid(points) ?? PH_FALLBACK;
  // Re-frame when the origin/radius change, the result set changes, or the user resets the view.
  const fitKey = useMemo(
    () =>
      `${origin ? `${origin.lat.toFixed(4)},${origin.lng.toFixed(4)}@${radiusKm}` : "none"}::${located
        .map((c) => c.id)
        .join(",")}::${fitNonce}`,
    [origin, radiusKm, located, fitNonce],
  );

  const resetView = () => setFitNonce((n) => n + 1);

  return (
    <div className="relative h-full w-full">
      <MapContainer
        center={[initialCenter.lat, initialCenter.lng]}
        zoom={origin ? 12 : 6}
        zoomControl={false}
        scrollWheelZoom
        className="h-full min-h-[480px] w-full"
      >
        <TileLayer key={theme} url={theme === "dark" ? TILES.dark : TILES.light} attribution={ATTRIBUTION} />
        <ZoomControl position="bottomright" />
        <FitBounds points={points} origin={origin} radiusKm={radiusKm} fitKey={fitKey} />
        <ViewportWatcher points={points} onChange={setAnyInView} />
        <SelectionController selectedId={selectedId} markerRefs={markerRefs} clusterRef={clusterRef} />

        {/* Search origin + radius (only when an origin is chosen). */}
        {origin && (
          <>
            <Circle
              center={[origin.lat, origin.lng]}
              radius={radiusKm * 1000}
              pathOptions={{ color: "#0d9488", weight: 1.5, fillColor: "#0d9488", fillOpacity: 0.08 }}
            />
            <Marker position={[origin.lat, origin.lng]} icon={ORIGIN_ICON} zIndexOffset={1000}>
              <Popup>
                <p className="text-sm font-bold text-card-foreground">{origin.label}</p>
                <p className="text-xs text-muted-foreground">Search origin · {radiusKm} km radius</p>
              </Popup>
            </Marker>
          </>
        )}

        <MarkerClusterGroup ref={clusterRef} chunkedLoading showCoverageOnHover={false}>
          {located.map((c) => (
            <Marker
              key={c.id}
              position={[c.latitude as number, c.longitude as number]}
              icon={pinIcon(c.priority, selectedId === c.id)}
              ref={(m) => {
                if (m) markerRefs.current[c.id] = m;
                else delete markerRefs.current[c.id];
              }}
              eventHandlers={{ click: () => onSelect(c.id) }}
            >
              <Popup>
                <div className="min-w-[13rem]">
                  <p className="text-sm font-bold text-card-foreground">{c.business}</p>
                  <p className="text-xs text-muted-foreground">
                    {[c.niche, c.area].filter(Boolean).join(" · ")}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {c.distanceKm !== undefined ? `${c.distanceKm.toFixed(1)} km away · ` : ""}
                    {c.contact}
                  </p>
                  {c.website && (
                    <div className="mt-2">
                      <WebsiteIntelBadge website={c.website} compact />
                    </div>
                  )}
                  {c.qualified && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Already added to CRM
                      {c.qualifiedBy ? <> · Added by: <span className="font-medium">{c.qualifiedBy.name}</span></> : null}
                    </p>
                  )}
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Button
                      variant="default"
                      size="sm"
                      disabled={qualifyingId === c.id || c.qualified}
                      onClick={() => onQualify(c)}
                      title={
                        c.qualified
                          ? `Already accepted into the CRM${c.qualifiedBy ? ` by ${c.qualifiedBy.name}` : " by another BD"} — it can no longer be accepted.`
                          : "Accept this prospect into your CRM leads"
                      }
                    >
                      {c.qualified ? "Already in CRM" : qualifyingId === c.id ? "Accepting…" : "Accept into CRM"}
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => onPlanVisit(c)}>Plan visit</Button>
                    <Button variant="ghost" size="sm" onClick={() => onDirections(c)}>Directions</Button>
                  </div>
                </div>
              </Popup>
            </Marker>
          ))}
        </MarkerClusterGroup>
      </MapContainer>

      {/* Reset view — always return the map to the current result set / search area. */}
      {(located.length > 0 || origin) && (
        <button
          type="button"
          onClick={resetView}
          className="absolute left-3 top-3 z-[1000] inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold text-card-foreground shadow-sm transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <LocateFixed className="h-3.5 w-3.5 text-teal-600" aria-hidden />
          Reset view
        </button>
      )}

      {/* Out-of-view: results exist but none are visible in the current map view. */}
      {located.length > 0 && !anyInView && (
        <div className="absolute left-1/2 top-3 z-[1000] flex -translate-x-1/2 items-center gap-2 rounded-lg border border-border bg-card px-3 py-1.5 text-xs text-card-foreground shadow-md">
          <span className="text-muted-foreground">
            {located.length} result{located.length === 1 ? "" : "s"} outside this view
          </span>
          <button type="button" onClick={resetView} className="font-semibold text-teal-600 hover:underline">
            Reset view
          </button>
        </div>
      )}
    </div>
  );
}
