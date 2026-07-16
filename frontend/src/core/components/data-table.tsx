import { useState, useMemo } from "react";
import { ChevronLeft, ChevronRight, Search } from "lucide-react";

import { cn } from "@/lib/utils";
import { StatusFilter, type StatusFilterOption } from "@/core/components/status-filter";

export interface Column<T> {
  /** Column header (rendered uppercase, gray). */
  header: string;
  /** Cell renderer for a row. */
  cell: (row: T) => React.ReactNode;
  align?: "left" | "right";
  /** Extra classes on the cell (e.g. width). */
  className?: string;
}

const DEFAULT_PAGE_SIZES = [10, 25, 50, 100];

/** "in_progress" → "In Progress". Used for auto-generated status-filter labels. */
function humanize(s: string): string {
  return s.replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

/** A row's `status`, if it has a string one — the signal that auto-filtering applies. */
function rowStatus(row: unknown): string | undefined {
  if (row && typeof row === "object") {
    const s = (row as { status?: unknown }).status;
    if (typeof s === "string") return s;
  }
  return undefined;
}

/**
 * Default searchable text when a caller doesn't supply `getSearchText`: every
 * top-level primitive value on the row plus one level into nested objects (so
 * e.g. `employee.name`, `leave_type.name` are searchable). Callers with a privacy
 * rule (e.g. anonymous concerns) MUST pass an explicit getSearchText instead.
 */
function autoSearchText(row: unknown): string {
  if (row == null || typeof row !== "object") return String(row ?? "");
  const parts: string[] = [];
  for (const val of Object.values(row as Record<string, unknown>)) {
    if (val == null) continue;
    const t = typeof val;
    if (t === "string" || t === "number" || t === "boolean") {
      parts.push(String(val));
    } else if (t === "object") {
      for (const nested of Object.values(val as Record<string, unknown>)) {
        if (nested == null) continue;
        const nt = typeof nested;
        if (nt === "string" || nt === "number") parts.push(String(nested));
      }
    }
  }
  return parts.join(" ");
}

/**
 * Styled list table per the Style Guide: uppercase gray column headers (13px),
 * rows with a bold title + gray subtitle pattern (see PrimaryCell), right-aligned
 * action columns, wrapped in a white 21px card. Supports client-side pagination
 * via `pageSize` (>0 enables it; 0 or undefined = show all rows).
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  empty = "No records yet.",
  className,
  onRowClick,
  rowClassName,
  pageSize = 10,
  pageSizeOptions = DEFAULT_PAGE_SIZES,
  title,
  titleRight,
  needsAction,
  searchable = true,
  getSearchText,
  searchPlaceholder = "Search…",
  filterable = true,
  toolbar,
}: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T, index: number) => string | number;
  empty?: React.ReactNode;
  className?: string;
  /** When set, rows become clickable. */
  onRowClick?: (row: T) => void;
  /** Optional per-row className (e.g. to highlight a selected row). */
  rowClassName?: (row: T) => string | undefined;
  /** Rows per page. 0 = no pagination (show all). Default 10. */
  pageSize?: number;
  /** Allowed rows-per-page values for the selector. */
  pageSizeOptions?: number[];
  /** Optional card title, rendered as a header strip inside the table card. */
  title?: React.ReactNode;
  /** Optional right-aligned content in the title strip (e.g. a period label). */
  titleRight?: React.ReactNode;
  /**
   * When provided, rows for which this returns true are stably floated to the
   * top — so items that still need HR action (e.g. pending approvals) show
   * before already-decided ones, without disturbing the order within each group.
   */
  needsAction?: (row: T) => boolean;
  /** Search box on by default. Set false to hide it for this table. */
  searchable?: boolean;
  /**
   * Custom searchable text per row. Defaults to every visible field on the row
   * (see autoSearchText). Pass this when a field must NOT be searchable — e.g.
   * never expose an anonymous submitter's identity.
   */
  getSearchText?: (row: T) => string;
  /** Placeholder for the search box. */
  searchPlaceholder?: string;
  /**
   * Status filter on by default when rows have a string `status` field (options
   * derived from the data). Set false to hide it, or pass an explicit `toolbar`
   * to supply your own (curated labels, etc.).
   */
  filterable?: boolean;
  /** Controls rendered next to the search box; overrides the auto status filter. */
  toolbar?: React.ReactNode;
}) {
  const [page, setPage] = useState(1);
  const [size, setSize] = useState(pageSize);
  const [query, setQuery] = useState("");
  const [statusValue, setStatusValue] = useState("all");

  // Auto status filter: only when enabled, no explicit toolbar, and rows carry a
  // string `status`. Options are the distinct statuses in first-seen order.
  const autoStatus = filterable && !toolbar;
  const autoStatusOptions = useMemo<StatusFilterOption[]>(() => {
    if (!autoStatus) return [];
    const seen = new Set<string>();
    const opts: StatusFilterOption[] = [{ value: "all", label: "All" }];
    for (const row of rows) {
      const s = rowStatus(row);
      if (s && !seen.has(s)) {
        seen.add(s);
        opts.push({ value: s, label: humanize(s) });
      }
    }
    return opts.length > 1 ? opts : [];
  }, [autoStatus, rows]);
  const showStatusFilter = autoStatusOptions.length > 1;

  const statusFilteredRows = useMemo(() => {
    if (!showStatusFilter || statusValue === "all") return rows;
    return rows.filter((row) => rowStatus(row) === statusValue);
  }, [rows, showStatusFilter, statusValue]);

  // Text search: explicit getSearchText, else every visible field (autoSearchText).
  const searchText = useMemo(
    () => (searchable ? getSearchText ?? ((row: T) => autoSearchText(row)) : undefined),
    [searchable, getSearchText],
  );
  const searchedRows = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!searchText || !q) return statusFilteredRows;
    return statusFilteredRows.filter((row) => searchText(row).toLowerCase().includes(q));
  }, [statusFilteredRows, searchText, query]);

  // Float rows that still need action to the top (stable partition).
  const orderedRows = useMemo(() => {
    if (!needsAction) return searchedRows;
    const need: T[] = [];
    const rest: T[] = [];
    for (const row of searchedRows) (needsAction(row) ? need : rest).push(row);
    return need.length && rest.length ? [...need, ...rest] : searchedRows;
  }, [searchedRows, needsAction]);

  // Reset to page 1 whenever the row set or page size changes.
  const totalRows = orderedRows.length;
  const usePaging = size > 0 && totalRows > size;

  const { pageRows, totalPages } = useMemo(() => {
    if (!usePaging) return { pageRows: orderedRows, totalPages: 1 };
    const tp = Math.max(1, Math.ceil(totalRows / size));
    const start = (Math.min(page, tp) - 1) * size;
    return { pageRows: orderedRows.slice(start, start + size), totalPages: tp };
  }, [orderedRows, usePaging, page, size, totalRows]);

  // Clamp page if rows shrink below current page.
  if (usePaging && page > totalPages && totalPages > 0) {
    setPage(totalPages);
  }

  return (
    <div
      className={cn(
        "overflow-hidden rounded-card border border-border bg-card",
        className,
      )}
    >
      {(title || titleRight) && (
        <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
          {title ? (
            <h2 className="text-h3 font-bold text-foreground">{title}</h2>
          ) : (
            <span />
          )}
          {titleRight}
        </div>
      )}

      {(searchText || toolbar || showStatusFilter) && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
          {searchText ? (
            <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <input
                type="search"
                value={query}
                onChange={(e) => { setQuery(e.target.value); setPage(1); }}
                placeholder={searchPlaceholder}
                className="h-8 w-full rounded-lg border border-input bg-transparent pl-8 pr-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
              />
            </div>
          ) : (
            <span />
          )}
          {toolbar ? (
            <div className="flex flex-wrap items-center gap-2">{toolbar}</div>
          ) : showStatusFilter ? (
            <StatusFilter
              options={autoStatusOptions}
              value={statusValue}
              onChange={(v) => { setStatusValue(v); setPage(1); }}
            />
          ) : null}
        </div>
      )}

      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-border">
              {columns.map((col, i) => (
                <th
                  key={i}
                  className={cn(
                    "px-4 py-3 text-label font-bold uppercase tracking-wide text-muted-foreground",
                    col.align === "right" && "text-right",
                    col.className,
                  )}
                >
                  {col.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {pageRows.length === 0 ? (
              <tr>
                <td
                  colSpan={columns.length}
                  className="px-4 py-10 text-center text-sm text-muted-foreground"
                >
                  {query.trim() || statusValue !== "all" ? "No matches." : empty}
                </td>
              </tr>
            ) : (
              pageRows.map((row, i) => (
                <tr
                  key={rowKey(row, i)}
                  onClick={onRowClick ? () => onRowClick(row) : undefined}
                  className={cn(
                    "border-b border-border last:border-0 transition-colors hover:bg-muted/40",
                    onRowClick && "cursor-pointer",
                    rowClassName?.(row),
                  )}
                >
                  {columns.map((col, j) => (
                    <td
                      key={j}
                      className={cn(
                        "px-4 py-3 align-middle text-sm text-foreground",
                        col.align === "right" && "text-right",
                        col.className,
                      )}
                    >
                      {col.cell(row)}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {usePaging && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
          <div className="flex items-center gap-2 text-caption text-muted-foreground">
            <span>Rows per page</span>
            <select
              value={size}
              onChange={(e) => { setSize(Number(e.target.value)); setPage(1); }}
              className="h-7 rounded-md border border-input bg-card px-1.5 text-caption text-foreground outline-none focus-visible:border-ring"
            >
              {pageSizeOptions.map((n) => (
                <option key={n} value={n}>{n}</option>
              ))}
            </select>
            <span className="hidden sm:inline">
              · Showing {(page - 1) * size + 1}–{Math.min(page * size, totalRows)} of {totalRows}
            </span>
          </div>

          <div className="flex items-center gap-1">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
              aria-label="Previous page"
            >
              <ChevronLeft className="size-4" />
            </button>
            <span className="px-2 text-caption text-foreground">
              Page {page} of {totalPages}
            </span>
            <button
              type="button"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              className="inline-flex size-7 items-center justify-center rounded-md border border-input bg-card text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
              aria-label="Next page"
            >
              <ChevronRight className="size-4" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

/** Bold title over a gray subtitle — the common first-column cell in the design. */
export function PrimaryCell({
  title,
  subtitle,
}: {
  title: React.ReactNode;
  subtitle?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col">
      <span className="font-semibold text-foreground">{title}</span>
      {subtitle ? (
        <span className="text-caption text-muted-foreground">{subtitle}</span>
      ) : null}
    </div>
  );
}
