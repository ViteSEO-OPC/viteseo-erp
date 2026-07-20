"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Info, Download, Lock } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { DataTable, type Column } from "@/core/components/data-table";
import { ReportBars } from "@/modules/hr/components/report-bars";
import { HeadcountReport } from "@/modules/hr/components/headcount-report";
import { RecruitmentReport } from "@/modules/hr/components/recruitment-report";
import { LeaveReport } from "@/modules/hr/components/leave-report";
import { CompensationReport } from "@/modules/hr/components/compensation-report";
import { AttritionReport } from "@/modules/hr/components/attrition-report";
import { DiversityReport } from "@/modules/hr/components/diversity-report";
import { HealthSafetyReport } from "@/modules/hr/components/health-safety-report";
import { AuditReport } from "@/modules/hr/components/audit-report";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";

export default function ReportDetailPage() {
  const params = useParams<{ key: string }>();
  // Reports with a bespoke chart layout render their own view; the rest use the
  // generic summary + bars + table renderer below.
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
  const { data: report, isLoading, isError } = useReport(reportKey);
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
