"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { ArrowLeft, Download, Printer, Search } from "lucide-react";

import { cn } from "@/lib/utils";
import { notify as toast } from "@/core/lib/notify";
import { Button } from "@/components/ui/button";
import { StatCard } from "@/core/components/stat-card";
import { PageHeader } from "@/modules/hr/components/page-header";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useReport, useExportReport } from "@/modules/hr/hooks/use-report";
import {
  AttendanceDtrTable,
  AttendanceLeaveTable,
  AttendanceLegend,
  AttendanceSummaryTable,
} from "@/modules/hr/components/attendance-report-tables";

/**
 * The Attendance Summary screen: three month-scoped views of the same period — per-employee
 * totals, the daily time record, and leave usage — behind one filter bar.
 */

const ALL_DEPARTMENTS = "all";

export function AttendanceReport({ reportKey }: { reportKey: string }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  // The URL is what the report is fetched with; the two selects below are a DRAFT of it that only
  // takes effect on "Generate report", which is what the button is for.
  const appliedMonth = searchParams.get("month") ?? "";
  const appliedDepartment = searchParams.get("department") ?? ALL_DEPARTMENTS;

  const [draftMonth, setDraftMonth] = useState(appliedMonth);
  const [draftDepartment, setDraftDepartment] = useState(appliedDepartment);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [query, setQuery] = useState("");

  const params = useMemo(
    () => ({
      ...(appliedMonth ? { month: appliedMonth } : {}),
      ...(appliedDepartment !== ALL_DEPARTMENTS ? { department: appliedDepartment } : {}),
    }),
    [appliedMonth, appliedDepartment],
  );

  const { data: report, isLoading, isError, isFetching, refetch } = useReport(reportKey, params);
  const exportMut = useExportReport(reportKey, params);

  // The month select shows the draft until one is picked, then whatever the server resolved to —
  // on a first visit that is the current month, which the URL does not name.
  const monthValue = draftMonth || report?.selected_month?.value || "";
  const dirty = draftMonth !== appliedMonth || draftDepartment !== appliedDepartment;

  function applyFilters() {
    setExpanded(null);

    // Nothing pending — the button re-runs the report rather than sitting dead, which is what a
    // reader coming back to a screen they left open actually wants from it.
    if (!dirty) {
      void refetch();
      return;
    }

    const next = new URLSearchParams();
    if (draftMonth) next.set("month", draftMonth);
    if (draftDepartment !== ALL_DEPARTMENTS) next.set("department", draftDepartment);
    const qs = next.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }

  /** Both Print and Export pull the same file — the report is a register, not a chart. */
  async function onExport() {
    try {
      await exportMut.mutateAsync();
      toast.success("Report downloaded");
    } catch {
      toast.error("Could not export the report.");
    }
  }

  const tabs = report?.tabs ?? [];
  const departments = report?.departments ?? [];
  const months = report?.months ?? [];
  const tabHref = (key: string) => {
    const qs = searchParams.toString();
    return qs ? `/teamora/reports/${key}?${qs}` : `/teamora/reports/${key}`;
  };

  // Search narrows the table only — the metric cards keep describing the whole period, because a
  // headline that moved as you typed a name would be reporting on your search box, not the month.
  const filter = <T extends { name: string; department: string | null }>(rows: T[]): T[] => {
    const q = query.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter(
      (r) => r.name.toLowerCase().includes(q) || (r.department ?? "").toLowerCase().includes(q),
    );
  };

  const toggle = (employeeId: number) =>
    setExpanded((current) => (current === employeeId ? null : employeeId));

  const periodLine = report
    ? [
        report.selected_month?.label,
        report.selected_department,
        `${report.working_days} working days`,
      ]
        .filter(Boolean)
        .join(" · ")
    : "";

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title={report?.title ?? "Attendance Summary"}
        subtitle={report?.description}
        actions={
          <Button variant="outline" asChild>
            <Link href="/teamora/reports">
              <ArrowLeft />
              Back to report library
            </Link>
          </Button>
        }
      />

      {/* Tabs — the three views of the month, each a route of its own. */}
      {tabs.length > 0 && (
        <div className="mt-6 grid gap-4 lg:grid-cols-3">
          {tabs.map((tab) => {
            const isActive = tab.key === (report?.active_tab ?? reportKey);

            return (
              <Link
                key={tab.key}
                href={tabHref(tab.key)}
                aria-current={isActive ? "page" : undefined}
                className={cn(
                  "rounded-card border px-5 py-4 transition-colors",
                  isActive
                    ? "border-brand bg-brand-subtle"
                    : "border-border bg-card hover:border-brand/60",
                )}
              >
                <p className="font-bold text-foreground">{tab.title}</p>
                <p className="mt-1 text-caption text-muted-foreground">{tab.description}</p>
              </Link>
            );
          })}
        </div>
      )}

      {/* Filters */}
      <div className="mt-5 flex flex-wrap items-center gap-3">
        <label htmlFor="att-month" className="text-caption font-medium text-muted-foreground">
          Month
        </label>
        <SimpleSelect
          id="att-month"
          className="w-44"
          value={monthValue || undefined}
          onValueChange={setDraftMonth}
          placeholder="Select month"
          options={months.map((m) => ({ value: m.value, label: m.label }))}
        />

        <label htmlFor="att-dept" className="text-caption font-medium text-muted-foreground">
          Department
        </label>
        <SimpleSelect
          id="att-dept"
          className="w-52"
          value={draftDepartment}
          onValueChange={setDraftDepartment}
          options={[
            { value: ALL_DEPARTMENTS, label: "All departments" },
            ...departments.map((d) => ({ value: String(d.id), label: d.name })),
          ]}
        />

        <Button onClick={applyFilters} disabled={isFetching}>
          {isFetching ? "Generating…" : "Generate report"}
        </Button>

        <div className="ml-auto flex items-center gap-2">
          <Button
            variant="outline"
            onClick={onExport}
            disabled={exportMut.isPending || !report}
            title="Downloads the report as a formatted spreadsheet, under the company letterhead."
          >
            <Printer />
            Print
          </Button>
          <Button variant="outline" onClick={onExport} disabled={exportMut.isPending || !report}>
            <Download />
            {exportMut.isPending ? "Exporting…" : "Export"}
          </Button>
        </div>
      </div>

      {isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading report…</p>
      ) : isError || !report ? (
        <p className="mt-6 text-sm text-destructive">Could not load this report.</p>
      ) : (
        <>
          {/* Metrics */}
          <div className="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {report.summary.map((metric) => (
              <StatCard key={metric.label} label={metric.label} value={metric.value} />
            ))}
          </div>

          {report.month_in_progress && (
            <p className="mt-3 text-caption text-muted-foreground">
              {report.selected_month?.label} is still in progress — figures cover the{" "}
              {report.elapsed_working_days} working day
              {report.elapsed_working_days === 1 ? "" : "s"} elapsed so far. Today is never counted
              as an absence.
            </p>
          )}

          {/* Table */}
          <div className="mt-5 overflow-hidden rounded-card border border-border bg-card">
            <div className="flex flex-wrap items-start justify-between gap-3 px-6 pb-4 pt-5">
              <div>
                <h2 className="text-h3 font-bold text-foreground">
                  {report.tabs?.find((t) => t.key === report.active_tab)?.title ?? report.title}
                </h2>
                <p className="mt-1 text-caption text-muted-foreground">{periodLine}</p>
              </div>
              <div className="relative min-w-[220px]">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="search"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder="Search employee"
                  aria-label="Search employee"
                  className="h-9 w-full rounded-lg border border-input bg-transparent pl-8 pr-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                />
              </div>
            </div>

            {report.attendance && (
              <AttendanceSummaryTable
                rows={filter(report.attendance)}
                expanded={expanded}
                onToggle={toggle}
                empty={emptyMessage(query, "No attendance in this period.")}
              />
            )}
            {report.dtr && (
              <AttendanceDtrTable
                rows={filter(report.dtr)}
                expanded={expanded}
                onToggle={toggle}
                empty={emptyMessage(query, "No time records in this period.")}
              />
            )}
            {report.leave && (
              <AttendanceLeaveTable
                rows={filter(report.leave)}
                expanded={expanded}
                onToggle={toggle}
                empty={emptyMessage(query, "No employees in this period.")}
              />
            )}
          </div>

          {/* Legend */}
          <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
            <AttendanceLegend />
            <span className="text-caption text-muted-foreground">
              Click a row for the daily breakdown
            </span>
          </div>

          {report.note && <p className="mt-3 text-caption text-muted-foreground">{report.note}</p>}

          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-caption text-muted-foreground">
            <span>Generated {report.generated_at}</span>
            <span>Prepared by: HR Department</span>
          </div>
        </>
      )}
    </div>
  );
}

function emptyMessage(query: string, fallback: string): string {
  return query.trim() ? "No employees match that search." : fallback;
}
