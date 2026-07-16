"use client";

import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { ReportsToolbar } from "@/modules/crm/features/reports/components/reports-toolbar";
import { StatCardChart } from "@/modules/crm/features/reports/components/stat-card-chart";
import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { LabeledBarList } from "@/modules/crm/components/shared/labeled-bar-list/labeled-bar-list";
import { BarChart } from "@/modules/crm/components/charts/bar-chart/bar-chart";
import { LineChart } from "@/modules/crm/components/charts/line-chart/line-chart";
import { PieChart } from "@/modules/crm/components/charts/pie-chart/pie-chart";
import { useReportsData } from "@/modules/crm/features/reports/hooks/use-reports-data";

export function ReportsGrid() {
  const { data, range, setRange } = useReportsData();

  return (
    <div className="flex flex-col gap-6">
      <ReportsToolbar range={range} onRangeChange={setRange} />

      {/* Row 1 — KPI cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {data.kpis.map((kpi) => (
          <StatCardChart key={kpi.id} card={kpi} />
        ))}
      </div>

      {/* Row 2 — Status bar + Priority donut */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-12">
        <ChartCard
          title="Total leads by status"
          subtitle="Distribution across pipeline stages"
          className="md:col-span-8"
        >
          <LabeledBarList rows={data.statusBars} max={100} />
        </ChartCard>

        <ChartCard
          title={data.priorityDonut.title}
          subtitle={data.priorityDonut.subtitle}
          className="md:col-span-4"
        >
          <PieChart
            segments={data.priorityDonut.segments}
            centerLabel={data.priorityDonut.centerLabel}
          />
        </ChartCard>
      </div>

      {/* Row 3 — Three donut cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {data.outcomeDonuts.map((donut, i) => (
          <ChartCard key={i} title={donut.title} subtitle={donut.subtitle}>
            <PieChart
              segments={donut.segments}
              centerLabel={donut.centerLabel}
            />
          </ChartCard>
        ))}
      </div>

      {/* Row 4 — Line + Conversion bar */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-12">
        <ChartCard
          title="Monthly outreach activity"
          subtitle="Email vs Cold call, Feb – Jul"
          className="md:col-span-8"
        >
          <LineChart series={data.outreachLine} />
        </ChartCard>

        <ChartCard
          title="Lead conversion rate"
          subtitle="Monthly conversion % (Feb – Jul)"
          className="md:col-span-4"
        >
          <BarChart
            data={data.conversionBars.map((b) => ({
              label: b.month,
              value: b.value,
              color: b.color,
              highlight: b.highlight,
            }))}
            max={30}
          />
        </ChartCard>
      </div>

      {/* Row 5 — Industry bars */}
      <ChartCard
        title="Leads by industry"
        subtitle="Distribution across top sectors"
        filter={
          <Select defaultValue="Last 30 Days">
            <SelectTrigger className="h-7 rounded-lg bg-gray-150 px-2 py-1 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="Last 30 Days">Last 30 Days</SelectItem>
            </SelectContent>
          </Select>
        }
      >
        <LabeledBarList rows={data.industryBars.map((b) => ({ label: b.sector, value: b.value, color: b.color }))} />
      </ChartCard>
    </div>
  );
}
