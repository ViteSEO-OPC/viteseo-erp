"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, Download } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { RecruitmentFunnelChart } from "@/modules/hr/components/recruitment-funnel-chart";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";
import { SimpleSelect } from "@/modules/hr/ui/select";

const RANGES = [
  { value: 3, label: "Last 3 Months" },
  { value: 6, label: "Last 6 Months" },
  { value: 9, label: "Last 9 Months" },
  { value: 12, label: "Last 12 Months" },
];

export function RecruitmentReport() {
  const [range, setRange] = useState(9);
  const [department, setDepartment] = useState("all");

  const params: Record<string, string | number> = { months: range };
  if (department !== "all") params.department = department;

  const { data: report, isLoading, isError } = useReport("recruitment", params);
  const exportMut = useExportReport("recruitment");

  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  const funnel = report?.funnel ?? [];
  const departments = report?.departments ?? [];

  return (
    <div className="p-8">
      <PageHeader
        title={report?.title ?? "Recruitment Funnel"}
        subtitle={report?.description ?? "Candidate pipeline; funnel matches the pipeline stages."}
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

      {/* Funnel */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <h2 className="mb-4 text-h3 font-bold text-foreground">Pipeline funnel</h2>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : funnel.length === 0 ? (
          <p className="text-sm text-muted-foreground">No candidates in the selected range.</p>
        ) : (
          <RecruitmentFunnelChart data={funnel} />
        )}
      </div>
    </div>
  );
}
