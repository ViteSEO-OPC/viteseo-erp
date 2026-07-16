"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { MapContainer, TileLayer, Marker, Popup, ZoomControl, useMap, useMapEvents } from "react-leaflet";
import MarkerClusterGroup from "react-leaflet-cluster";
import L from "leaflet";
import { LocateFixed } from "lucide-react";
import "leaflet/dist/leaflet.css";
import "leaflet.markercluster/dist/MarkerCluster.css";
import "leaflet.markercluster/dist/MarkerCluster.Default.css";
import { Button } from "@/modules/crm/components/ui/button";
import { useTheme } from "@/modules/crm/providers/theme-provider";
import { centroid, type LatLng } from "@/modules/crm/features/nearby-clients/lib/distance";
import type { NearbyClient, NearbyPriority } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

const PIN_COLOR: Record<NearbyPriority, string> = {
  high: "#ef4444",
  medium: "#f59e0b",
  low: "#3b82f6",
};

// Keyless CARTO basemaps — theme-aware (Positron light / Dark Matter dark). OSM + CARTO attribution.
const TILES = {
  light: "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png",
  dark: "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png",
};
const ATTRIBUTION =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>';

const MANILA_FALLBACK: LatLng = { lat: 14.5547, lng: 121.0244 };

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

type Props = {
  clients: NearbyClient[];
  selectedId: string | null;
  onSelect: (id: string) => void;
  onPlanVisit: (client: NearbyClient) => void;
  onDirections: (client: NearbyClient) => void;
};

/**
 * Re-frames the view to fit the current result set. Re-runs whenever `fitKey` changes — that key
 * folds in both the result-set identity (so filtering re-frames) and a manual reset nonce (so the
 * "Reset view" button re-frames). Not a once-only fit.
 */
function FitBounds({ points, fitKey }: { points: LatLng[]; fitKey: string }) {
  const map = useMap();
  const lastKey = useRef<string | null>(null);
  useEffect(() => {
    const first = points[0];
    if (!first || lastKey.current === fitKey) return;
    lastKey.current = fitKey;
    // The map mounts via dynamic import in a flex/grid; settle + invalidateSize before fitting.
    const id = setTimeout(() => {
      map.invalidateSize();
      if (points.length === 1) {
        map.setView([first.lat, first.lng], 15);
      } else {
        map.fitBounds(points.map((p) => [p.lat, p.lng]), { padding: [48, 48] });
      }
    }, 150);
    return () => clearTimeout(id);
  }, [fitKey, points, map]);
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

export function NearbyMapInner({ clients, selectedId, onSelect, onPlanVisit, onDirections }: Props) {
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
  const initialCenter = centroid(points) ?? MANILA_FALLBACK;
  // Re-frame when the result set changes (filtering) or the user resets the view.
  const fitKey = useMemo(
    () => `${located.map((c) => c.id).join(",")}::${fitNonce}`,
    [located, fitNonce],
  );

  const resetView = () => setFitNonce((n) => n + 1);

  return (
    <div className="relative h-full w-full">
      <MapContainer
        center={[initialCenter.lat, initialCenter.lng]}
        zoom={13}
        zoomControl={false}
        scrollWheelZoom
        className="h-full min-h-[480px] w-full"
      >
        <TileLayer key={theme} url={theme === "dark" ? TILES.dark : TILES.light} attribution={ATTRIBUTION} />
        <ZoomControl position="bottomright" />
        <FitBounds points={points} fitKey={fitKey} />
        <ViewportWatcher points={points} onChange={setAnyInView} />
        <SelectionController selectedId={selectedId} markerRefs={markerRefs} clusterRef={clusterRef} />

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
                  <div className="mt-2 flex items-center gap-2">
                    <Button variant="default" size="sm" onClick={() => onPlanVisit(c)}>+ Plan visit</Button>
                    <Button variant="outline" size="sm" onClick={() => onDirections(c)}>Directions</Button>
                  </div>
                </div>
              </Popup>
            </Marker>
          ))}
        </MarkerClusterGroup>
      </MapContainer>

      {/* Reset view — always return the map to the current result set. */}
      {located.length > 0 && (
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
