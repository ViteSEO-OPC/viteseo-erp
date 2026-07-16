"use client";

import { CalendarCheck, UserPlus, FileText, Receipt } from "lucide-react";

import { StatCard } from "@/core/components/stat-card";
import { formatDate, formatRelative } from "@/core/lib/format";
import { AttendanceTrendChart } from "@/modules/hr/components/attendance-trend-chart";
import { CheckinBoard } from "@/modules/hr/components/checkin-board";
import { PendingApprovals } from "@/modules/hr/components/pending-approvals";
import { useHrDashboard } from "@/modules/hr/hooks/use-dashboard";
import type { ActivityItem } from "@/modules/hr/types/dashboard";

// Each activity type gets an icon and a circle colour drawn from the status tokens.
const ACTIVITY_STYLE = {
  leave: { Icon: CalendarCheck, circle: "bg-success" },
  reimbursement: { Icon: Receipt, circle: "bg-info" },
  hire: { Icon: UserPlus, circle: "bg-success" },
} as const;

export default function HrDashboardPage() {
  const { data: stats, isLoading } = useHrDashboard();

  return (
    <div className="space-y-8 p-8">
      {/* Section A — Employee check-in (live attendance data) */}
      <CheckinBoard />

      {/* Section B — Summary & Analytics (real aggregate reads) */}
      <section className="border-t border-border pt-8">
        <h2 className="text-h3 font-bold text-foreground">Summary &amp; Analytics</h2>

        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard label="Headcount" value={stats?.headcount ?? "—"} subNote="Active employees" />
          <StatCard label="On Leave Today" value={stats?.on_leave_today ?? "—"} subNote="Approved leave covering today" />
          <StatCard label="Open Roles" value={stats?.open_roles ?? "—"} subNote="Recruitment pipeline" />
          <StatCard
            label="Next Payroll"
            value={stats ? formatDate(stats.next_payroll_date) : "—"}
            subNote="Upcoming cycle"
          />
        </div>

        {/* Trend chart */}
        <div className="mt-6 rounded-card border border-border bg-card p-6">
          <AttendanceTrendChart data={stats?.attendance_trend ?? []} />
        </div>

        {/* Approvals + recent activity */}
        <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
          <PendingApprovals />

          <div className="rounded-card border border-border bg-card p-6">
            <h3 className="text-h3 font-bold text-foreground">Recent Activity</h3>
            <div className="mt-4 h-80 space-y-4 overflow-y-auto pr-1">
              {isLoading ? (
                <p className="py-4 text-sm text-muted-foreground">Loading…</p>
              ) : (stats?.recent_activity ?? []).length === 0 ? (
                <p className="py-4 text-sm text-muted-foreground">No recent activity.</p>
              ) : (
                stats?.recent_activity.map((a: ActivityItem, i) => {
                  const style = ACTIVITY_STYLE[a.type];
                  const Icon = style?.Icon ?? FileText;
                  return (
                    <div
                      key={i}
                      className={`flex items-start gap-3.5 ${i > 0 ? "border-t border-border pt-4" : ""}`}
                    >
                      <span
                        className={`flex size-8 shrink-0 items-center justify-center rounded-full text-white ${style?.circle ?? "bg-muted-foreground"}`}
                      >
                        <Icon className="size-4" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium text-foreground">{a.text}</p>
                        <p className="mt-0.5 text-caption text-muted-foreground">{formatRelative(a.at)}</p>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
