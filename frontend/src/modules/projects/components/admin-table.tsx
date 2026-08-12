"use client";

import type { ReactNode } from "react";
import { Card } from "@/core/ui";
import type { Paginated } from "@/core/types";
import { Pagination } from "@/modules/projects/components/team-shared";

/**
 * The management-surface table shell: striped rows, a trailing action column and
 * a paginated footer. Shared by the Archives and Approvals queues — each differs
 * only in its columns, its data hook and what it puts in the action cell.
 */

export type AdminColumn<T> = {
  key: string;
  label: string;
  /** Width / alignment classes applied to both the header and body cells. */
  className?: string;
  render: (row: T) => ReactNode;
};

/**
 * Row-selection wiring for tables that support a bulk action. Selection lives in
 * the parent (it has to survive re-renders of this table and drive the bulk bar),
 * so this is purely presentational.
 */
export type AdminSelection<T> = {
  /** Selected row ids, as a Set for O(1) lookup while rendering. */
  selected: Set<string>;
  onToggle: (id: string) => void;
  /** Select or clear every selectable row on the current page. */
  onToggleAll: (select: boolean) => void;
  /** Rows that may not be selected (e.g. nothing left to do on them). */
  isSelectable?: (row: T) => boolean;
  /** Accessible label prefix for the per-row checkbox, e.g. "task". */
  itemLabel: string;
};

/** Query state passed from a page down into whichever tab is mounted. */
export type AdminQuery = {
  params: Record<string, unknown>;
  search: string;
  page: number;
  onPage: (page: number) => void;
};

export function AdminTable<T extends { id: string }>({
  label,
  columns,
  query,
  result,
  actions,
  selection,
  actionLabel = "Action",
  actionColumnClassName = "w-[120px]",
  emptyMessage,
  searchEmptyMessage,
  summaryVerb,
}: {
  /** Plural noun used in the error / empty states and the pagination summary. */
  label: string;
  columns: AdminColumn<T>[];
  query: AdminQuery;
  result: { data?: Paginated<T>; isLoading: boolean; isError: boolean };
  /** Contents of the trailing action cell for a row. */
  actions: (row: T) => ReactNode;
  /** Omit for a table without bulk actions (the Archives queue). */
  selection?: AdminSelection<T>;
  actionLabel?: string;
  actionColumnClassName?: string;
  emptyMessage: string;
  searchEmptyMessage: string;
  /** Adjective in "Showing N of M <verb> <label>" (e.g. "archived"). */
  summaryVerb: string;
}) {
  const { data, isLoading, isError } = result;
  const rows = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const pageCount = Math.max(1, data?.meta.last_page ?? 1);

  // The header checkbox covers this page only — it can't speak for rows the
  // server hasn't sent, and pretending otherwise would be a lie about scope.
  const selectableRows = selection
    ? rows.filter((r) => selection.isSelectable?.(r) ?? true)
    : [];
  const selectedOnPage = selectableRows.filter((r) => selection!.selected.has(r.id));
  const allOnPageSelected =
    selectableRows.length > 0 && selectedOnPage.length === selectableRows.length;
  const someOnPageSelected =
    selectedOnPage.length > 0 && !allOnPageSelected;

  if (isError) {
    return (
      <Card className="p-8 text-center">
        <p className="font-semibold text-danger">Couldn&apos;t load {label}.</p>
        <p className="mt-1 text-sm text-ink-soft">
          Make sure the API is running and you&apos;re signed in.
        </p>
      </Card>
    );
  }

  return (
    <>
      <Card className="overflow-hidden rounded-2xl p-0">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] border-collapse">
            <thead>
              <tr className="border-b border-line bg-surface-alt">
                {selection && (
                  <th className="w-[44px] px-5 py-3 text-left">
                    <input
                      type="checkbox"
                      className="h-4 w-4 accent-brand"
                      aria-label={`Select all ${label} on this page`}
                      checked={allOnPageSelected}
                      ref={(el) => {
                        // Partial page selection reads as indeterminate, not off.
                        if (el) el.indeterminate = someOnPageSelected;
                      }}
                      disabled={selectableRows.length === 0}
                      onChange={(e) => selection.onToggleAll(e.target.checked)}
                    />
                  </th>
                )}
                {columns.map((c) => (
                  <th
                    key={c.key}
                    className={`px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-ink ${c.className ?? ""}`}
                  >
                    {c.label}
                  </th>
                ))}
                <th
                  className={`px-5 py-3 text-center text-xs font-bold uppercase tracking-wide text-ink ${actionColumnClassName}`}
                >
                  {actionLabel}
                </th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <tr>
                  <td
                    colSpan={columns.length + (selection ? 2 : 1)}
                    className="py-10 text-center text-ink-soft"
                  >
                    Loading {label}...
                  </td>
                </tr>
              ) : rows.length === 0 ? (
                <tr>
                  <td
                    colSpan={columns.length + (selection ? 2 : 1)}
                    className="py-10 text-center text-ink-soft"
                  >
                    {query.search.trim() ? searchEmptyMessage : emptyMessage}
                  </td>
                </tr>
              ) : (
                rows.map((row, idx) => (
                  <tr
                    key={row.id}
                    className={`border-b border-line last:border-b-0 ${
                      idx % 2 === 1 ? "bg-surface-alt" : "bg-white"
                    }`}
                  >
                    {selection && (
                      <td className="px-5 py-3.5">
                        <input
                          type="checkbox"
                          className="h-4 w-4 accent-brand"
                          aria-label={`Select ${selection.itemLabel}`}
                          checked={selection.selected.has(row.id)}
                          disabled={!(selection.isSelectable?.(row) ?? true)}
                          onChange={() => selection.onToggle(row.id)}
                        />
                      </td>
                    )}
                    {columns.map((c) => (
                      <td
                        key={c.key}
                        className={`px-5 py-3.5 text-sm text-ink ${c.className ?? ""}`}
                      >
                        {c.render(row)}
                      </td>
                    ))}
                    <td className="px-5 py-3.5 text-center">{actions(row)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {!isLoading && total > 0 && (
        <Pagination
          page={query.page - 1}
          pageCount={pageCount}
          onPage={(p) => query.onPage(p + 1)}
          summary={`Showing ${rows.length} of ${total} ${summaryVerb} ${label}`}
        />
      )}
    </>
  );
}
