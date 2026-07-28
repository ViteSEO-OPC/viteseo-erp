"use client";

import { useCallback, useState } from "react";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";

/**
 * Download one lead's PDF profile.
 *
 * The single frontend entry point for lead PDF export — the Lead Details header action and the BD
 * table's row action both call this, and it hits the one backend route that renders the document.
 * Nothing about the PDF is built in the browser.
 *
 * Goes through apiClient (not a bare link) so the request carries auth headers; the response is a
 * blob, and the filename comes from the server's Content-Disposition with a local fallback.
 *
 * NOTE: the blob-download mechanics mirror the HR module's private `downloadFile` helper. It is not
 * imported across the module boundary; lifting it into `src/core` is the eventual home once a third
 * module needs it.
 */
export function useExportLeadPdf() {
  const [exportingId, setExportingId] = useState<string | null>(null);

  const exportLeadPdf = useCallback(async (leadId: string, businessName?: string) => {
    setExportingId(leadId);
    try {
      const res = await apiClient.get(`/leads/${leadId}/export-pdf`, { responseType: "blob" });

      const disposition = res.headers["content-disposition"] as string | undefined;
      const fallback = `lead-${businessName ? businessName.toLowerCase().replace(/\s+/g, "-") : leadId}.pdf`;
      const name = disposition?.match(/filename="?([^"]+)"?/)?.[1] ?? fallback;

      const href = URL.createObjectURL(res.data as Blob);
      const link = document.createElement("a");
      link.href = href;
      link.download = name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(href);
    } catch {
      toast.error("Could not export the lead PDF. Please try again.");
    } finally {
      setExportingId(null);
    }
  }, []);

  return { exportLeadPdf, exportingId };
}
