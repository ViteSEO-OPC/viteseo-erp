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
import { AdvancedDiscoveryDialog } from "@/modules/crm/features/nearby-clients/components/advanced-discovery-dialog";
import { useDiscoveryCapability } from "@/modules/crm/features/nearby-clients/hooks/use-map-provider";
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
    matchesIgnoringPriority,
    isLoading,
    isError,
  } = useNearbyClients();

  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [planClient, setPlanClient] = useState<NearbyClient | null>(null);
  const [areasOpen, setAreasOpen] = useState(false);
  // Advanced Lead Discovery is an ADDITIVE surface: it fills the pool this board reads, and opening
  // or closing it changes nothing about the board itself.
  const [discoveryOpen, setDiscoveryOpen] = useState(false);
  // Additive capability, read from the one endpoint that already resolves the provider.
  const { canDiscover } = useDiscoveryCapability();

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
    <div className="flex flex-col gap-4 lg:min-h-[45rem]">
      {/* Header row: context + admin registry access */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <MapPin className="h-4 w-4 text-teal-600" aria-hidden />
          <span className="text-sm text-muted-foreground">
            Discovered businesses in your target areas.
          </span>
        </div>
        {isManager && (
          <div className="flex flex-wrap items-center gap-2">
            {/* Starts a fresh on-demand search against the factual provider.
                TWO GATES, BOTH PRE-EXISTING. The role check is the same one the registry action
                beside it uses — no new frontend permission. The CAPABILITY check asks
                `GET /discovery/config` which provider is EFFECTIVE, resolved from
                `crm_nerby_provider_settings` with its fallbacks already applied.
                Why capability and not only role: on the OpenStreetMap baseline this button offers a
                paid Google search that would quietly run as a free Overpass sweep. It would work,
                and its results would not be what its name promises — a difference invisible until
                someone compares them. With no Stage 2 capability configured the board is exactly
                what it was before Stage 2 existed. */}
            {canDiscover && (
              <Button variant="outline" size="sm" onClick={() => setDiscoveryOpen(true)}>
                Advanced Lead Discovery
              </Button>
            )}
            <Button variant="outline" size="sm" onClick={() => setAreasOpen(true)}>
              Manage target areas
            </Button>
          </div>
        )}
      </div>

      {/* Two panels that are deliberately NOT the same height.
          `items-start` is the whole mechanism: grid items stretch to the tallest row by default,
          which tied the results column to the map and made the map the ceiling on how many leads
          could be shown — 32px of list at one point, less than a single card. The two columns answer
          different questions and need different amounts of room: the map is a fixed canvas, the list
          is as long as five cards need. Each now sizes to its own content, and the map pins itself
          (below) so it stays usable while the longer column scrolls past it. */}
      <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
        {/* Left: filters above the results list.
            Below `lg` the panel must size to its CONTENT. It used to be a fixed `h-[70vh]
            min-h-[480px] overflow-hidden` box at every width, but the filter stack — search,
            origin, radius, priority, niche, status, source — is taller than 480px, so on a narrow
            viewport `overflow-hidden` clipped the bottom filters with no way to scroll to them,
            and the list underneath was left a sliver. The fixed height and the clipping therefore
            apply only from `lg`, where the pinned layout has the room to sustain them. */}
        <div className="flex flex-col rounded-xl border border-border bg-card shadow-card lg:col-span-4">
          {/* THE FILTERS ARE ALWAYS FULLY VISIBLE. `shrink-0` is deliberate at every width: a filter
              a user cannot see is a filter they cannot tell is applied, and a stack that scrolls
              hides that state behind a gesture. The list is the thing that scrolls — see below. */}
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
          {/* THE ONLY THING THAT SCROLLS. The filters above are `shrink-0` at every width and stay
              whole and reachable; the overflow lives here.
              Its height is STATED rather than inherited. As `flex-1` inside a panel pinned to the
              map's height the list got whatever the filters did not want, which is how it ended up
              shorter than one card. 66rem is what about five compact cards need — five × 179px plus
              their gaps, the list header and its padding — and not a pixel more, so the column is as
              short as the target allows. Lead six onward is reached by scrolling here.
              Below `lg` the viewport-proportional rules are unchanged: in a stacked layout the list
              should stay relative to the screen, not 1000px tall. */}
          <div className="max-h-[60vh] min-h-[20rem] overflow-y-auto border-t border-border-subtle px-5 py-4 lg:h-[66rem] lg:max-h-none lg:min-h-0">
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
                priorityFilter={filters.priority}
                matchesIgnoringPriority={matchesIgnoringPriority}
              />
            )}
          </div>
        </div>

        {/* Right: interactive map — its own height, no longer the ceiling on the list.
            `lg:h-[76vh]` reproduces the height it had while stretched to the row, so the map itself
            is unchanged in size. `sticky` is what keeps it usable now that the left column is
            taller: it stays in view as the list scrolls past instead of sliding away and leaving
            half the screen empty. Below `lg` the stacked layout is untouched. */}
        <NearbyMap
          className="h-[70vh] min-h-[480px] lg:sticky lg:top-6 lg:col-span-8 lg:h-[76vh]"
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

      {isManager && (
        <AdvancedDiscoveryDialog open={discoveryOpen && canDiscover} onOpenChange={setDiscoveryOpen} />
      )}
    </div>
  );
}
