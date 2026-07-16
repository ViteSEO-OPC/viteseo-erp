"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Download } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { StackedBarChart } from "@/modules/hr/components/stacked-bar-chart";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";

const selectClass =
  "h-9 rounded-lg border border-border bg-card px-3 pr-8 text-sm font-medium text-foreground outline-none focus-visible:border-ring";

const RANGES = [
  { value: 3, label: "Last 3 Months" },
  { value: 6, label: "Last 6 Months" },
  { value: 9, label: "Last 9 Months" },
  { value: 12, label: "Last 12 Months" },
];

// Anonymous / Named, in series order.
const HS_COLORS = ["var(--muted-foreground)", "var(--info)"];

export function HealthSafetyReport() {
  const [range, setRange] = useState(9);
  const [department, setDepartment] = useState("all");

  const { data: report, isLoading, isError } = useReport(
    "health_safety",
    department !== "all" ? { department } : undefined,
  );
  const exportMut = useExportReport("health_safety");

  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  const departments = report?.departments ?? [];
  const stacked = report?.stacked
    ? {
        series: report.stacked.series.map((s, i) => ({ ...s, color: HS_COLORS[i] ?? s.color })),
        points: report.stacked.points.slice(-range),
      }
    : null;

  return (
    <div className="p-8">
      <PageHeader
        title={report?.title ?? "Health & Safety"}
        subtitle={report?.description ?? "Incident tracking required for the agency. Employees may report concerns anonymously."}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" asChild>
              <Link href="/teamora/reports">
                <ArrowLeft />
                Back to report library
              </Link>
            </Button>
            <Button variant="outline" onClick={onExport} disabled={exportMut.isPending || !report}>
              <Download />
              {exportMut.isPending ? "Exporting…" : "Export"}
            </Button>
          </div>
        }
      />

      {/* Filters */}
      <div className="mt-6 flex flex-wrap items-center gap-3">
        <select aria-label="Date range" className={selectClass} value={range} onChange={(e) => setRange(Number(e.target.value))}>
          {RANGES.map((r) => (
            <option key={r.value} value={r.value}>{r.label}</option>
          ))}
        </select>
        <select aria-label="Department" className={selectClass} value={department} onChange={(e) => setDepartment(e.target.value)}>
          <option value="all">All Departments</option>
          {departments.map((d) => (
            <option key={d.id} value={d.id}>{d.name}</option>
          ))}
        </select>
      </div>

      {/* Stacked chart */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <h2 className="mb-4 text-h3 font-bold text-foreground">Reports per month, anonymous vs. named (stacked)</h2>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report || !stacked ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : (
          <StackedBarChart data={stacked} yLabel="Reports" />
        )}
      </div>
    </div>
  );
}
