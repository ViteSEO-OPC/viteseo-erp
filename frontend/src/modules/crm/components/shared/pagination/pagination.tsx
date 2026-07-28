"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

type PaginationProps = {
  /** 0-based index of the current page (matches the CRM list hooks). */
  page: number;
  totalPages: number;
  total: number;
  onPageChange: (page: number) => void;
  /** Page size used only for the "Showing X–Y of Z" summary. */
  perPage?: number;
  /**
   * Optional noun appended to the summary — e.g. `unit="leads"` renders
   * "Showing 1–7 of 2,847 leads". Omitted by default so existing callers are unchanged.
   */
  unit?: string;
  /**
   * Page controls (arrows + numbers). Set false to render the "Showing X–Y of Z" summary on its own
   * — for a list that fits on one page but still doesn't show every row at once (e.g. a viewport
   * that scrolls internally). Defaults to true, so every existing caller is unchanged.
   */
  showControls?: boolean;
  className?: string;
};

/**
 * Standardized CRM pagination — used by every paginated table (Leads, Archive, and future Nearby
 * Clients / Users / …) so behavior is consistent app-wide.
 *
 * Renders a compact, ellipsized window of page numbers (never every page), centered, with
 * Previous/Next. Backend pagination is untouched — this is a rendering concern only.
 *
 *   page 1     →  ‹ 1 2 3 4 5 … 30 ›
 *   middle     →  ‹ 1 … 8 9 10 11 12 … 30 ›
 *   last page  →  ‹ 1 … 26 27 28 29 30 ›
 */

const WINDOW = 5; // page numbers shown around (and clamped to) the current page

/**
 * Compact page list as 1-based numbers with "ellipsis" gap markers. First and last pages are always
 * shown; a gap of exactly one page renders that page instead of an ellipsis. Width stays bounded no
 * matter how large `totalPages` is.
 */
function getPageItems(current: number, total: number): Array<number | "ellipsis"> {
  if (total <= WINDOW + 2) {
    return Array.from({ length: total }, (_, i) => i + 1);
  }

  const half = Math.floor(WINDOW / 2);
  let start = current - half;
  let end = current + half;
  if (start < 1) {
    start = 1;
    end = WINDOW;
  }
  if (end > total) {
    end = total;
    start = total - WINDOW + 1;
  }

  const items: Array<number | "ellipsis"> = [];
  if (start > 1) {
    items.push(1);
    if (start > 2) items.push("ellipsis");
  }
  for (let p = start; p <= end; p += 1) items.push(p);
  if (end < total) {
    if (end < total - 1) items.push("ellipsis");
    items.push(total);
  }
  return items;
}

export function Pagination({
  page,
  totalPages,
  total,
  onPageChange,
  perPage = 10,
  unit,
  showControls = true,
  className,
}: PaginationProps) {
  const from = total === 0 ? 0 : page * perPage + 1;
  const to = Math.min((page + 1) * perPage, total);
  const items = getPageItems(page + 1, totalPages);

  const arrowClass = cn(
    "flex h-8 w-8 items-center justify-center rounded-md border border-border text-sm transition-colors",
    "text-secondary-foreground hover:bg-muted hover:text-foreground",
    "disabled:pointer-events-none disabled:opacity-40",
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
  );

  return (
    <nav
      aria-label="Pagination"
      className={cn(
        "flex flex-col items-center gap-3 px-1 py-3 sm:grid sm:grid-cols-[1fr_auto_1fr]",
        className,
      )}
    >
      <p className="text-sm text-muted-foreground sm:justify-self-start">
        Showing <span className="font-medium text-foreground">{from}</span>–
        <span className="font-medium text-foreground">{to}</span> of{" "}
        <span className="font-medium text-foreground">{total.toLocaleString()}</span>
        {unit ? ` ${unit}` : null}
      </p>

      {showControls ? (
      <div className="flex items-center gap-1 sm:col-start-2 sm:justify-self-center">
        <button
          type="button"
          onClick={() => onPageChange(page - 1)}
          disabled={page === 0}
          className={arrowClass}
          aria-label="Previous page"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>

        {items.map((item, index) =>
          item === "ellipsis" ? (
            <span
              key={`ellipsis-${index}`}
              className="flex h-8 min-w-8 items-center justify-center px-1 text-sm text-muted-foreground"
              aria-hidden
            >
              …
            </span>
          ) : (
            <button
              key={item}
              type="button"
              onClick={() => onPageChange(item - 1)}
              className={cn(
                "flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm transition-colors",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                item - 1 === page
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-border text-secondary-foreground hover:bg-muted hover:text-foreground",
              )}
              aria-label={`Page ${item}`}
              aria-current={item - 1 === page ? "page" : undefined}
            >
              {item}
            </button>
          ),
        )}

        <button
          type="button"
          onClick={() => onPageChange(page + 1)}
          disabled={page >= totalPages - 1}
          className={arrowClass}
          aria-label="Next page"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>
      ) : null}
    </nav>
  );
}
