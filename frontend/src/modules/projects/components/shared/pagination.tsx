"use client";

import { ChevronLeftIcon, ChevronRightIcon } from "@/core/ui";

/**
 * The module's paginated-list footer, and the page size every paginated view
 * counts in.
 *
 * Extracted from `team/team-shared` during the components reorganisation. It had
 * grown up there because the members table was the first list to need paging,
 * but four other surfaces import it — the archives and approvals queues, the
 * projects list and the workload table — none of which have anything to do with
 * the team. Under folders that read as a cross-domain reach; here it is what it
 * always was: a shared widget.
 *
 * `page` is 0-indexed; the bubbles render 1-indexed.
 */

/** Rows per page for every client-paginated list in the module. */
export const PAGE_SIZE = 8;

/** Builds the page-number sequence with ellipses (e.g. 1 2 3 … 12). */
function pageItems(total: number, current: number): Array<number | "dots"> {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  const range: number[] = [];
  for (
    let i = Math.max(2, current - 1);
    i <= Math.min(total - 1, current + 1);
    i++
  ) {
    range.push(i);
  }
  const items: Array<number | "dots"> = [1];
  if (range[0] > 2) items.push("dots");
  items.push(...range);
  if (range[range.length - 1] < total - 1) items.push("dots");
  items.push(total);
  return items;
}

/** Pagination footer with range summary + numbered bubbles. `page` is 0-indexed. */
export function Pagination({
  page,
  pageCount,
  onPage,
  summary,
}: {
  page: number;
  pageCount: number;
  onPage: (page: number) => void;
  summary: string;
}) {
  return (
    <div className="mt-4 flex flex-col items-center gap-3 border-t border-line pt-4 sm:flex-row sm:justify-between">
      <p className="text-sm text-ink-soft">{summary}</p>
      <div className="flex items-center gap-2">
        <button
          type="button"
          disabled={page === 0}
          onClick={() => onPage(Math.max(0, page - 1))}
          className="flex h-9 w-9 items-center justify-center rounded-full border border-line transition-colors hover:bg-black/5 disabled:opacity-50"
          aria-label="Previous page"
        >
          <ChevronLeftIcon className="h-4 w-4" />
        </button>
        <div className="flex items-center gap-1">
          {pageItems(pageCount, page + 1).map((item, i) =>
            item === "dots" ? (
              <span
                key={`dots-${i}`}
                className="flex h-8 w-8 items-center justify-center text-sm text-ink-soft"
              >
                …
              </span>
            ) : (
              <button
                key={item}
                type="button"
                onClick={() => onPage(item - 1)}
                className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold transition-colors ${
                  page + 1 === item
                    ? "bg-ink text-white"
                    : "bg-white text-ink-soft hover:bg-black/5"
                }`}
              >
                {item}
              </button>
            ),
          )}
        </div>
        <button
          type="button"
          disabled={page >= pageCount - 1}
          onClick={() => onPage(Math.min(pageCount - 1, page + 1))}
          className="flex h-9 w-9 items-center justify-center rounded-full border border-line transition-colors hover:bg-black/5 disabled:opacity-50"
          aria-label="Next page"
        >
          <ChevronRightIcon className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}
