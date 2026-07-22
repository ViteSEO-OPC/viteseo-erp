"use client";

import { useCallback, useState } from "react";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";
import { downloadLeadsCsv } from "@/modules/crm/features/leads/lib/leads-csv";

type LeadListResponse = {
  data: Lead[];
  meta: { last_page: number; total: number };
};

// Safety cap so a runaway/huge dataset can't spin forever (client-side export is a first-phase
// approach; a dedicated export endpoint can replace this at scale).
const MAX_PAGES = 200;

/**
 * Client-side CSV export. Reuses the existing paginated `GET /leads` endpoint with the CURRENT
 * filter/sort params (no new backend endpoint), walking every page so the export matches exactly
 * the full filtered result set the user is viewing. Builds and downloads the CSV in the browser.
 */
export function useExportLeads() {
  const [isExporting, setIsExporting] = useState(false);

  const exportLeads = useCallback(async (params: Record<string, string>) => {
    setIsExporting(true);
    try {
      const rows: Lead[] = [];
      let page = 1;
      let lastPage = 1;

      do {
        const res = await apiClient.get<LeadListResponse>("/leads", {
          params: { ...params, page },
        });
        rows.push(...res.data.data);
        lastPage = res.data.meta.last_page;
        page += 1;
      } while (page <= lastPage && page <= MAX_PAGES);

      if (rows.length === 0) {
        toast.info("No leads to export for the current filters.");
        return;
      }

      const today = new Date().toISOString().slice(0, 10);
      downloadLeadsCsv(rows, `leads-export-${today}.csv`);
      toast.success(`Exported ${rows.length} lead${rows.length === 1 ? "" : "s"}.`);
    } catch {
      toast.error("Export failed. Please try again.");
    } finally {
      setIsExporting(false);
    }
  }, []);

  return { exportLeads, isExporting };
}
