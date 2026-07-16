import { cn } from "@/lib/utils";
import { formatLateDuration } from "@/core/lib/format";
import type { HrAttendanceRow } from "@/modules/hr/types/attendance";

// Shared Status (presence) + Timing (punctuality) derivation and badge — used by BOTH
// the HR dashboard check-in board and the attendance page, so the two tables read
// identically and can't drift apart.

export type Tone = "success" | "danger" | "info" | "warning" | "muted";

export const BADGE_TONE: Record<Tone, string> = {
  success: "bg-success-subtle text-success",
  danger: "bg-danger-subtle text-danger",
  info: "bg-info-subtle text-info",
  warning: "bg-warning-subtle text-warning",
  muted: "bg-muted text-muted-foreground",
};

export function SoftBadge({ tone, children }: { tone: Tone; children: React.ReactNode }) {
  return (
    <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", BADGE_TONE[tone])}>
      {children}
    </span>
  );
}

/**
 * Presence — did the employee show up? Checked in / Not checked in / Absent /
 * On leave / Rest day. Punctuality lives in {@link deriveAttendanceTiming}, not here.
 */
export function deriveAttendanceStatus(row: HrAttendanceRow): { label: string; tone: Tone } {
  if (row.leave_type) return { label: `On leave · ${row.leave_type}`, tone: "warning" };
  if (row.time_in) return { label: "Checked in", tone: "success" };
  if (row.status === "absent") return { label: "Absent", tone: "danger" };
  if (row.status === "rest_day") return { label: "Rest day", tone: "muted" };
  return { label: "Not checked in", tone: "muted" };
}

/**
 * Punctuality relative to the employee's schedule: Early / On time / Late 1h 15m.
 * Returns null when there's no clock-in to judge (shown as "—").
 */
export function deriveAttendanceTiming(row: HrAttendanceRow): { label: string; tone: Tone } | null {
  if (!row.timing) return null;
  if (row.timing.category === "early") return { label: "Early", tone: "info" };
  if (row.timing.category === "on_time") return { label: "On time", tone: "info" };
  const dur = formatLateDuration(row.timing.late_minutes);
  return { label: dur ? `Late ${dur}` : "Late", tone: "danger" };
}
