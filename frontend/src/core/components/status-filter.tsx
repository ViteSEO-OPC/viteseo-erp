"use client";

import { cn } from "@/lib/utils";

export interface StatusFilterOption {
  /** The row status this matches; use "all" for the no-filter option. */
  value: string;
  label: string;
}

/**
 * A small segmented control for filtering a table by status. Generic on purpose —
 * each page passes its own options (statuses + labels) and owns the filtering, so
 * DataTable stays domain-agnostic. Drop it into DataTable's `toolbar` slot.
 */
export function StatusFilter({
  options,
  value,
  onChange,
}: {
  options: StatusFilterOption[];
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="inline-flex items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-0.5">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          onClick={() => onChange(o.value)}
          className={cn(
            "rounded-md px-2.5 py-1 text-caption font-medium transition-colors",
            value === o.value
              ? "bg-brand text-brand-foreground shadow-sm"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Filter rows by a chosen status. `all` (or an empty value) keeps everything.
 * Reads the status off each row via `getStatus` (default: row.status).
 */
export function filterByStatus<T extends { status?: string }>(
  rows: T[],
  value: string,
  getStatus: (row: T) => string = (r) => r.status ?? "",
): T[] {
  if (!value || value === "all") return rows;
  return rows.filter((r) => getStatus(r) === value);
}
