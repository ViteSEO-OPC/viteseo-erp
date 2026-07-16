"use client";

import { Search } from "lucide-react";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import type { NearbyFilters as Filters } from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";

function FilterSelect({
  value,
  onChange,
  allLabel,
  options,
}: {
  value: string;
  onChange: (v: string) => void;
  allLabel: string;
  options: string[];
}) {
  // Base-ui SelectValue renders the raw value, so the "all" option's value IS its readable label
  // (mapped back to "" = no filter). Matches the codebase's value-equals-label Select convention.
  return (
    <Select value={value || allLabel} onValueChange={(v) => onChange(v && v !== allLabel ? v : "")}>
      <SelectTrigger className="w-full">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={allLabel}>{allLabel}</SelectItem>
        {options.map((o) => (
          <SelectItem key={o} value={o}>{o}</SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function Label({ children }: { children: React.ReactNode }) {
  return (
    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
      {children}
    </span>
  );
}

type Props = {
  filters: Filters;
  patch: (next: Partial<Filters>) => void;
  options: { areas: string[]; niches: string[]; statuses: string[] };
};

export function NearbyFilters({ filters, patch, options }: Props) {
  return (
    <div className="flex flex-col gap-4">
      {/* Search */}
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
        <input
          value={filters.search}
          onChange={(e) => patch({ search: e.target.value })}
          placeholder="Search saved clients…"
          className="h-10 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm text-foreground placeholder:text-gray-400 focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30"
          aria-label="Search saved clients"
        />
      </div>

      {/* Distance */}
      <div>
        <div className="flex items-center justify-between">
          <Label>Distance</Label>
          <span className="text-sm font-bold text-teal-700">{filters.distanceKm} km</span>
        </div>
        <input
          type="range"
          min={1}
          max={10}
          value={filters.distanceKm}
          onChange={(e) => patch({ distanceKm: Number(e.target.value) })}
          className="mt-2 w-full accent-teal-500"
          aria-label="Distance in kilometres"
        />
        <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
          <span>1 km</span>
          <span>10 km</span>
        </div>
      </div>

      {/* Location */}
      <div className="flex flex-col gap-1.5">
        <Label>Location</Label>
        <FilterSelect value={filters.area} onChange={(v) => patch({ area: v })} allLabel="All locations" options={options.areas} />
      </div>

      {/* Niche + Status */}
      <div className="grid grid-cols-2 gap-3">
        <div className="flex flex-col gap-1.5">
          <Label>Business niche</Label>
          <FilterSelect value={filters.niche} onChange={(v) => patch({ niche: v })} allLabel="All niches" options={options.niches} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label>Lead status</Label>
          <FilterSelect value={filters.status} onChange={(v) => patch({ status: v })} allLabel="All statuses" options={options.statuses} />
        </div>
      </div>
    </div>
  );
}
