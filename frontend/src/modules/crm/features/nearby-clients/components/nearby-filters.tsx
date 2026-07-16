"use client";

import { Search, LocateFixed, X } from "lucide-react";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { cn } from "@/modules/crm/lib/utils";
import {
  RADIUS_OPTIONS,
  type NearbyFilters as Filters,
  type NearbyOrigin,
  type GeolocationStatus,
} from "@/modules/crm/features/nearby-clients/hooks/use-nearby-clients";

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
  options: { locations: string[]; niches: string[]; statuses: string[]; priorities: string[] };
  origin: NearbyOrigin | null;
  selectLocation: (area: string) => void;
  useMyLocation: () => void;
  clearOrigin: () => void;
  geoStatus: GeolocationStatus;
};

export function NearbyFilters({
  filters,
  patch,
  options,
  origin,
  selectLocation,
  useMyLocation,
  clearOrigin,
  geoStatus,
}: Props) {
  // The Location picker reflects the origin only when the origin came FROM a location (not geolocation).
  const locationValue = origin?.kind === "location" ? origin.label : "";

  return (
    <div className="flex flex-col gap-4">
      {/* Search */}
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
        <input
          value={filters.search}
          onChange={(e) => patch({ search: e.target.value })}
          placeholder="Search discovered clients…"
          className="h-10 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30"
          aria-label="Search discovered clients"
        />
      </div>

      {/* Search origin — where the radius is measured from. */}
      <div className="flex flex-col gap-1.5">
        <Label>Search origin</Label>
        <FilterSelect
          value={locationValue}
          onChange={selectLocation}
          allLabel="All locations"
          options={options.locations}
        />
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={useMyLocation}
            className={cn(
              "inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              origin?.kind === "geolocation"
                ? "border-teal-400 bg-teal-50 text-teal-700 dark:bg-teal-950/40"
                : "border-border bg-background text-foreground hover:bg-muted",
            )}
            aria-pressed={origin?.kind === "geolocation"}
          >
            <LocateFixed className="h-3.5 w-3.5" aria-hidden />
            {geoStatus === "locating" ? "Locating…" : "Use my location"}
          </button>
          {origin && (
            <button
              type="button"
              onClick={clearOrigin}
              className="inline-flex items-center gap-1 rounded-lg border border-border bg-background px-2.5 py-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:bg-muted"
              aria-label="Clear search origin"
            >
              <X className="h-3.5 w-3.5" aria-hidden />
              Clear
            </button>
          )}
        </div>
        <p className="text-[11px] text-muted-foreground">
          {origin
            ? `Showing businesses within ${filters.radiusKm} km of ${origin.label}.`
            : "Showing all discovered businesses. Pick a location or use your position to search by radius."}
        </p>
        {geoStatus === "denied" && (
          <p className="text-[11px] text-amber-600">Location permission denied — pick a location instead.</p>
        )}
        {geoStatus === "unavailable" && (
          <p className="text-[11px] text-amber-600">Geolocation isn’t available in this browser.</p>
        )}
      </div>

      {/* Radius — measured from the selected origin. Inert until an origin is chosen. */}
      <div>
        <div className="flex items-center justify-between">
          <Label>Radius</Label>
          <span className={cn("text-sm font-bold", origin ? "text-teal-700" : "text-muted-foreground")}>
            {filters.radiusKm} km
          </span>
        </div>
        <div className="mt-2 grid grid-cols-4 gap-2">
          {RADIUS_OPTIONS.map((km) => (
            <button
              key={km}
              type="button"
              disabled={!origin}
              onClick={() => patch({ radiusKm: km })}
              className={cn(
                "rounded-lg border px-2 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                filters.radiusKm === km && origin
                  ? "border-teal-400 bg-teal-50 text-teal-700 dark:bg-teal-950/40"
                  : "border-border bg-background text-foreground hover:bg-muted",
                !origin && "cursor-not-allowed opacity-50 hover:bg-background",
              )}
            >
              {km} km
            </button>
          ))}
        </div>
      </div>

      {/* Prospecting priority — derived from Website Intelligence (No website → High, etc.). */}
      <div className="flex flex-col gap-1.5">
        <Label>Prospecting priority</Label>
        <FilterSelect
          value={filters.priority}
          onChange={(v) => patch({ priority: v })}
          allLabel="All priorities"
          options={options.priorities}
        />
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
