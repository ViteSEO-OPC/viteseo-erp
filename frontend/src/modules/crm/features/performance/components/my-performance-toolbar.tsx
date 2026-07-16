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

// Presentation-only toolbar. The range selector and Export PDF are Demo affordances — there is no
// date-range or export backend this milestone (both deferred per BD-PERF-COMP-SPEC). The selector
// holds local UI state only (it drives no query); Export is disabled until the KPI Engine lands.
const RANGES = ["Last 30 Days", "Last 7 Days", "This Quarter", "This Year"];

export function MyPerformanceToolbar() {
  const [range, setRange] = useState(RANGES[0]);

  return (
    <div className="flex items-center justify-between gap-3">
      <Select value={range} onValueChange={(v) => setRange(v ?? range)}>
        <SelectTrigger className="w-40">
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

      <Button variant="outline" size="sm" disabled title="Available with the KPI Engine">
        <Download className="h-4 w-4" />
        Export PDF
      </Button>
    </div>
  );
}
