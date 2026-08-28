"use client";


import { EmployeeCell } from "@/modules/hr/components/employee-cell";
import type { StatusFilterOption } from "@/core/components/status-filter";
import { useServerTable } from "@/modules/hr/hooks/use-server-table";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatTimeManila } from "@/core/lib/format";
import { useAttendanceSummary, useHrAttendance } from "@/modules/hr/hooks/use-attendance";
import { useLeaveTypes } from "@/modules/hr/hooks/use-leave";
import { DayRulesButton } from "@/modules/hr/components/day-rules-button";
import type { HrAttendanceRow } from "@/modules/hr/types/attendance";
import {
  SoftBadge,
  deriveAttendanceStatus,
  deriveAttendanceTiming,
} from "@/modules/hr/lib/attendance-display";
import { AttendanceSummaryTiles } from "@/modules/hr/components/attendance-summary-tiles";
import { LeaveTypeLegend } from "@/modules/hr/components/leave-type-legend";

const columns: Column<HrAttendanceRow>[] = [
  {
    header: "Employee",
    cell: (r) => (
      <EmployeeCell
        employee={{ id: r.employee_id, name: r.name, photo_url: r.photo_url }}
        displayName={r.list_name}
      />
    ),
  },
  { header: "Dept", cell: (r) => r.department ?? "—", className: "text-muted-foreground" },
  {
    header: "Status",
    cell: (r) => {
      const s = deriveAttendanceStatus(r);
      return <SoftBadge tone={s.tone}>{s.label}</SoftBadge>;
    },
  },
  { header: "First in", cell: (r) => formatTimeManila(r.first_in) },
  { header: "Last out", cell: (r) => formatTimeManila(r.last_out), className: "text-muted-foreground" },
  {
    header: "Timing",
    cell: (r) => {
      const t = deriveAttendanceTiming(r);
      return t ? <SoftBadge tone={t.tone}>{t.label}</SoftBadge> : "—";
    },
  },
];

/** Curated: server mode cannot read the vocabulary off one page of rows. */
const ATTENDANCE_STATUS_OPTIONS: StatusFilterOption[] = [
  { value: "all", label: "All" },
  { value: "present", label: "Present" },
  { value: "late", label: "Late" },
  { value: "absent", label: "Absent" },
  { value: "on_leave", label: "On leave" },
  { value: "not_checked_in", label: "Not checked in" },
];

export function CheckinBoard() {
  const { data: summary } = useAttendanceSummary();
  // Server-paged and server-filtered, like the attendance board it mirrors.
  //
  // Its three filter states below were declared, fed into a client-side filter, and never
  // rendered — no control existed for any of them, so all three sat at their defaults and the
  // filter was a no-op. The table supplies a real search box and status pills now, and both
  // reach the query.
  const table = useServerTable(25);
  const { data: rosterPage, isLoading: rosterLoading, isFetching } = useHrAttendance(
    undefined,
    table.params,
  );
  const roster = rosterPage?.items;
  const { data: leaveTypes } = useLeaveTypes();


  const filteredRoster = roster ?? [];


  const emptyMessage = rosterLoading
    ? "Loading…"
    : "No attendance records for today.";

  return (
    <section>
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-h3 font-bold text-foreground">Employee Check-in Dashboard</h2>
        <DayRulesButton />
      </div>

      {/* Stat tiles (4×2) */}
      <div className="mt-4">
        <AttendanceSummaryTiles summary={summary} />
      </div>

      {/* Leave-type legend */}
      <div className="mt-6">
        <LeaveTypeLegend leaveTypes={leaveTypes} />
      </div>

      {/* Check-in table */}
      <div className="mt-3">
        <DataTable
          columns={columns}
          rows={filteredRoster}
          rowKey={(r) => r.employee_id}
          empty={emptyMessage}
          pageSize={table.pageSize}
          searchPlaceholder="Search employee…"
          server={table.serverProps(rosterPage?.total ?? 0, isFetching, ATTENDANCE_STATUS_OPTIONS)}
        />
      </div>
    </section>
  );
}
