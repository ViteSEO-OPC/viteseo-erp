"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Download } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StackedBarChart } from "@/modules/hr/components/stacked-bar-chart";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { Label } from "@/components/ui/label";

const RANGES = [
  { value: 3, label: "Last 3 Months" },
  { value: 6, label: "Last 6 Months" },
  { value: 9, label: "Last 9 Months" },
  { value: 12, label: "Last 12 Months" },
];

// Net pay / SSS / PhilHealth / Pag-IBIG, in series order.
const COMP_COLORS = ["var(--info)", "var(--warning)", "var(--success)", "var(--violet)"];

export function CompensationReport() {
  const [range, setRange] = useState(9);
  const [department, setDepartment] = useState("all");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [payGroup, setPayGroup] = useState("all");

  // FIN-041 — an explicit date range and a pay group, alongside the rolling `months` window this
  // report already had. The range WINS over `months` server-side: the rolling window is what the chart
  // has always shown, and a range is what reconciling a specific period needs.
  const params: Record<string, string | number> = { months: range };
  if (department !== "all") params.department = department;
  if (from) params.from = from;
  if (to) params.to = to;
  if (payGroup !== "all") params.frequency = payGroup;

  const { data: report, isLoading, isError } = useReport("compensation", params);
  const exportMut = useExportReport("compensation");

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
        series: report.stacked.series.map((s, i) => ({ ...s, color: COMP_COLORS[i] ?? s.color })),
        points: report.stacked.points,
      }
    : null;

  return (
    <div className="p-8">
      <PageHeader
        title={report?.title ?? "Compensation & Benefits"}
        subtitle={report?.description ?? "Gross-to-net breakdowns, cost per head, benefits coverage, payroll run history for audit."}
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
        {/* FIN-041 — pay group, offered only when there is more than one to choose between. */}
        {(report?.pay_groups?.length ?? 0) > 1 && (
          <SimpleSelect
            aria-label="Pay group"
            className="w-44"
            value={payGroup}
            onValueChange={setPayGroup}
            options={[
              { value: "all", label: "All pay groups" },
              ...report!.pay_groups!.map((g) => ({
                value: g,
                label: g.replace(/_/g, "-").replace(/\b\w/g, (c) => c.toUpperCase()),
              })),
            ]}
          />
        )}
        <div className="flex items-center gap-2">
          <Label htmlFor="comp-from">From</Label>
          <DatePicker id="comp-from" value={from} onChange={(v) => setFrom(v)} className="w-36" />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="comp-to">To</Label>
          <DatePicker id="comp-to" value={to} onChange={(v) => setTo(v)} className="w-36" />
        </div>
        {(from || to) && (
          <Button variant="ghost" onClick={() => { setFrom(""); setTo(""); }}>
            Clear range
          </Button>
        )}
      </div>

      {/* Stacked chart */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <h2 className="mb-4 text-h3 font-bold text-foreground">Gross-to-net per payroll run (stacked)</h2>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report || !stacked ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : stacked.points.length === 0 ? (
          <p className="text-sm text-muted-foreground">No completed payroll runs in the selected range.</p>
        ) : (
          <StackedBarChart data={stacked} yLabel="PHP (thousands)" />
        )}
      </div>
    </div>
  );
}
