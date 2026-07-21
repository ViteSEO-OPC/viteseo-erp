"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Download } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { GroupedBarChart } from "@/modules/hr/components/grouped-bar-chart";
import { TenureCurveChart } from "@/modules/hr/components/tenure-curve-chart";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";
import { SimpleSelect } from "@/modules/hr/ui/select";

const RANGES = [
  { value: 3, label: "Last 3 Months" },
  { value: 6, label: "Last 6 Months" },
  { value: 9, label: "Last 9 Months" },
  { value: 12, label: "Last 12 Months" },
];

// Voluntary / Involuntary, in series order.
const DEP_COLORS = ["var(--info)", "var(--danger)"];

export function AttritionReport() {
  const [range, setRange] = useState(9);
  const [department, setDepartment] = useState("all");

  const { data: report, isLoading, isError } = useReport(
    "attrition",
    department !== "all" ? { department } : undefined,
  );
  const exportMut = useExportReport("attrition");

  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  const departments = report?.departments ?? [];
  // Show the last ceil(range/3) quarters of departures.
  const qCount = Math.max(1, Math.ceil(range / 3));
  const departures = report?.departures
    ? {
        series: report.departures.series.map((s, i) => ({ ...s, color: DEP_COLORS[i] ?? s.color })),
        points: report.departures.points.slice(-qCount),
      }
    : null;
  const tenure = (report?.tenure ?? []) as { label: string; value: number }[];

  return (
    <div className="p-8">
      <PageHeader
        title={report?.title ?? "Attrition & Tenure"}
        subtitle={report?.description ?? "Voluntary vs. involuntary departures, tenure curves — how long employees stay."}
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
        <SimpleSelect
          aria-label="Date range"
          className="w-48"
          value={String(range)}
          onValueChange={(v) => setRange(Number(v))}
          options={RANGES.map((r) => ({ value: String(r.value), label: r.label }))}
        />
        <SimpleSelect
          aria-label="Department"
          className="w-56"
          value={department}
          onValueChange={setDepartment}
          options={[
            { value: "all", label: "All Departments" },
            ...departments.map((d) => ({ value: String(d.id), label: d.name })),
          ]}
        />
      </div>

      {/* Two charts side by side */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report || !departures ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : (
          <div className="grid gap-8 lg:grid-cols-2">
            <div>
              <h2 className="mb-3 text-h3 font-bold text-foreground">Departures per quarter</h2>
              <GroupedBarChart data={departures} yLabel="Departures" />
            </div>
            <div>
              <h2 className="mb-3 text-h3 font-bold text-foreground">Tenure curve</h2>
              <TenureCurveChart data={tenure} />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
