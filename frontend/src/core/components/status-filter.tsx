"use client";

import { cn } from "@/lib/utils";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/core/ui/select";

export interface StatusFilterOption {
  /** The row status this matches; use "all" for the no-filter option. */
  value: string;
  label: string;
}

/**
 * A small segmented control for filtering a table by status. Generic on purpose —
 * each page passes its own options (statuses + labels) and owns the filtering, so
 * DataTable stays domain-agnostic. Drop it into DataTable's `toolbar` slot.
 *
 * Below `sm` it is a dropdown instead. The segmented control is one unbreakable
 * row — it cannot wrap or shrink — so its width is however many options there
 * are, and several of these lists are derived from the data (see DataTable's auto
 * status filter), meaning the count is not knowable up front. On a phone that ran
 * past the card and off the screen. A select costs the same width at four options
 * or twelve.
 *
 * Both are rendered and toggled with CSS rather than measuring the viewport in JS:
 * no hydration mismatch, no resize listener, and `display: none` keeps the hidden
 * one out of the accessibility tree, so it does not read as a duplicate control.
 *
 * The dropdown is core's Radix `Select`, trimmed to match the `SimpleSelect` the
 * rest of the app uses (`h-8`, `rounded-lg`, no shadow) so it reads as the same
 * control. It is not `SimpleSelect` itself only because that lives in
 * `modules/hr/` and this is core — core must not depend on a module, or the
 * component stops being usable by the other pods it is filed under.
 */
export function StatusFilter({
  options,
  value,
  onChange,
  label = "Filter",
}: {
  options: StatusFilterOption[];
  value: string;
  onChange: (value: string) => void;
  /** Accessible name for the small-screen dropdown, e.g. "Category". */
  label?: string;
}) {
  return (
    <>
      {/* Phone: full width on its own line, under the search box. */}
      <div className="w-full sm:hidden">
        <Select value={value} onValueChange={onChange}>
          <SelectTrigger
            size="sm"
            aria-label={label}
            className="w-full rounded-lg px-2.5 shadow-none"
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {options.map((o) => (
              <SelectItem key={o.value} value={o.value}>
                {o.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* sm and up: the segmented control, unchanged. */}
      <div className="hidden items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-0.5 sm:inline-flex">
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
    </>
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
