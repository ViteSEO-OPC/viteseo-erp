"use client";

import type { NicheDistributionItem } from "@/modules/crm/features/leads/hooks/use-niche-distribution";

type Props = {
  items: NicheDistributionItem[];
  /** Largest count in the set — bars scale to this so the top niche fills the track. */
  max: number;
};

/**
 * Leads by niche — presentation only. Each value is a real per-industry total from the API's SQL
 * aggregate (see useLeadsNiche); this component just draws each industry's bar scaled to the largest.
 * No business computation, no taxonomy.
 */
export function NicheBars({ items, max }: Props) {
  return (
    <div className="flex flex-col gap-2.5" role="list" aria-label="Leads by niche">
      {items.map((item) => {
        const pct = max > 0 ? Math.round((item.count / max) * 100) : 0;
        return (
          <div
            key={item.id}
            className="flex items-center gap-3"
            aria-label={`${item.label}: ${item.count} leads`}
          >
            <span
              className="w-24 shrink-0 truncate text-xs text-secondary-foreground sm:w-32"
              aria-hidden
            >
              {item.label}
            </span>
            <div className="h-3 flex-1 rounded-full bg-secondary" aria-hidden>
              <div
                className="h-3 rounded-full transition-all"
                style={{ width: `${pct}%`, backgroundColor: item.color }}
              />
            </div>
            <span
              className="w-8 shrink-0 text-right text-sm font-semibold tabular-nums text-foreground"
              aria-hidden
            >
              {item.count}
            </span>
          </div>
        );
      })}
    </div>
  );
}
