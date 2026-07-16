"use client";

import { useEffect, useRef, useState } from "react";
import { Filter, ChevronDown } from "lucide-react";
import { Button, buttonVariants } from "@/modules/crm/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { cn } from "@/modules/crm/lib/utils";
import { LEAD_SORT_OPTIONS, DEFAULT_LEAD_SORT } from "@/modules/crm/features/leads/hooks/use-leads-data";
import { LeadsNichePanel } from "@/modules/crm/features/leads/components/leads-niche-panel";
import type { NicheDistribution } from "@/modules/crm/features/leads/hooks/use-niche-distribution";

type Props = {
  // Filter by niche (wired): distribution + selection from the single useLeads instance.
  // Niche id === industry value (Industry = Niche this phase).
  nicheDistribution: NicheDistribution;
  selectedNiches: string[];
  onToggleNiche: (id: string) => void;
  // Sort (wired).
  sort?: string;
  onSortChange?: (value: string) => void;
  // Clear (wired): reset all filters/sort to the bare list.
  onClear?: () => void;
  clearDisabled?: boolean;
};

/**
 * Leads filter/sort bar — a single bordered container. The header row (Filter-by-niche trigger +
 * Sort + Clear) is always visible; the niche panel (chips + distribution bar + summary) expands and
 * collapses beneath it like an accordion, with a smooth height + opacity transition, and closes on
 * outside click. Presentation only — data/filter/sort/export logic is unchanged. State/handlers
 * come from the single `useLeads` instance in `leads-page.tsx`.
 */
export function LeadsFilterBar({
  nicheDistribution,
  selectedNiches,
  onToggleNiche,
  sort = DEFAULT_LEAD_SORT,
  onSortChange,
  onClear,
  clearDisabled,
}: Props) {
  const [nicheOpen, setNicheOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Collapse on outside click. Ignore clicks inside the container and inside any portaled
  // dropdown menu (e.g. the Sort menu), which render outside the container in the DOM.
  useEffect(() => {
    if (!nicheOpen) return;

    function handlePointerDown(event: MouseEvent) {
      const target = event.target as HTMLElement | null;
      if (!target) return;
      if (containerRef.current?.contains(target)) return;
      if (target.closest('[data-slot^="dropdown-menu"]')) return;
      setNicheOpen(false);
    }

    document.addEventListener("mousedown", handlePointerDown);
    return () => document.removeEventListener("mousedown", handlePointerDown);
  }, [nicheOpen]);

  const sortLabel =
    LEAD_SORT_OPTIONS.find((o) => o.value === sort)?.label ?? LEAD_SORT_OPTIONS[0].label;
  // Single-select: show the active industry name in the trigger, or "All" when none.
  const nicheState = selectedNiches[0] ?? "All";

  return (
    <div ref={containerRef} className="rounded-lg border border-border bg-card">
      {/* Header row — always visible */}
      <div className="flex flex-wrap items-center justify-between gap-3 p-3">
        {/* Filter-by-niche accordion trigger */}
        <button
          type="button"
          aria-expanded={nicheOpen}
          onClick={() => setNicheOpen((open) => !open)}
          className="inline-flex items-center gap-2 rounded-md px-2 py-1 text-sm font-semibold text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <Filter className="h-4 w-4 text-primary-accent" />
          Filter by niche
          <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
            {nicheState}
          </span>
          <ChevronDown
            className={cn(
              "h-4 w-4 text-muted-foreground transition-transform duration-300",
              nicheOpen && "rotate-180",
            )}
          />
        </button>

        {/* Sort dropdown + Clear — always in the header row */}
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">Sort</span>
          <DropdownMenu>
            <DropdownMenuTrigger className={cn(buttonVariants({ variant: "outline" }), "gap-1.5")}>
              {sortLabel}
              <ChevronDown className="h-4 w-4 text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuRadioGroup value={sort} onValueChange={onSortChange}>
                {LEAD_SORT_OPTIONS.map((option) => (
                  <DropdownMenuRadioItem key={option.value} value={option.value}>
                    {option.label}
                  </DropdownMenuRadioItem>
                ))}
              </DropdownMenuRadioGroup>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button variant="outline" onClick={onClear} disabled={clearDisabled}>
            Clear
          </Button>
        </div>
      </div>

      {/* Expandable niche panel — smooth height (grid-rows) + opacity accordion, same container */}
      <div
        className={cn(
          "grid transition-all duration-300 ease-in-out",
          nicheOpen ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0",
        )}
      >
        <div className="min-h-0 overflow-hidden">
          <div className="border-t border-border p-4">
            <LeadsNichePanel
              distribution={nicheDistribution}
              selectedNiches={selectedNiches}
              onToggleNiche={onToggleNiche}
            />
          </div>
        </div>
      </div>
    </div>
  );
}
