"use client";

import { useMemo, useState } from "react";
import { Search } from "lucide-react";

import { PageHeader } from "@/modules/hr/components/page-header";
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
import {
  SoftBadge,
  deriveAttendanceStatus,
  deriveAttendanceTiming,
} from "@/modules/hr/lib/attendance-display";
import type { HrAttendanceRow, Severity } from "@/modules/hr/types/attendance";
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

export default function AttendancePage() {
  const [date, setDate] = useState(manilaToday());
  const { data: summary } = useAttendanceSummary(date);
  const { data: rows, isLoading, isError } = useHrAttendance(date);

  const [editing, setEditing] = useState<HrAttendanceRow | null>(null);
  const [scheduling, setScheduling] = useState<HrAttendanceRow | null>(null);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [timingFilter, setTimingFilter] = useState("all");

  const filtered = useMemo(() => {
    return (rows ?? []).filter((r) => {
      if (search && !(r.name ?? "").toLowerCase().includes(search.toLowerCase())) return false;
      if (statusFilter !== "all" && r.status !== statusFilter) return false;
      if (timingFilter !== "all" && r.timing?.category !== timingFilter) return false;
      return true;
    });
  }, [rows, search, statusFilter, timingFilter]);

  const columns: Column<HrAttendanceRow>[] = [
    {
      header: "Employee",
      cell: (r) => <span className="font-semibold text-foreground">{r.name ?? "—"}</span>,
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

      {/* Off-site time-ins awaiting review. Renders nothing when the queue is empty, so
          this costs no space on a normal day. Above the roster because the people in it
          are already working and aren't on the clock yet. */}
      <div className="mt-4 empty:mt-0">
        <FieldTimeInQueue />
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
            empty="No attendance matches these filters."
          />
        )}
      </div>

      <AttendanceAdjustDialog
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        row={editing}
        onScheduleMeeting={() => { setScheduling(editing); setEditing(null); }}
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
