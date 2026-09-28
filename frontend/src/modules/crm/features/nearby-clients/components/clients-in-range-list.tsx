import { ClientRangeCard } from "@/modules/crm/features/nearby-clients/components/client-range-card";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import {
  nearbyEmptyMessage,
  nearbyEmptyReason,
} from "@/modules/crm/features/nearby-clients/lib/empty-reason";
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
  /** The selected priority label, or "" when none is chosen. */
  priorityFilter: string;
  /** How many rows would show if the priority filter alone were dropped. */
  matchesIgnoringPriority: number;
};

export function ClientsInRangeList({ clients, selectedId, onSelect, onPlanVisit, onDirections, onQualify, qualifyingId, poolIsEmpty, priorityFilter, matchesIgnoringPriority }: Props) {
  const nearest = clients[0]?.distanceKm ?? null;
  const activeCount = clients.filter((c) => c.active).length;

  return (
    <div className="flex flex-col gap-3">
      {/* THIS COUNT IS THE POOL, NOT A DISCOVERY RESULT, and the distinction is worth stating on
          screen rather than assuming. "Clients in range: 438" sitting beside a map of the Philippines
          invites exactly one reading — that a nationwide search found 438 businesses in the country —
          and that reading is false in both directions: the pool holds everything ever discovered by
          any lane plus whatever the current filters allow through, while a nationwide run's own
          totals live in its progress panel and nowhere else. Naming it "in your pool" and saying so
          underneath costs one line and removes the ambiguity. */}
      <div>
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-bold text-card-foreground">Clients in range</h3>
          <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-teal-100 px-1.5 text-[11px] font-bold text-teal-700">
            {clients.length}
          </span>
        </div>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {clients.length} in your prospect pool
          {nearest !== null ? ` · nearest ${nearest.toFixed(1)} km` : ""} · {activeCount} active
        </p>
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          Your existing pool and current filters — not a discovery total.
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
          message={nearbyEmptyMessage(
            // Never null here: this branch only renders when there are no rows.
            nearbyEmptyReason({
              visibleCount: clients.length,
              poolIsEmpty,
              priorityFilter,
              matchesIgnoringPriority,
            }) ?? "filters-excluded-all",
            priorityFilter,
          )}
        />
      )}
    </div>
  );
}
