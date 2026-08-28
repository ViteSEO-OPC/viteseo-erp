"use client";

import {useState} from "react";

import { PageHeader } from "@/modules/hr/components/page-header";
import type { StatusFilterOption } from "@/core/components/status-filter";
import { useServerTable } from "@/modules/hr/hooks/use-server-table";
import { DataTable, type Column } from "@/core/components/data-table";
import { cn } from "@/lib/utils";
import { formatTimeManila, formatDate } from "@/core/lib/format";
import {
  useHrAttendance,
  useAttendanceSummary,
} from "@/modules/hr/hooks/use-attendance";
import { AttendanceAdjustDialog } from "@/modules/hr/components/attendance-adjust-dialog";
import { AttendanceMeetingDialog } from "@/modules/hr/components/attendance-meeting-dialog";
import { AttendanceSummaryTiles } from "@/modules/hr/components/attendance-summary-tiles";
import { FieldTimeInQueue } from "@/modules/hr/components/field-timein-queue";
import { OpenShiftQueue } from "@/modules/hr/components/open-shift-queue";
import { ManagementGenerationCard } from "@/modules/hr/components/management-generation-card";
import {
  SoftBadge,
  deriveAttendanceStatus,
  deriveAttendanceTiming,
} from "@/modules/hr/lib/attendance-display";
import type { HrAttendanceRow, OpenShiftRow, Severity } from "@/modules/hr/types/attendance";
import { DatePicker } from "@/modules/hr/ui/date-picker";

function manilaToday(): string {
  return new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
}

// Solid dot classes per severity (legend: green/yellow/orange/red).
const SEVERITY: Record<Severity, { dot: string }> = {
  green: { dot: "bg-success" },
  yellow: { dot: "bg-warning" },
  orange: { dot: "bg-severity-orange" },
  red: { dot: "bg-danger" },
};

// A single severity dot in the Alert column (display only — open the row for details).
// Mirrors the detail modal's alert level exactly: the worst unresolved violation's
// severity, or green (no alert) when there's no flag. Whether the employee has clocked
// in is a separate concern shown in the Status column, not an alert.
function AlertDot({ row }: { row: HrAttendanceRow }) {
  const severity: Severity = row.flag?.severity ?? "green";
  return (
    <span
      className={cn("inline-block size-2.5 rounded-full", SEVERITY[severity].dot)}
      title={row.flag ? row.flag.types.join(", ") : "No violations"}
    />
  );
}

/**
 * The roster statuses, curated. Server mode cannot read them off the rows — those are one page.
 *
 * The API also accepts a `timing` filter (early / on_time / late), derived the same way, which
 * no control on this screen offers yet.
 */
const ATTENDANCE_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "present", label: "Present" },
  { value: "late", label: "Late" },
  { value: "absent", label: "Absent" },
  { value: "on_leave", label: "On leave" },
  { value: "not_checked_in", label: "Not checked in" },
];

export default function AttendancePage() {
  const [date, setDate] = useState(manilaToday());
  const { data: summary } = useAttendanceSummary(date);
  // Server-paged, and server-FILTERED. The status and timing controls below are not columns —
  // each row's status is derived from the employee's schedule and the day — so the server
  // filters after deriving and before cutting the page. Applying either here would have narrowed
  // the twenty-five rows on screen and called it the roster.
  //
  // The board also GAINS filtering it never had: the previous version declared search, status
  // and timing state, fed them into a client-side filter, and rendered no control for any of
  // them — so all three sat at their defaults and the filter was a no-op. The table supplies a
  // real search box and status pills now, and both reach the query.
  const table = useServerTable(25);
  const { data, isLoading, isError, isFetching } = useHrAttendance(date, table.params);
  const filtered = data?.items ?? [];

  const [editing, setEditing] = useState<HrAttendanceRow | null>(null);
  const [scheduling, setScheduling] = useState<HrAttendanceRow | null>(null);

  /**
   * An open shift the review queue asked us to correct. The queue spans many dates while this
   * page shows one, so fixing a row moves the whole page to that date and reuses the ordinary
   * correction dialog — corrections have to work the same way whichever list started them.
   */
  const [pendingFix, setPendingFix] = useState<OpenShiftRow | null>(null);


  /**
   * The row the correction dialog is on. DERIVED rather than stashed by an effect: the queue's
   * pick can't be resolved until the roster for its date has loaded, and waiting for that in an
   * effect is a render cascade for an answer that is already available one pass earlier.
   */
  const editingRow =
    editing ??
    (pendingFix && pendingFix.work_date === date
      ? (filtered.find((r) => r.employee_id === pendingFix.employee_id) ?? null)
      : null);

  const closeEditor = () => {
    setEditing(null);
    setPendingFix(null);
  };


  const columns: Column<HrAttendanceRow>[] = [
    {
      header: "Employee",
      cell: (r) => (
        <span className="font-semibold text-foreground">{r.list_name ?? r.name ?? "—"}</span>
      ),
    },
    { header: "Dept", cell: (r) => r.department ?? "—" },
    {
      header: "Status",
      cell: (r) => {
        const s = deriveAttendanceStatus(r);
        return <SoftBadge tone={s.tone}>{s.label}</SoftBadge>;
      },
    },
    {
      header: "Timing",
      cell: (r) => {
        const t = deriveAttendanceTiming(r);
        return t ? <SoftBadge tone={t.tone}>{t.label}</SoftBadge> : "—";
      },
    },
    { header: "Date", cell: (r) => formatDate(r.work_date) },
    { header: "Time In", cell: (r) => formatTimeManila(r.first_in) },
    { header: "Time Out", cell: (r) => formatTimeManila(r.last_out) },
    {
      header: "Hours",
      className: "text-center",
      cell: (r) => (
        <span className="font-semibold text-foreground">{Number(r.hours_worked.toFixed(1))}</span>
      ),
    },
    {
      header: "Alert",
      className: "text-center",
      cell: (r) => <AlertDot row={r} />,
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Attendance"
        subtitle="Daily check-in status, timing, and violation flags — click a row to correct it."
        actions={
          <DatePicker value={date} onChange={setDate} max={manilaToday()} className="w-44" />
        }
      />

      {/* 8 summary tiles */}
      <div className="mt-6">
        <AttendanceSummaryTiles summary={summary} />
      </div>

      {/*
       * Off-site time-ins awaiting review. Renders nothing when the queue is empty, so
       * this costs no space on a normal day. Above the roster because the people in it
       * are already working and aren't on the clock yet.
       */}
      <div className="mt-4 empty:mt-0">
        <FieldTimeInQueue />
      </div>

      {/*
       * Forgotten clock-outs. Also above the roster, and for the same reason: these are days
       * whose pay is wrong or unsettled, which outranks reading today's list. Hides itself
       * when nothing needs fixing.
       */}
      <div className="mt-4 empty:mt-0">
        <OpenShiftQueue
          onFix={(r) => {
            setPendingFix(r);
            setDate(r.work_date);
          }}
        />
      </div>

      {/*
       * Management attendance is generated, not punched. Renders nothing when nobody is
       * exempt; below the two queues because a missed nightly is a data-freshness problem,
       * not a person waiting on HR.
       */}
      <div className="mt-4 empty:mt-0">
        <ManagementGenerationCard />
      </div>

      <div className="mt-4">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading attendance…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">Could not load attendance.</p>
        ) : (
          <DataTable
            columns={columns}
            rows={filtered}
            rowKey={(r) => r.employee_id}
            onRowClick={(r) => setEditing(r)}
            pageSize={table.pageSize}
            searchPlaceholder="Search employee…"
            empty="No attendance matches these filters."
            server={table.serverProps(data?.total ?? 0, isFetching, ATTENDANCE_STATUS_OPTIONS)}
          />
        )}
      </div>

      <AttendanceAdjustDialog
        open={!!editingRow}
        onOpenChange={(o) => !o && closeEditor()}
        row={editingRow}
        onScheduleMeeting={() => { setScheduling(editingRow); closeEditor(); }}
      />

      <AttendanceMeetingDialog
        open={!!scheduling}
        onOpenChange={(o) => !o && setScheduling(null)}
        employeeId={scheduling?.employee_id ?? null}
        employeeName={scheduling?.name ?? null}
      />
    </div>
  );
}
