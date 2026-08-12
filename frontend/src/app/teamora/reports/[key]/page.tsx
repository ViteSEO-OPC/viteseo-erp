"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { Suspense, useState } from "react";
import { ArrowLeft, Info, Download, Lock } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { DataTable, type Column } from "@/core/components/data-table";
import { ReportBars } from "@/modules/hr/components/report-bars";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { Label } from "@/components/ui/label";
import { HeadcountReport } from "@/modules/hr/components/headcount-report";
import { RecruitmentReport } from "@/modules/hr/components/recruitment-report";
import { LeaveReport } from "@/modules/hr/components/leave-report";
import { CompensationReport } from "@/modules/hr/components/compensation-report";
import { AttritionReport } from "@/modules/hr/components/attrition-report";
import { DiversityReport } from "@/modules/hr/components/diversity-report";
import { HealthSafetyReport } from "@/modules/hr/components/health-safety-report";
import { AuditReport } from "@/modules/hr/components/audit-report";
import { AttendanceReport } from "@/modules/hr/components/attendance-report";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";

/** The three tabs of the Attendance Summary screen, all rendered by the same component. */
const ATTENDANCE_KEYS = ["attendance_summary", "attendance_dtr", "attendance_leave"];

export default function ReportDetailPage() {
  const params = useParams<{ key: string }>();
  // Reports with a bespoke chart layout render their own view; the rest use the
  // generic summary + bars + table renderer below.
  if (ATTENDANCE_KEYS.includes(params.key)) {
    // AttendanceReport drives its filters off the query string, and useSearchParams needs a
    // Suspense boundary during static rendering.
    return (
      <Suspense fallback={null}>
        <AttendanceReport reportKey={params.key} />
      </Suspense>
    );
  }
  if (params.key === "headcount") return <HeadcountReport />;
  if (params.key === "recruitment") return <RecruitmentReport />;
  if (params.key === "leave") return <LeaveReport />;
  if (params.key === "compensation") return <CompensationReport />;
  if (params.key === "attrition") return <AttritionReport />;
  if (params.key === "diversity") return <DiversityReport />;
  if (params.key === "health_safety") return <HealthSafetyReport />;
  if (params.key === "audit") return <AuditReport />;
  return <GenericReport reportKey={params.key} />;
}

function GenericReport({ reportKey }: { reportKey: string }) {
  // MF-11 — the generic renderer now honours a department filter whenever the report's payload
  // carries a `departments` list. Reports were already returning one and the fallback view ignored
  // it, so any report without a bespoke component had a filter the server supported and the page
  // never offered. The control only appears when there is something to filter by.
  const [department, setDepartment] = useState("all");
  // FIN-041 / FIN-042 — a date range, a pay group and (for the register) which run to show. Each is
  // sent only when set, so an untouched screen asks for exactly what it always did.
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [payGroup, setPayGroup] = useState("all");
  const [run, setRun] = useState("");
  // FIN-055 — the tax reconciliation is asked a YEAR, not a date range: annual tax is a property
  // of the whole year, and a partial range would compute a bracket against pay that is not all of
  // it. Empty means "whatever the server picked", which is the current year.
  const [year, setYear] = useState("");

  const {
    data: report,
    isLoading,
    isError,
  } = useReport(reportKey, {
    ...(department !== "all" ? { department } : {}),
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
    ...(payGroup !== "all" ? { frequency: payGroup } : {}),
    ...(run ? { run } : {}),
    ...(year ? { year } : {}),
  });
  const exportMut = useExportReport(reportKey);

  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title={report?.title ?? "Report"}
        subtitle={report?.description}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" asChild>
              <Link href="/teamora/reports">
                <ArrowLeft />
                Back to reports
              </Link>
            </Button>
            {report && (
              <Button onClick={onExport} disabled={exportMut.isPending}>
                <Download />
                {exportMut.isPending ? "Exporting…" : "Export"}
              </Button>
            )}
          </div>
        }
      />

      <div className="mt-6 space-y-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading report…</p>
        ) : isError || !report ? (
          <p className="text-sm text-destructive">Could not load this report.</p>
        ) : (
          <>
            {report.restricted && (
              <div className="flex items-center gap-2 rounded-card border border-border bg-muted/40 px-4 py-2.5 text-sm text-muted-foreground">
                <Lock className="size-4" />
                Access restricted to HR leadership / admin.
              </div>
            )}

            {/* FIN-041 / FIN-042 — each control appears only when the report's own payload offers
                something to filter by, so a report that doesn't support one never shows it. */}
            {((report.departments?.length ?? 0) > 0 ||
              (report.pay_groups?.length ?? 0) > 0 ||
              (report.years?.length ?? 0) > 0 ||
              (report.runs?.length ?? 0) > 0) && (
              <div className="flex flex-wrap items-end gap-3 rounded-card border border-border bg-card p-4">
                {(report.years?.length ?? 0) > 0 && (
                  <div className="space-y-1.5">
                    <Label htmlFor="rep-year">Tax year</Label>
                    <SimpleSelect
                      className="w-36"
                      value={year || String(report.selected_year ?? "")}
                      onValueChange={setYear}
                      options={report.years!.map((y) => ({ value: String(y), label: String(y) }))}
                    />
                  </div>
                )}
                {(report.runs?.length ?? 0) > 0 && (
                  <div className="space-y-1.5">
                    <Label htmlFor="rep-run">Run</Label>
                    <SimpleSelect
                      className="w-64"
                      value={run || String(report.selected_run?.id ?? "")}
                      onValueChange={setRun}
                      options={report.runs!.map((r) => ({ value: String(r.id), label: r.label }))}
                    />
                  </div>
                )}
                {(report.departments?.length ?? 0) > 0 && (
                  <div className="space-y-1.5">
                    <Label htmlFor="rep-dept">Department</Label>
                    <SimpleSelect
                      className="w-52"
                      value={department}
                      onValueChange={setDepartment}
                      options={[
                        { value: "all", label: "All departments" },
                        ...report.departments!.map((d) => ({ value: String(d.id), label: d.name })),
                      ]}
                    />
                  </div>
                )}
                {(report.pay_groups?.length ?? 0) > 0 && (
                  <div className="space-y-1.5">
                    <Label htmlFor="rep-group">Pay group</Label>
                    <SimpleSelect
                      className="w-44"
                      value={payGroup}
                      onValueChange={setPayGroup}
                      options={[
                        { value: "all", label: "All pay groups" },
                        ...report.pay_groups!.map((g) => ({
                          value: g,
                          label: g.replace(/_/g, "-").replace(/\b\w/g, (c) => c.toUpperCase()),
                        })),
                      ]}
                    />
                  </div>
                )}
                {/* The range is offered on any report carrying a department list — every one of them
                    aggregates over runs, so a period is always a meaningful thing to ask for.
                    FIN-055 — EXCEPT a year-scoped one. Annual tax is a property of the whole year:
                    a bracket found on part of it is not a partial answer, it is a wrong one. A
                    report that asks for a year does not also offer a range that could contradict it. */}
                {(report.years?.length ?? 0) === 0 && (
                  <>
                    <div className="flex items-center gap-2">
                      <Label htmlFor="rep-from">From</Label>
                      <DatePicker id="rep-from" value={from} onChange={(v) => setFrom(v)} className="w-36" />
                    </div>
                    <div className="flex items-center gap-2">
                      <Label htmlFor="rep-to">To</Label>
                      <DatePicker id="rep-to" value={to} onChange={(v) => setTo(v)} className="w-36" />
                    </div>
                  </>
                )}
                {(from || to || department !== "all" || payGroup !== "all") && (
                  <Button
                    variant="ghost"
                    onClick={() => {
                      setFrom("");
                      setTo("");
                      setDepartment("all");
                      setPayGroup("all");
                    }}
                  >
                    Clear filters
                  </Button>
                )}
              </div>
            )}

            {report.mock && (
              <div className="flex items-start gap-3 rounded-card border border-warning/40 bg-warning-subtle px-4 py-3">
                <Info className="mt-0.5 size-5 shrink-0 text-warning" />
                <p className="text-sm text-foreground">
                  This report is a <span className="font-semibold">placeholder</span> —
                  the MVP schema doesn’t capture the underlying data yet.
                </p>
              </div>
            )}

            {/* Summary */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              {report.summary.map((s, i) => (
                <StatCard key={i} label={s.label} value={s.value} highlight={i === 0} />
              ))}
            </div>

            {/* Bars */}
            {report.bars && (
              <div className="rounded-card border border-border bg-card p-6">
                <h2 className="mb-4 text-h3 font-bold text-foreground">{report.bars.title}</h2>
                <ReportBars bars={report.bars} />
              </div>
            )}

            {/* Table */}
            {report.table && (
              <DataTable
                columns={report.table.columns.map((c, i): Column<string[]> => ({
                  header: c,
                  align: i > 0 ? "right" : "left",
                  className: i === 0 ? "font-medium text-foreground" : "text-muted-foreground",
                  cell: (row) => row[i] ?? "—",
                }))}
                rows={report.table.rows}
                rowKey={(_row, i) => i}
                pageSize={10}
              />
            )}

            {/* FIN-042 — the variance against the previous period. Its own block below the register,
                because it answers a different question: not "what does this run pay" but "what
                changed since last time". */}
            {report.variance && (
              <div>
                <h2 className="mb-3 text-h3 font-bold text-foreground">{report.variance.title}</h2>
                <DataTable
                  columns={report.variance.columns.map((c, i): Column<string[]> => ({
                    header: c,
                    align: i > 0 ? "right" : "left",
                    className: i === 0 ? "font-medium text-foreground" : "text-muted-foreground",
                    cell: (row) => row[i] ?? "—",
                  }))}
                  rows={report.variance.rows}
                  rowKey={(_row, i) => i}
                  pageSize={0}
                />
              </div>
            )}

            {/* Government remittance (Payroll & Cost) */}
            {report.remittance && (
              <div>
                <h2 className="mb-3 text-h3 font-bold text-foreground">
                  {report.remittance.title}
                </h2>
                <DataTable
                  columns={report.remittance.columns.map((c, i): Column<string[]> => ({
                    header: c,
                    align: i > 0 ? "right" : "left",
                    className: i === 0 ? "font-medium text-foreground" : "text-muted-foreground",
                    cell: (row) => row[i] ?? "—",
                  }))}
                  rows={report.remittance.rows}
                  rowKey={(_row, i) => i}
                  pageSize={0}
                />
              </div>
            )}

            {report.note && (
              <p className="text-caption text-muted-foreground">{report.note}</p>
            )}

            {/* Export history */}
            <div>
              <h2 className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
                Export history
              </h2>
              {report.exports.length === 0 ? (
                <p className="text-caption text-muted-foreground">Not exported yet.</p>
              ) : (
                <div className="overflow-hidden rounded-card border border-border bg-card">
                  {report.exports.map((e, i) => (
                    <div key={i} className="flex items-center justify-between border-b border-border px-4 py-2.5 text-sm last:border-0">
                      <span className="text-foreground">{e.by}</span>
                      <span className="text-caption text-muted-foreground">{e.when ?? "—"}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
