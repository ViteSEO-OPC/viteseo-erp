import { ClientRangeCard } from "@/modules/crm/features/nearby-clients/components/client-range-card";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import type { NearbyClient } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

type Props = {
  clients: NearbyClient[];
  selectedId: string | null;
  onSelect: (id: string) => void;
  onPlanVisit: (client: NearbyClient) => void;
  onDirections: (client: NearbyClient) => void;
  onQualify: (client: NearbyClient) => void;
  qualifyingId: string | null;
  /**
   * True when the Prospect Pool itself is empty — i.e. nothing has been discovered yet, as opposed
   * to the filters having excluded everything. The two look identical in the list (zero cards) but
   * mean opposite things to a BD, so they must not share a message.
   */
  poolIsEmpty: boolean;
};

export function ClientsInRangeList({ clients, selectedId, onSelect, onPlanVisit, onDirections, onQualify, qualifyingId, poolIsEmpty }: Props) {
  const nearest = clients[0]?.distanceKm ?? null;
  const activeCount = clients.filter((c) => c.active).length;

  return (
    <div className="flex flex-col gap-3">
      <div>
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-bold text-card-foreground">Clients in range</h3>
          <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-teal-100 px-1.5 text-[11px] font-bold text-teal-700">
            {clients.length}
          </span>
        </div>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {nearest !== null ? `Nearest ${nearest.toFixed(1)} km · ` : ""}
          {activeCount} active
        </p>
      </div>

      {clients.length > 0 ? (
        <div className="flex flex-col gap-3">
          {clients.map((c) => (
            <ClientRangeCard
              key={c.id}
              client={c}
              selected={selectedId === c.id}
              onSelect={() => onSelect(c.id)}
              onPlanVisit={() => onPlanVisit(c)}
              onDirections={() => onDirections(c)}
              onQualify={() => onQualify(c)}
              qualifying={qualifyingId === c.id}
            />
          ))}
        </div>
      ) : (
        <EmptyState
          message={
            poolIsEmpty
              ? "No discovered businesses yet. Run discovery to populate the Prospect Pool."
              : "No clients match these filters."
          }
        />
      )}
    </div>
  );
}
