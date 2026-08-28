"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getReport, exportReport } from "@/modules/hr/api/reports";

/**
 * One report.
 *
 * `keepPreviousData` is for the SERVER-PAGED reports: turning a page changes the query key, so
 * without it the screen has no data for a moment and the whole table is replaced by its loading
 * state — a page hop reads as the records vanishing and coming back. With it the previous page
 * stays on screen (the caller dims it via `isFetching`) until the next one arrives.
 */
export function useReport(
  key: string,
  params?: Record<string, string | number>,
  options?: { keepPreviousData?: boolean },
) {
  return useQuery({
    queryKey: ["hr", "reports", key, params ?? {}],
    queryFn: () => getReport(key, params),
    ...(options?.keepPreviousData ? { placeholderData: keepPreviousData } : {}),
  });
}

export function useExportReport(key: string, params?: Record<string, string | number>) {
  const qc = useQueryClient();
  return useMutation({
    // Fetch the file, then trigger a browser download (CSV or PDF).
    mutationFn: async () => {
      const { blob, filename } = await exportReport(key, params);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    },
    // Refresh the report so its export history reflects the new entry.
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "reports", key] }),
  });
}
