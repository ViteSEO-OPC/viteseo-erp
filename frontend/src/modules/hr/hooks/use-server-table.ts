"use client";

import { useState } from "react";

import type { ServerTable } from "@/core/components/data-table";
import type { StatusFilterOption } from "@/core/components/status-filter";
import { useDebouncedValue } from "@/core/use-debounced-value";
import type { DateWindow, QueueParams } from "@/modules/hr/api/queue";

/**
 * The state behind a server-paged table, in one place.
 *
 * Every queue screen needs the same four things — a page, a search box, a status filter, and a
 * debounce so the box does not fire a request per keystroke — and gets them wrong in the same
 * two ways when written out by hand: forgetting to return to page 1 when a filter narrows the
 * result, and passing the raw search straight into the query key.
 *
 * ```tsx
 * const table = useServerTable();
 * const { data, isFetching } = useReimbursements(table.params);
 * <DataTable rows={data?.items ?? []} server={table.serverProps(data?.total ?? 0, isFetching)} />
 * ```
 */
export function useServerTable(pageSize = 10, dateWindow?: DateWindow) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");

  // The box stays responsive; the request waits for the typing to settle.
  const debouncedSearch = useDebouncedValue(search, 250);

  /*
   * A window that changes is a filter that narrows, so it goes back to page 1 — the third way
   * these screens get it wrong, and the one the table itself cannot fix: the pills and the
   * search box reset the page through `onParamsChange`, but a period picker lives OUTSIDE the
   * table. Left alone, page 7 of the old result is usually past the end of the new one, and an
   * empty table under a filter that matched plenty of rows reads as "nothing here".
   *
   * Adjusted during render rather than in an effect: React re-runs this render with the new
   * page before anything is committed, so no request is ever made for the stale one.
   */
  const windowKey = `${dateWindow?.from ?? ""}|${dateWindow?.to ?? ""}`;
  const [appliedWindow, setAppliedWindow] = useState(windowKey);
  if (windowKey !== appliedWindow) {
    setAppliedWindow(windowKey);
    setPage(1);
  }

  const params: QueueParams = {
    page,
    per_page: pageSize,
    search: debouncedSearch.trim() || undefined,
    status,
    ...(dateWindow?.from ? { from: dateWindow.from } : {}),
    ...(dateWindow?.to ? { to: dateWindow.to } : {}),
  };

  /**
   * The `server` prop for DataTable. Pass the total and whether a fetch is in flight; pass
   * `statusOptions` when the table should show status pills — server mode cannot derive them
   * from one page of rows.
   */
  function serverProps(
    total: number,
    loading: boolean,
    statusOptions?: StatusFilterOption[],
  ): ServerTable {
    return {
      total,
      page,
      loading,
      statusOptions,
      onParamsChange: (next) => {
        setPage(next.page);
        setSearch(next.query);
        setStatus(next.status);
      },
    };
  }

  /** Return to page 1 — for a filter that lives OUTSIDE the table and changes the result. */
  const resetPage = () => setPage(1);

  // `setPage` is exposed for the few places that page a list without a DataTable around it —
  // the payment-batch dialog renders its own rows and still needs somewhere to go past the first
  // ten. Prefer `serverProps` wherever there IS a table; it wires the pager for you.
  return { params, serverProps, resetPage, setPage, pageSize };
}
