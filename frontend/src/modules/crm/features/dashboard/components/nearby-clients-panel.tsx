"use client";

import { useRouter } from "next/navigation";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { NearbyMap } from "@/modules/crm/features/nearby-clients/components/nearby-map";
import { useNearbyClients } from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";
import { cn } from "@/modules/crm/lib/utils";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

// Prospecting-priority dot, using the same priority tokens as the rest of the CRM.
const PRIORITY_DOT: Record<string, string> = {
  high: "bg-priority-high",
  medium: "bg-priority-medium",
  low: "bg-priority-low",
};

// Intentional dashboard preview: the latest 12 saved clients (the hook returns them newest-first).
// The fixed-height card shows ~5 and scrolls rows 6–12; the full dataset lives on the Nearby
// Clients page ("View Nearby Clients"). This is a deliberate widget cap, not a data limitation.
const LIST_LIMIT = 12;

// Shared fixed height for both panels so the map and the saved-clients card always align, with
// the client list scrolling internally rather than growing the card.
const PANEL_HEIGHT = "h-[380px]";

/**
 * Dashboard "Nearby Clients" widget — map plus the closest saved clients.
 *
 * Reuses the Nearby Clients feature wholesale: the same `useNearbyClients` hook (GET /nearby-clients)
 * and the same Leaflet `NearbyMap` the full page renders, so the dashboard can never drift from it.
 * Read-only here: selecting anything routes to the full page rather than duplicating its actions.
 *
 * Distance: `distanceKm` is only defined once a search origin is chosen (a target location or
 * browser geolocation), which happens on the Nearby Clients page. The dashboard does not prompt for
 * location, so distance renders as "—" with an explanation rather than a fabricated figure.
 */
export function NearbyClientsPanel() {
  const router = useRouter();
  const { clients, origin, filters, isLoading, isError } = useNearbyClients();

  const goToPage = () => router.push("/zerem/nearby-clients");
  const visible = clients.slice(0, LIST_LIMIT);

  return (
    <section>
      <SectionHeader
        title="Nearby Clients"
        linkText="View Nearby Clients"
        linkHref="/zerem/nearby-clients"
        linkClassName="text-dashboard-link"
      />

      {isError && <ErrorState message="Failed to load nearby clients" />}

      {isLoading && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[7fr_5fr]">
          <Skeleton className={cn("w-full rounded-xl", PANEL_HEIGHT)} />
          <Skeleton className={cn("w-full rounded-xl", PANEL_HEIGHT)} />
        </div>
      )}

      {!isLoading && !isError && clients.length === 0 && (
        <EmptyState message="No nearby clients discovered yet" />
      )}

      {!isLoading && !isError && clients.length > 0 && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[7fr_5fr]">
          <div className="min-w-0">
            <NearbyMap
              clients={clients}
              origin={origin}
              radiusKm={filters.radiusKm}
              selectedId={null}
              onSelect={goToPage}
              onPlanVisit={goToPage}
              onDirections={goToPage}
              onQualify={goToPage}
              className={PANEL_HEIGHT}
            />
          </div>

          <div className={cn("flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-card", PANEL_HEIGHT)}>
            <p className="border-b border-border-subtle px-4 py-3 text-sm font-medium text-secondary-foreground">
              {origin ? "Closest saved clients to your location" : "Saved clients in your pipeline"}
            </p>

            <ul className="min-h-0 flex-1 divide-y divide-border-subtle overflow-y-auto">
              {visible.map((client) => (
                <NearbyRow key={client.id} client={client} hasOrigin={origin !== null} />
              ))}
            </ul>

            {!origin && (
              <p className="border-t border-border-subtle px-4 py-2.5 text-xs text-muted-foreground">
                Set a location on Nearby Clients to see distances.
              </p>
            )}
          </div>
        </div>
      )}
    </section>
  );
}

function NearbyRow({ client, hasOrigin }: { client: NearbyClient; hasOrigin: boolean }) {
  return (
    <li className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40">
      <span
        className={cn("h-2 w-2 shrink-0 rounded-full", PRIORITY_DOT[client.priority])}
        aria-hidden
      />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-card-foreground">{client.business}</p>
        {client.niche && (
          <p className="truncate text-xs text-muted-foreground">{client.niche}</p>
        )}
      </div>

      <StatusChip status={client.status as LeadStatus} />

      {/* Distance needs a chosen origin; without one it is genuinely unknown, not zero. */}
      <span
        className={cn(
          "w-14 shrink-0 text-right text-sm tabular-nums",
          hasOrigin && client.distanceKm !== undefined
            ? "font-semibold text-card-foreground"
            : "text-muted-foreground",
        )}
        title={hasOrigin ? undefined : "Distance requires a selected location"}
      >
        {hasOrigin && client.distanceKm !== undefined
          ? `${client.distanceKm.toFixed(1)} km`
          : "—"}
      </span>
    </li>
  );
}
