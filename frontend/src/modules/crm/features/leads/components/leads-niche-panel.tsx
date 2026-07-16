"use client";

import { cn } from "@/modules/crm/lib/utils";
import type { NicheDistribution } from "@/modules/crm/features/leads/hooks/use-niche-distribution";

type Props = {
  distribution: NicheDistribution;
  // Niche id === industry value (Industry = Niche this phase).
  selectedNiches: string[];
  onToggleNiche: (id: string) => void;
};

/**
 * Presentation-only niche panel: selectable chips (dot + label + count), a proportional
 * distribution bar, and a "Showing X of Y leads" summary. All data (labels, colors, counts)
 * comes from props — this component contains no industry/niche mapping logic.
 */
export function LeadsNichePanel({ distribution, selectedNiches, onToggleNiche }: Props) {
  const { items, shown, total } = distribution;
  const segments = items.filter((item) => item.count > 0);

  return (
    <div className="flex flex-col gap-3">
      {/* Niche chips — the full industry catalog is always visible; the selected chip becomes
          active (filled with its own stronger color), inactive chips stay light. */}
      <div className="flex flex-wrap gap-2">
        {items.map((item) => {
          const selected = selectedNiches.includes(item.id);
          return (
            <button
              key={item.id}
              type="button"
              aria-pressed={selected}
              onClick={() => onToggleNiche(item.id)}
              className={cn(
                "inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                selected
                  ? "border-transparent text-white"
                  : "border-border bg-background text-foreground hover:bg-muted",
              )}
              style={selected ? { backgroundColor: item.color, borderColor: item.color } : undefined}
            >
              <span
                className="h-2 w-2 shrink-0 rounded-full"
                style={{ backgroundColor: selected ? "#ffffff" : item.color }}
              />
              <span className="font-medium">{item.label}</span>
              <span
                className={cn(
                  "rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums",
                  selected ? "bg-white/25 text-white" : "bg-muted text-muted-foreground",
                )}
              >
                {item.count}
              </span>
            </button>
          );
        })}
      </div>

      {/* Segmented distribution bar — each segment is an alternate filter control: clicking it
          runs the SAME onToggleNiche as its chip (single source of truth), so the chip highlights
          and the table/summary update identically. Widths proportional to count. */}
      {segments.length > 0 ? (
        <div className="flex h-2 w-full gap-0.5">
          {segments.map((item) => {
            const selected = selectedNiches.includes(item.id);
            return (
              <button
                key={item.id}
                type="button"
                aria-pressed={selected}
                aria-label={`Filter by ${item.label} (${item.count})`}
                title={`${item.label}: ${item.count}`}
                onClick={() => onToggleNiche(item.id)}
                className={cn(
                  "h-full min-w-0 cursor-pointer rounded-full p-0 transition-opacity hover:opacity-80",
                  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  selectedNiches.length > 0 && !selected && "opacity-40",
                )}
                style={{ flexGrow: item.count, backgroundColor: item.color }}
              />
            );
          })}
        </div>
      ) : (
        <div className="h-2 w-full rounded-full bg-muted" />
      )}

      {/* Summary */}
      <p className="text-sm text-muted-foreground">
        Showing <span className="font-semibold text-foreground">{shown}</span> of {total} leads
      </p>
    </div>
  );
}
