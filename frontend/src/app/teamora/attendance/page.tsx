"use client";

import { useMemo, useState } from "react";
import { Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import { PageHeader } from "@/core/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { DataTable, type Column } from "@/core/components/data-table";
import { cn } from "@/lib/utils";
import { formatTimeManila, formatDate } from "@/core/lib/format";
import {
  useHrAttendance,
  useAttendanceSummary,
} from "@/modules/hr/hooks/use-attendance";
import { useLeaveTypes } from "@/modules/hr/hooks/use-leave-config";
import { AttendanceAdjustDialog } from "@/modules/hr/components/attendance-adjust-dialog";
import { AttendanceMeetingDialog } from "@/modules/hr/components/attendance-meeting-dialog";
import type { HrAttendanceRow, Severity } from "@/modules/hr/types/attendance";

function manilaToday(): string {
  return new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Manila" });
}

type AttSummaryCounts = {
  checked_in: number; not_checked_in: number; on_leave: number;
  early_arrivals: number; late_arrivals: number; on_time: number;
  total_employees: number; absent: number;
};

// Solid dot classes per severity (legend: green/yellow/orange/red).
const SEVERITY: Record<Severity, { dot: string }> = {
  green: { dot: "bg-success" },
  yellow: { dot: "bg-warning" },
  orange: { dot: "bg-severity-orange" },
  red: { dot: "bg-danger" },
};

const TILES: { key: keyof AttSummaryCounts; label: string; tint: string }[] = [
  { key: "checked_in", label: "Checked in", tint: "border-success/40 bg-success-subtle" },
  { key: "not_checked_in", label: "Not checked in", tint: "border-danger/40 bg-danger-subtle" },
  { key: "on_leave", label: "On leave", tint: "border-info/40 bg-info-subtle" },
  { key: "early_arrivals", label: "Early arrivals", tint: "border-success/40 bg-success-subtle" },
  { key: "late_arrivals", label: "Late arrivals", tint: "border-warning/40 bg-warning-subtle" },
  { key: "on_time", label: "On time", tint: "border-info/40 bg-info-subtle" },
  { key: "total_employees", label: "Total employees", tint: "" },
  { key: "absent", label: "Absent", tint: "border-danger/40 bg-danger-subtle" },
];

// Soft status pill (matches the design's subtle badges).
const STATUS_PILL: Record<string, { cls: string; label: string }> = {
  present: { cls: "bg-success-subtle text-success", label: "Checked in" },
  late: { cls: "bg-danger-subtle text-danger", label: "Late" },
  absent: { cls: "bg-danger-subtle text-danger", label: "Absent" },
  not_checked_in: { cls: "bg-muted text-muted-foreground", label: "Not checked in" },
};

function StatusPill({ row }: { row: HrAttendanceRow }) {
  const s =
    row.status === "on_leave"
      ? {
          cls: "bg-warning-subtle text-warning",
          label: row.leave_type ? `On leave - ${row.leave_type}` : "On leave",
        }
      : (STATUS_PILL[row.status] ?? {
          cls: "bg-muted text-muted-foreground",
          label: row.status.replace(/_/g, " "),
        });
  return (
    <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", s.cls)}>
      {s.label}
    </span>
  );
}

// A single severity dot in the Alert column (display only — open the row for details).
function AlertDot({ row }: { row: HrAttendanceRow }) {
  const severity: Severity = row.flag ? row.flag.severity : row.first_in ? "green" : "red";
  return (
    <span
      className={cn("inline-block size-2.5 rounded-full", SEVERITY[severity].dot)}
      title={row.flag ? row.flag.types.join(", ") : undefined}
    />
  );
}

export default function AttendancePage() {
  const [date, setDate] = useState(manilaToday());
  const { data: summary } = useAttendanceSummary(date);
  const { data: rows, isLoading, isError } = useHrAttendance(date);
  const { data: leaveTypes } = useLeaveTypes();

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
    { header: "Status", cell: (r) => <StatusPill row={r} /> },
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
    <div className="p-8">
      <PageHeader
        title="Attendance"
        subtitle="Daily check-in status, timing, and violation flags — click a row to correct it."
        actions={
          <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="w-auto" />
        }
      />

      {/* 8 summary tiles */}
      <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        {TILES.map((t) => (
          <StatCard
            key={t.key}
            label={t.label}
            value={summary ? summary[t.key] : "—"}
            className={t.tint}
          />
        ))}
      </div>

      {/* Leave-type legend */}
      {leaveTypes && leaveTypes.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <span className="text-label font-bold uppercase tracking-wide text-muted-foreground">Leave types</span>
          {leaveTypes.map((lt) => (
            <span key={lt.id} className="inline-flex items-center gap-1.5 text-xs text-foreground">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: lt.color ?? "#94a3b8" }} />
              {lt.name}
            </span>
          ))}
        </div>
      )}

      {/* Filter bar */}
      <div className="mt-4 flex flex-wrap items-center gap-2">
        <div className="relative w-52">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search employee"
            className="h-9 w-full rounded-lg border border-border bg-card pl-9 pr-3 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
        </div>
        <select
          className="h-9 rounded-lg border border-border bg-card px-3 text-sm text-foreground"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
        >
          <option value="all">All status</option>
          <option value="present">Present</option>
          <option value="late">Late</option>
          <option value="absent">Absent</option>
          <option value="on_leave">On leave</option>
          <option value="not_checked_in">Not checked in</option>
        </select>
        <select
          className="h-9 rounded-lg border border-border bg-card px-3 text-sm text-foreground"
          value={timingFilter}
          onChange={(e) => setTimingFilter(e.target.value)}
        >
          <option value="all">All timing</option>
          <option value="early">Early</option>
          <option value="on_time">On time</option>
          <option value="late">Late</option>
        </select>
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
