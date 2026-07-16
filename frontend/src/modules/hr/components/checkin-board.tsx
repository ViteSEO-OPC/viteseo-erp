"use client";

import { useState, useMemo } from "react";
import { Search } from "lucide-react";

import { Avatar } from "@/core/components/avatar";
import { DataTable, type Column } from "@/core/components/data-table";
import { cn } from "@/lib/utils";
import { formatTimeManila } from "@/core/lib/format";
import { useAttendanceSummary, useHrAttendance } from "@/modules/hr/hooks/use-attendance";
import { useLeaveTypes } from "@/modules/hr/hooks/use-leave";
import { GracePeriodButton } from "@/modules/hr/components/grace-period-button";
import type { HrAttendanceRow } from "@/modules/hr/types/attendance";

type Tone = "success" | "danger" | "info" | "warning" | "muted";

const TILE_TONE: Record<Tone, string> = {
  success: "bg-success-subtle text-success",
  danger: "bg-danger-subtle text-danger",
  info: "bg-info-subtle text-info",
  warning: "bg-warning-subtle text-warning",
  muted: "bg-muted text-foreground",
};

const BADGE_TONE: Record<Tone, string> = {
  success: "bg-success-subtle text-success",
  danger: "bg-danger-subtle text-danger",
  info: "bg-info-subtle text-info",
  warning: "bg-warning-subtle text-warning",
  muted: "bg-muted text-muted-foreground",
};

const DOT_TONE: Record<Tone, string> = {
  success: "bg-success",
  danger: "bg-danger",
  info: "bg-info",
  warning: "bg-warning",
  muted: "bg-muted-foreground",
};

function SoftBadge({ tone, children }: { tone: Tone; children: React.ReactNode }) {
  return (
    <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", BADGE_TONE[tone])}>
      {children}
    </span>
  );
}

function Tile({ value, label, tone }: { value: React.ReactNode; label: string; tone: Tone }) {
  return (
    <div className={cn("rounded-card p-4", TILE_TONE[tone])}>
      <p className="text-h2 font-bold leading-none">{value}</p>
      <p className="mt-1.5 text-caption font-medium text-foreground/70">{label}</p>
    </div>
  );
}

function deriveStatus(row: HrAttendanceRow): { label: string; tone: Tone } {
  if (row.leave_type) return { label: `On leave · ${row.leave_type}`, tone: "danger" };
  if (row.time_in) return { label: "Checked in", tone: "success" };
  if (row.status === "absent") return { label: "Absent", tone: "danger" };
  return { label: "Not checked in", tone: "muted" };
}

function deriveTiming(row: HrAttendanceRow): { label: string; tone: Tone } | null {
  if (!row.timing) return null;
  if (row.timing.category === "early") return { label: "Early", tone: "info" };
  if (row.timing.category === "on_time") return { label: "On time", tone: "info" };
  return { label: `Late ${row.timing.late_minutes}m`, tone: "danger" };
}

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
      const s = deriveStatus(r);
      return <SoftBadge tone={s.tone}>{s.label}</SoftBadge>;
    },
  },
  { header: "First in", cell: (r) => formatTimeManila(r.first_in) },
  { header: "Last out", cell: (r) => formatTimeManila(r.last_out), className: "text-muted-foreground" },
  {
    header: "Timing",
    cell: (r) => {
      const t = deriveTiming(r);
      return t ? <SoftBadge tone={t.tone}>{t.label}</SoftBadge> : "—";
    },
  },
];

export function CheckinBoard() {
  const { data: summary, isLoading: summaryLoading } = useAttendanceSummary();
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
        const tm = deriveTiming(r);
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
      <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Tile value={summaryLoading ? "…" : summary?.checked_in ?? 0} label="Checked in" tone="success" />
        <Tile value={summaryLoading ? "…" : summary?.not_checked_in ?? 0} label="Not checked in" tone="danger" />
        <Tile value={summaryLoading ? "…" : summary?.on_leave ?? 0} label="On leave" tone="info" />
        <Tile value={summaryLoading ? "…" : summary?.early_arrivals ?? 0} label="Early arrivals" tone="success" />
        <Tile value={summaryLoading ? "…" : summary?.late_arrivals ?? 0} label="Late arrivals" tone="warning" />
        <Tile value={summaryLoading ? "…" : summary?.on_time ?? 0} label="On time" tone="info" />
        <Tile value={summaryLoading ? "…" : summary?.total_employees ?? 0} label="Total employees" tone="muted" />
        <Tile value={summaryLoading ? "…" : summary?.absent ?? 0} label="Absent" tone="danger" />
      </div>

      {/* Leave-type legend */}
      <p className="mt-6 text-label font-bold uppercase tracking-wide text-muted-foreground">Leave types</p>
      <div className="mt-2 flex flex-wrap gap-2">
        {(leaveTypes ?? []).map((lt) => (
          <span key={lt.id} className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground">
            <span className="size-2 rounded-full" style={{ backgroundColor: lt.color ?? "#6b7280" }} />
            {lt.name}
          </span>
        ))}
      </div>

      {/* Filter bar */}
      <div className="mt-4 flex flex-wrap items-center gap-2">
        <div className="relative w-52">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search employee"
            className="h-9 w-full rounded-lg border border-border bg-card pl-9 pr-3 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
        </div>
        <select
          className="h-9 rounded-lg border border-border bg-card px-3 text-sm text-foreground"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          aria-label="Filter by status"
        >
          <option value="all">All status</option>
          <option value="checked_in">Checked in</option>
          <option value="on_leave">On leave</option>
          <option value="absent">Absent</option>
        </select>
        <select
          className="h-9 rounded-lg border border-border bg-card px-3 text-sm text-foreground"
          value={timingFilter}
          onChange={(e) => setTimingFilter(e.target.value)}
          aria-label="Filter by timing"
        >
          <option value="all">All timing</option>
          <option value="on_time">On time / Early</option>
          <option value="late">Late</option>
        </select>
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
