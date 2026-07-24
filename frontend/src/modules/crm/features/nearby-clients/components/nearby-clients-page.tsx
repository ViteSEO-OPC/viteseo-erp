"use client";

import { useState } from "react";
import { MapPin } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { Button } from "@/modules/crm/components/ui/button";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useNearbyClients } from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";
import { useQualifyProspect } from "@/modules/crm/features/nearby-clients/hooks/use-qualify-prospect";
import { NearbyFilters } from "@/modules/crm/features/nearby-clients/components/nearby-filters";
import { ClientsInRangeList } from "@/modules/crm/features/nearby-clients/components/clients-in-range-list";
import { NearbyMap } from "@/modules/crm/features/nearby-clients/components/nearby-map";
import { PlanVisitModal } from "@/modules/crm/features/nearby-clients/components/plan-visit-modal";
import { TargetAreasDialog } from "@/modules/crm/features/nearby-clients/components/target-areas-dialog";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

/**
 * Nearby Clients — real discovered businesses (from the frozen enrichment pipeline) on an interactive
 * map. The list and map share one result set (`clients`), so they are always synchronized: selecting
 * in either highlights and reveals in the other, and panning the map never changes the set. The page
 * fills the viewport — only the client list scrolls; the map stays fixed beside it.
 */
export function NearbyClientsPage() {
  const { hasMinRole } = usePermissions();
  const isManager = hasMinRole("crm_admin");
  const {
    clients,
    filters,
    patch,
    options,
    origin,
    selectLocation,
    useMyLocation,
    clearOrigin,
    geoStatus,
    isLoading,
    isError,
  } = useNearbyClients();

  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [planClient, setPlanClient] = useState<NearbyClient | null>(null);
  const [areasOpen, setAreasOpen] = useState(false);

  // The qualification bridge: accept a prospect into the CRM. On success it leaves the pool.
  const qualify = useQualifyProspect();
  const onQualify = (client: NearbyClient) => qualify.mutate(client.id);

  const onDirections = (client: NearbyClient) => {
    if (client.latitude !== null && client.longitude !== null) {
      window.open(
        `https://www.google.com/maps/dir/?api=1&destination=${client.latitude},${client.longitude}`,
        "_blank",
        "noopener,noreferrer",
      );
    } else {
      toast.info("No coordinates available for this business yet.");
    }
  };

  return (
    <div className="flex flex-col gap-4 lg:h-full lg:min-h-0">
      {/* Header row: context + admin registry access */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <MapPin className="h-4 w-4 text-teal-600" aria-hidden />
          <span className="text-sm text-muted-foreground">
            Discovered businesses in your target areas.
          </span>
        </div>
        {isManager && (
          <Button variant="outline" size="sm" onClick={() => setAreasOpen(true)}>
            Manage target areas
          </Button>
        )}
      </div>

      {/* Enterprise two-panel layout. The page fills <main> exactly (no page-level scroll): the left
          panel pins its filters and scrolls its list internally; the map holds a fixed height. */}
      <div className="grid grid-cols-1 gap-6 lg:min-h-0 lg:flex-1 lg:grid-cols-12">
        {/* Left: pinned filters + internally-scrolling list */}
        <div className="flex h-[70vh] min-h-[480px] flex-col overflow-hidden rounded-xl border border-border bg-card shadow-card lg:col-span-4 lg:h-full lg:min-h-0">
          <div className="shrink-0 p-5 pb-4">
            <NearbyFilters
              filters={filters}
              patch={patch}
              options={options}
              origin={origin}
              selectLocation={selectLocation}
              useMyLocation={useMyLocation}
              clearOrigin={clearOrigin}
              geoStatus={geoStatus}
            />
          </div>
          <div className="min-h-0 flex-1 overflow-y-auto border-t border-border-subtle px-5 py-4">
            {isLoading ? (
              <div className="flex flex-col gap-3" aria-busy>
                {[0, 1, 2, 3].map((i) => (
                  <div key={i} className="h-24 w-full animate-pulse rounded-xl bg-muted" />
                ))}
              </div>
            ) : isError ? (
              <ErrorState message="Failed to load discovered businesses." />
            ) : (
              <ClientsInRangeList
                clients={clients}
                selectedId={selectedId}
                onSelect={setSelectedId}
                onPlanVisit={setPlanClient}
                onDirections={onDirections}
                onQualify={onQualify}
                qualifyingId={qualify.isPending ? (qualify.variables ?? null) : null}
              />
            )}
          </div>
        </div>

        {/* Right: interactive map — fixed height matching the left panel */}
        <NearbyMap
          className="h-[70vh] min-h-[480px] lg:col-span-8 lg:h-full"
          clients={clients}
          origin={origin}
          radiusKm={filters.radiusKm}
          selectedId={selectedId}
          onSelect={setSelectedId}
          onPlanVisit={setPlanClient}
          onDirections={onDirections}
          onQualify={onQualify}
        />
      </div>

      <PlanVisitModal
        open={planClient !== null}
        onOpenChange={(o) => { if (!o) setPlanClient(null); }}
        client={planClient}
      />

      {isManager && <TargetAreasDialog open={areasOpen} onOpenChange={setAreasOpen} />}
    </div>
  );
}
