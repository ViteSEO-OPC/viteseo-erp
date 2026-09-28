"use client";

import dynamic from "next/dynamic";
import { useCallback, useState } from "react";
import { cn } from "@/modules/crm/lib/utils";
import { NearbyMapGoogle } from "@/modules/crm/features/nearby-clients/components/nearby-map-google";
import { useMapProvider } from "@/modules/crm/features/nearby-clients/hooks/use-map-provider";
import {
  googleMapsBrowserKey,
  mapRendererFor,
} from "@/modules/crm/features/nearby-clients/lib/map-provider";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";
import type { NearbyOrigin } from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";

// Leaflet touches the DOM/`window`, so the map is client-only — loaded exactly like the app's
// charts and calendar (dynamic import, no SSR).
const NearbyMapInner = dynamic(
  () => import("./nearby-map-inner").then((m) => m.NearbyMapInner),
  {
    ssr: false,
    loading: () => <div className="h-full min-h-[480px] w-full animate-pulse rounded-xl bg-muted" />,
  },
);

type Props = {
  clients: NearbyClient[];
  origin: NearbyOrigin | null;
  radiusKm: number;
  selectedId: string | null;
  onSelect: (id: string) => void;
  onPlanVisit: (client: NearbyClient) => void;
  onDirections: (client: NearbyClient) => void;
  onQualify: (client: NearbyClient) => void;
  className?: string;
};

/**
 * THE MAP-PROVIDER SEAM. This component has always been the place the map implementation is chosen —
 * everything above it speaks only in coordinates and `NearbyClient`, so swapping the renderer
 * touches no hook, filter, list or page. It now makes that choice from the EFFECTIVE discovery
 * provider instead of assuming one.
 *
 * OpenStreetMap remains the default and the answer to every uncertainty: while the provider lookup
 * is in flight, if it fails, or if the provider is anything other than the structured places
 * provider, the existing OSM map renders exactly as before.
 *
 * AND IT IS ALSO THE MAP FALLBACK. If the structured places provider is effective but its map
 * cannot run — no browser key, or the Maps script fails to load — the OpenStreetMap map draws the
 * same records instead. That is a RENDERER fallback only: the discovery provider, the records and
 * `foundBy` are untouched, so a working prospect pool never appears broken because a map credential
 * is missing.
 */
export function NearbyMap({ className, ...props }: Props) {
  const { effectiveProvider } = useMapProvider();
  // Latched for the session: once the Maps script has failed, retrying it on every re-render would
  // stall the board behind a request already known to fail.
  const [googleFailed, setGoogleFailed] = useState(false);
  const onUnavailable = useCallback(() => setGoogleFailed(true), []);

  const renderer = mapRendererFor({
    effectiveProvider,
    hasBrowserKey: googleMapsBrowserKey() !== null,
    googleFailed,
  });

  return (
    <div className={cn("relative isolate overflow-hidden rounded-xl border border-border shadow-card", className)}>
      {renderer === "google" ? (
        <NearbyMapGoogle {...props} onUnavailable={onUnavailable} />
      ) : (
        <NearbyMapInner {...props} />
      )}
    </div>
  );
}
