"use client";

import { useCallback, useState } from "react";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";

/**
 * Download a performance scorecard PDF.
 *
 * The single frontend entry point for performance export — My Performance (a BD's own scorecard)
 * and Reports & Performance (Admin/SA: one BD, or the CRM-wide All Reports) both call this, and it
 * hits the one backend route that renders the document. Nothing about the PDF is built in the
 * browser, and no figure is recomputed here.
 *
 * `userId` is passed ONLY by the management surface when a specific BD is selected. Omitting it
 * means "my own scope" for a BD and "All Reports" for an Admin/SA — the same default the JSON
 * endpoints use. A BD sending it would be rejected 403 server-side; the UI simply never does.
 *
 * Mirrors useExportLeadPdf: apiClient (so the request carries auth), blob response, filename from
 * Content-Disposition with a local fallback.
 */
export function useExportPerformancePdf() {
  const [isExporting, setIsExporting] = useState(false);

  const exportPerformancePdf = useCallback(async (userId?: string) => {
    setIsExporting(true);
    try {
      const res = await apiClient.get("/dashboard/performance-export-pdf", {
        params: userId ? { user_id: userId } : {},
        responseType: "blob",
      });

      const disposition = res.headers["content-disposition"] as string | undefined;
      const name = disposition?.match(/filename="?([^"]+)"?/)?.[1] ?? "performance-report.pdf";

      const href = URL.createObjectURL(res.data as Blob);
      const link = document.createElement("a");
      link.href = href;
      link.download = name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(href);
    } catch {
      toast.error("Could not export the performance PDF. Please try again.");
    } finally {
      setIsExporting(false);
    }
  }, []);

  return { exportPerformancePdf, isExporting };
}
