"use client";

import { useState, useMemo } from "react";
import { Search } from "lucide-react";

import { Avatar } from "@/core/components/avatar";
import { DataTable, type Column } from "@/core/components/data-table";
import { formatTimeManila } from "@/core/lib/format";
import { useAttendanceSummary, useHrAttendance } from "@/modules/hr/hooks/use-attendance";
import { useLeaveTypes } from "@/modules/hr/hooks/use-leave";
import { GracePeriodButton } from "@/modules/hr/components/grace-period-button";
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
      <div className="flex items-center gap-2.5">
        <Avatar name={r.name ?? ""} colorSeed={r.employee_id} size="sm" />
        <span className="font-semibold text-foreground">{r.name ?? "—"}</span>
      </div>
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

export function CheckinBoard() {
  const { data: summary } = useAttendanceSummary();
  const { data: roster, isLoading: rosterLoading } = useHrAttendance();
  const { data: leaveTypes } = useLeaveTypes();

  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [timingFilter, setTimingFilter] = useState("all");

  const filteredRoster = useMemo(() => {
    if (!roster) return [];
    return roster.filter((r) => {
      const name = (r.name ?? "").toLowerCase();
      if (query && !name.includes(query.trim().toLowerCase())) return false;
      if (statusFilter !== "all") {
        if (statusFilter === "checked_in" && !r.time_in) return false;
        if (statusFilter === "on_leave" && !r.leave_type) return false;
        if (statusFilter === "absent" && r.status !== "absent") return false;
      }
      if (timingFilter !== "all") {
        const tm = deriveAttendanceTiming(r);
        const cat = tm?.label.toLowerCase() ?? "";
        if (timingFilter === "on_time" && !cat.includes("on time") && !cat.includes("early")) return false;
        if (timingFilter === "late" && !cat.includes("late")) return false;
      }
      return true;
    });
  }, [roster, query, statusFilter, timingFilter]);

  const emptyMessage = rosterLoading
    ? "Loading…"
    : roster && roster.length === 0
      ? "No attendance records for today."
      : `No employees match "${query}".`;

  return (
    <section>
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-h3 font-bold text-foreground">Employee Check-in Dashboard</h2>
        <GracePeriodButton />
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
          pageSize={10}
        />
      </div>
    </section>
  );
}
