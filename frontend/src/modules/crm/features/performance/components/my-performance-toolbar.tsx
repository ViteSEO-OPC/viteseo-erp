"use client";

import { useState } from "react";
import { Download } from "lucide-react";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { Button } from "@/modules/crm/components/ui/button";
import { useExportPerformancePdf } from "@/modules/crm/features/performance/hooks/use-export-performance-pdf";

// Export PDF is live: it downloads the BD's OWN scorecard, rendered server-side from the same
// computation this page reads (no user_id is sent — the server resolves the requester's scope).
//
// The range selector remains presentation-only: no performance endpoint accepts a date range, so it
// still drives no query. It deliberately does NOT feed the export — the PDF prints no global
// reporting period precisely so it cannot imply a filter the backend does not apply.
const RANGES = ["Last 30 Days", "Last 7 Days", "This Quarter", "This Year"];

export function MyPerformanceToolbar() {
  const [range, setRange] = useState(RANGES[0]);
  const { exportPerformancePdf, isExporting } = useExportPerformancePdf();

  return (
    <div className="flex items-center justify-between gap-3">
      <Select value={range} onValueChange={(v) => setRange(v ?? range)}>
        <SelectTrigger className="w-40" aria-label="Date range">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {RANGES.map((r) => (
            <SelectItem key={r} value={r}>
              {r}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <Button
        variant="outline"
        size="sm"
        onClick={() => void exportPerformancePdf()}
        disabled={isExporting}
      >
        <Download className="h-4 w-4" />
        {isExporting ? "Exporting…" : "Export PDF"}
      </Button>
    </div>
  );
}
