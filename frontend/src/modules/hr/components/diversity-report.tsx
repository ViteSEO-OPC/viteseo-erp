"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Download } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { HorizontalStackedChart } from "@/modules/hr/components/horizontal-stacked-chart";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";
import { SimpleSelect } from "@/modules/hr/ui/select";

const RANGES = [
  { value: 3, label: "Last 3 Months" },
  { value: 6, label: "Last 6 Months" },
  { value: 9, label: "Last 9 Months" },
  { value: 12, label: "Last 12 Months" },
];

// Women / Men, in series order.
const REP_COLORS = ["var(--violet)", "var(--brand)"];

export function DiversityReport() {
  const [range, setRange] = useState(9);
  const [department, setDepartment] = useState("all");

  const { data: report, isLoading, isError } = useReport(
    "diversity",
    department !== "all" ? { department } : undefined,
  );
  const exportMut = useExportReport("diversity");

  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  const departments = report?.departments ?? [];
  const representation = report?.representation
    ? {
        series: report.representation.series.map((s, i) => ({ ...s, color: REP_COLORS[i] ?? s.color })),
        points: report.representation.points,
      }
    : null;

  return (
    <div className="p-8">
      <PageHeader
        title={report?.title ?? "Diversity"}
        subtitle={report?.description ?? "Gender representation across departments. Restricted to HR and leadership."}
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

      {/* Representation chart */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <h2 className="mb-4 text-h3 font-bold text-foreground">Representation by department (100% stacked)</h2>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report || !representation ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : representation.points.length === 0 ? (
          <p className="text-sm text-muted-foreground">No gender data available.</p>
        ) : (
          <HorizontalStackedChart data={representation} xLabel="Share of department (%)" />
        )}
      </div>
    </div>
  );
}
