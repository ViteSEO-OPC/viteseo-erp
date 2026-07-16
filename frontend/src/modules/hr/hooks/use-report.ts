"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getReport, exportReport } from "@/modules/hr/api/reports";

export function useReport(key: string, params?: Record<string, string | number>) {
  return useQuery({
    queryKey: ["hr", "reports", key, params ?? {}],
    queryFn: () => getReport(key, params),
  });
}

export function useExportReport(key: string) {
  const qc = useQueryClient();
  return useMutation({
    // Fetch the file, then trigger a browser download (CSV or PDF).
    mutationFn: async () => {
      const { blob, filename } = await exportReport(key);
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
