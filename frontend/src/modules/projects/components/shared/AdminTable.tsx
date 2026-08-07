"use client";

import type { ReactNode } from "react";
import { Card } from "@/core/ui";
import type { Paginated } from "@/core/types";
import { Pagination } from "../team/TeamShared";

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
              <tr className="border-b border-line bg-[#f9fafb]">
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
                    colSpan={columns.length + 1}
                    className="py-10 text-center text-ink-soft"
                  >
                    Loading {label}...
                  </td>
                </tr>
              ) : rows.length === 0 ? (
                <tr>
                  <td
                    colSpan={columns.length + 1}
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
                      idx % 2 === 1 ? "bg-[#f9fafb]" : "bg-white"
                    }`}
                  >
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
