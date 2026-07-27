"use client";

import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { ManagementKpiCard } from "@/modules/crm/features/management/components/management-kpi-card";
import { LeadAcquisitionCard } from "@/modules/crm/features/management/components/lead-acquisition-card";
import { LeadsByStatusCard } from "@/modules/crm/features/management/components/leads-by-status-card";
import { BdPerformanceCard } from "@/modules/crm/features/management/components/bd-performance-card";
import { ManagementActivityCard } from "@/modules/crm/features/management/components/management-activity-card";
import { useManagementData } from "@/modules/crm/features/management/hooks/use-management-data";
import { useBdPerformance } from "@/modules/crm/features/management/hooks/use-bd-performance";

// Presentation-only range filter (mock — the future backend metrics compute against it).
const RANGES = ["Last 6 months", "Last 30 days", "This quarter", "This year"];

export function ManagementDashboardPage() {
  const { data, range, setRange } = useManagementData();
  const bdPerformance = useBdPerformance();

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-end">
        <Select value={range} onValueChange={(v) => setRange(v ?? range)}>
          <SelectTrigger className="w-44" aria-label="Date range">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {RANGES.map((r) => (
              <SelectItem key={r} value={r}>{r}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        {data.kpis.map((kpi) => (
          <ManagementKpiCard key={kpi.id} kpi={kpi} />
        ))}
      </div>

      {/* Lead Acquisition + Leads by Status */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <LeadAcquisitionCard className="lg:col-span-8" />
        <LeadsByStatusCard className="lg:col-span-4" />
      </div>

      {/* BD Performance + Recent Activity */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <BdPerformanceCard
          rows={bdPerformance.data}
          isLoading={bdPerformance.isLoading}
          isError={bdPerformance.isError}
          className="lg:col-span-8"
        />
        <ManagementActivityCard className="lg:col-span-4" />
      </div>
    </div>
  );
}
