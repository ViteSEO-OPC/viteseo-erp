"use client";

import dynamic from "next/dynamic";
import { cn } from "@/modules/crm/lib/utils";
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
  /** Id of the prospect whose claim is in flight — guards the popup button, as the list card does. */
  qualifyingId: string | null;
  className?: string;
};

export function NearbyMap({ className, ...props }: Props) {
  return (
    <div className={cn("relative isolate overflow-hidden rounded-xl border border-border shadow-card", className)}>
      <NearbyMapInner {...props} />
    </div>
  );
}
