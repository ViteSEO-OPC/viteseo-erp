import { useLayoutEffect, useRef, useState, type RefObject } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
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
  /** The panel that scrolls this list (the page owns it). The virtualizer windows against it. */
  scrollElementRef: RefObject<HTMLDivElement | null>;
};

/** A compact card is ~179px tall; real heights are measured once a card mounts. */
const ESTIMATED_CARD_PX = 179;
/** Matches the `gap-3` the cards were laid out with before virtualization. */
const CARD_GAP_PX = 12;
/** Cards kept mounted above and below the visible ones, so a fast scroll doesn't show blank space. */
const OVERSCAN = 6;

export function ClientsInRangeList({ clients, selectedId, onSelect, onPlanVisit, onDirections, onQualify, qualifyingId, poolIsEmpty, priorityFilter, matchesIgnoringPriority, scrollElementRef }: Props) {
  const nearest = clients[0]?.distanceKm ?? null;
  const activeCount = clients.filter((c) => c.active).length;

  // VIRTUALIZED: only the cards in (or near) view are mounted. Rendering every card of a pool of
  // ~9,000 put ~275k elements in the DOM and blocked the page for 6–10s on every filter change.
  // `clients` is still the complete, filtered, sorted result — counts, the empty state and the map
  // all read it whole; only what is MOUNTED here is windowed.
  const cardsRef = useRef<HTMLDivElement>(null);
  // The count header sits above the cards inside the same scroll panel; the virtualizer needs to
  // know where the cards start within it. Re-measured whenever the list resizes (the header wraps
  // differently at other widths).
  const rootRef = useRef<HTMLDivElement>(null);
  const [scrollMargin, setScrollMargin] = useState(0);
  const hasCards = clients.length > 0;
  useLayoutEffect(() => {
    const panel = scrollElementRef.current;
    const root = rootRef.current;
    const cards = cardsRef.current;
    if (!panel || !root || !cards) return;
    const measure = () =>
      setScrollMargin(cards.getBoundingClientRect().top - panel.getBoundingClientRect().top + panel.scrollTop);
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(root);
    return () => observer.disconnect();
  }, [scrollElementRef, hasCards]);

  const virtualizer = useVirtualizer({
    count: clients.length,
    getScrollElement: () => scrollElementRef.current,
    estimateSize: () => ESTIMATED_CARD_PX,
    gap: CARD_GAP_PX,
    overscan: OVERSCAN,
    scrollMargin,
    // Keyed by lead, so a measured height follows its card across filter changes.
    getItemKey: (index) => clients[index].id,
  });

  // Which cards have their Website Intel panel open. Held here rather than in the card because a
  // card that scrolls out of view is unmounted, and must come back the way the user left it.
  const [intelOpenIds, setIntelOpenIds] = useState<ReadonlySet<string>>(() => new Set());
  const setIntelOpen = (id: string, open: boolean) =>
    setIntelOpenIds((prev) => {
      const next = new Set(prev);
      if (open) next.add(id);
      else next.delete(id);
      return next;
    });

  return (
    <div ref={rootRef} className="flex flex-col gap-3">
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
        <div ref={cardsRef} className="relative w-full" style={{ height: virtualizer.getTotalSize() }}>
          {virtualizer.getVirtualItems().map((item) => {
            const c = clients[item.index];
            return (
              <div
                key={item.key}
                data-index={item.index}
                ref={virtualizer.measureElement}
                className="absolute left-0 top-0 w-full"
                style={{ transform: `translateY(${item.start - scrollMargin}px)` }}
              >
                <ClientRangeCard
                  client={c}
                  selected={selectedId === c.id}
                  onSelect={() => onSelect(c.id)}
                  onPlanVisit={() => onPlanVisit(c)}
                  onDirections={() => onDirections(c)}
                  onQualify={() => onQualify(c)}
                  qualifying={qualifyingId === c.id}
                  intelOpen={intelOpenIds.has(c.id)}
                  onIntelOpenChange={(open) => setIntelOpen(c.id, open)}
                />
              </div>
            );
          })}
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
