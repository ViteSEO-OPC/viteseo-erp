import { apiClient } from "@/core/api-client";
import type { Report } from "@/modules/hr/types/reports";

export async function getReport(
  key: string,
  params?: Record<string, string | number>,
): Promise<Report> {
  const res = await apiClient.get(`/hr/reports/${key}`, { params });
  return res.data.data;
}

/**
 * Trigger a report export. The backend streams a file (CSV for table reports, PDF
 * for chart reports); we return the bytes + the server-provided filename so the
 * caller can save it.
 */
export async function exportReport(
  key: string,
  params?: Record<string, string | number>,
): Promise<{ blob: Blob; filename: string }> {
  const res = await apiClient.post(`/hr/reports/${key}/export`, null, {
    // The same filters the screen is showing, so the file matches the table it came from
    // rather than silently reverting to the report's defaults.
    params,
    responseType: "blob",
  });
  const disposition = (res.headers["content-disposition"] as string | undefined) ?? "";
  const match = disposition.match(/filename="?([^"]+)"?/);
  return { blob: res.data as Blob, filename: match?.[1] ?? `${key}-report` };
}
