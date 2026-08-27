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
 * in either highlights and reveals in the other, and panning the map never changes the set. From
 * `lg` up the page fills the viewport — only the client list scrolls; the map stays fixed beside it.
 *
 * `lg:h-full` is a cap, not a size: on its own it pinned the page to the viewport with no way to
 * exceed it, so a short window squeezed the client list to a 33px sliver and — once the map's
 * `min-h-[480px]` pushed the row past the cap — dropped it below the fold with `<main>` reporting
 * zero scroll range, i.e. unreachable. `lg:min-h-[45rem]` pairs the cap with a floor: tall
 * viewports are unaffected (the floor only bites below ~927px of viewport height), and shorter
 * ones grow the page instead, which `<main>` (already `overflow-y-auto`) scrolls.
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
    totalCount,
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
    <div className="flex flex-col gap-4 lg:h-full lg:min-h-[45rem]">
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

      {/* Enterprise two-panel layout. From `lg` the page fills <main> exactly (no page-level
          scroll): the left panel pins its filters and scrolls its list internally; the map holds a
          fixed height. Below `lg` that pinning is dropped — see the panel and list below. */}
      <div className="grid grid-cols-1 gap-6 lg:min-h-0 lg:flex-1 lg:grid-cols-12">
        {/* Left: filters above the results list.
            Below `lg` the panel must size to its CONTENT. It used to be a fixed `h-[70vh]
            min-h-[480px] overflow-hidden` box at every width, but the filter stack — search,
            origin, radius, priority, niche, status, source — is taller than 480px, so on a narrow
            viewport `overflow-hidden` clipped the bottom filters with no way to scroll to them,
            and the list underneath was left a sliver. The fixed height and the clipping therefore
            apply only from `lg`, where the pinned layout has the room to sustain them. */}
        <div className="flex flex-col rounded-xl border border-border bg-card shadow-card lg:col-span-4 lg:h-full lg:min-h-0 lg:overflow-hidden">
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
          {/* Below `lg` the list owns a bounded height of its own rather than inheriting whatever
              the filters left over: 60vh keeps it proportional to the screen and the 20rem floor
              stops it collapsing into a sliver. From `lg` both are dropped and it goes back to
              filling the pinned panel. */}
          <div className="max-h-[60vh] min-h-[20rem] overflow-y-auto border-t border-border-subtle px-5 py-4 lg:max-h-none lg:min-h-0 lg:flex-1">
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
                // `totalCount` is the unfiltered pool size, so this distinguishes "nothing
                // discovered yet" from "your filters excluded everything" — the page previously
                // reported both as a filter miss, which reads as broken when the pool is empty.
                poolIsEmpty={totalCount === 0}
              />
            )}
          </div>
        </div>

        {/* Right: interactive map — fixed height matching the left panel. `lg:min-h-0` drops the
            480px floor from the pinned path only: as a grid item that floor forced the whole row to
            480px however little height was available, which is what pushed the panel and its list
            below the fold. The root's floor above now guarantees the row's height instead. Below
            `lg` the 480px floor still applies, so the stacked layout is unchanged. */}
        <NearbyMap
          className="h-[70vh] min-h-[480px] lg:col-span-8 lg:h-full lg:min-h-0"
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
