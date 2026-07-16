import { cn } from "@/lib/utils";
import type { Tone } from "@/modules/hr/lib/attendance-display";
import type { AttendanceSummary } from "@/modules/hr/types/attendance";

// The 8 attendance summary tiles, shared by the HR dashboard and the attendance page
// so both read identically. Styled per the dashboard's subtle tinted tiles.

const TILE_TONE: Record<Tone, string> = {
  success: "bg-success-subtle text-success",
  danger: "bg-danger-subtle text-danger",
  info: "bg-info-subtle text-info",
  warning: "bg-warning-subtle text-warning",
  muted: "bg-muted text-foreground",
};

type CountKey = Exclude<keyof AttendanceSummary, "date">;

const TILES: { key: CountKey; label: string; tone: Tone }[] = [
  { key: "checked_in", label: "Checked in", tone: "success" },
  { key: "not_checked_in", label: "Not checked in", tone: "danger" },
  { key: "on_leave", label: "On leave", tone: "info" },
  { key: "early_arrivals", label: "Early arrivals", tone: "success" },
  { key: "late_arrivals", label: "Late arrivals", tone: "warning" },
  { key: "on_time", label: "On time", tone: "info" },
  { key: "total_employees", label: "Total employees", tone: "muted" },
  { key: "absent", label: "Absent", tone: "danger" },
];

export function AttendanceSummaryTiles({ summary }: { summary?: AttendanceSummary }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {TILES.map((t) => (
        <div key={t.key} className={cn("rounded-card p-4", TILE_TONE[t.tone])}>
          <p className="text-h2 font-bold leading-none">{summary ? summary[t.key] : "…"}</p>
          <p className="mt-1.5 text-caption font-medium text-foreground/70">{t.label}</p>
        </div>
      ))}
    </div>
  );
}
