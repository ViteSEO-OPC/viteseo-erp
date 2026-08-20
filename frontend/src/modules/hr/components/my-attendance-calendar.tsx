"use client";

import { useMemo } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { cn } from "@/lib/utils";
import {
  MY_DAY_LEGEND,
  MY_DAY_STATUS,
  dayHighlightLine,
} from "@/modules/hr/lib/my-attendance-display";
import type { MyAttendanceDay } from "@/modules/hr/types/my-attendance";

const WEEKDAYS = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];

/**
 * The selected month as a calendar, each day coloured by how it read — the same shape as the
 * time-off calendar on Work Schedule, so the two months line up visually.
 */
export function MyAttendanceCalendar({
  month,
  days,
  today,
  onShiftMonth,
}: {
  /** 'YYYY-MM' — the month the report was built for. */
  month: string;
  days: MyAttendanceDay[];
  today: string;
  onShiftMonth: (delta: number) => void;
}) {
  const [year, monthIndex] = useMemo(() => {
    const [y, m] = month.split("-").map(Number);
    return [y, m - 1];
  }, [month]);

  const byDate = useMemo(() => new Map(days.map((d) => [d.date, d])), [days]);

  // The 6×7 grid (Sunday-first), including the adjacent months' spill days.
  const cells = useMemo(() => {
    const first = new Date(year, monthIndex, 1);
    const gridStart = new Date(year, monthIndex, 1 - first.getDay());
    return Array.from({ length: 42 }, (_, i) => {
      const d = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(
        d.getDate(),
      ).padStart(2, "0")}`;
      return { key, day: d.getDate(), inMonth: d.getMonth() === monthIndex };
    });
  }, [year, monthIndex]);

  const monthLabel = new Date(year, monthIndex, 1).toLocaleDateString("en-US", {
    month: "long",
    year: "numeric",
  });

  // Only the statuses this month actually contains, so the legend describes what is on screen.
  const legend = useMemo(() => {
    const present = new Set(days.map((d) => d.status));
    return MY_DAY_LEGEND.filter(
      (status) =>
        present.has(status) ||
        (status === "suspended_paid" && present.has("suspended_unpaid")),
    );
  }, [days]);

  return (
    // `h-full` + a stretching grid, so the weeks fill the card instead of leaving a band of
    // empty space under the last one when the table beside it is taller. Below `xl` the two
    // stack and `h-full` resolves to the content height, so nothing is stretched on a phone.
    <div className="flex h-full flex-col rounded-card border border-border bg-card p-6">
      <div className="flex items-center justify-between">
        <h2 className="text-h3 font-bold text-foreground">{monthLabel}</h2>
        <div className="flex gap-1">
          <NavButton label="Previous month" onClick={() => onShiftMonth(-1)}>
            <ChevronLeft className="size-4" />
          </NavButton>
          <NavButton label="Next month" onClick={() => onShiftMonth(1)}>
            <ChevronRight className="size-4" />
          </NavButton>
        </div>
      </div>

      <div className="mt-4 grid flex-1 grid-cols-7 grid-rows-[auto_repeat(6,minmax(0,1fr))] gap-1.5 text-center">
        {WEEKDAYS.map((w) => (
          <div
            key={w}
            className="text-label font-bold uppercase tracking-wide text-muted-foreground"
          >
            {w}
          </div>
        ))}
        {cells.map((cell) => {
          const day = cell.inMonth ? byDate.get(cell.key) : undefined;
          const meta = day ? MY_DAY_STATUS[day.status] : null;
          const highlights = day ? dayHighlightLine(day) : "";
          const isToday = cell.key === today;

          return (
            <div key={cell.key} className="flex items-center justify-center">
              <span
                title={
                  day
                    ? [`${day.date} (${day.weekday}) — ${MY_DAY_STATUS[day.status].label}`, highlights]
                        .filter(Boolean)
                        .join(" · ")
                    : undefined
                }
                className={cn(
                  // Capped, not free-growing: past ~4rem the numbers start swimming in their
                  // own chip on a tall viewport.
                  "relative flex h-full max-h-16 min-h-11 w-11 flex-col items-center justify-center rounded-lg text-sm font-semibold",
                  // Spill days recede whether or not they carry a mark, so the month being
                  // viewed is the one that reads loudest.
                  !cell.inMonth && "text-muted-foreground/50",
                  meta ? meta.chip : cell.inMonth ? "text-foreground" : "",
                  isToday && "ring-2 ring-brand ring-offset-1 ring-offset-card",
                )}
              >
                {cell.day}
                {/*
                 * A dot for the minute-level facts a colour cannot carry: this day was late,
                 * ran short, or earned overtime on top of whatever its status says.
                 */}
                {day && (day.late_minutes > 0 || day.undertime_minutes > 0 || day.overtime_minutes > 0) && (
                  <span className="mt-0.5 flex gap-0.5">
                    {day.late_minutes > 0 && <Pip className="bg-warning" />}
                    {day.undertime_minutes > 0 && <Pip className="bg-severity-orange" />}
                    {day.overtime_minutes > 0 && <Pip className="bg-info" />}
                  </span>
                )}
              </span>
            </div>
          );
        })}
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-border pt-3">
        {legend.map((status) => (
          <span
            key={status}
            className="inline-flex items-center gap-1.5 text-caption text-muted-foreground"
          >
            <span className={cn("size-2.5 rounded-full", MY_DAY_STATUS[status].dot)} />
            {MY_DAY_STATUS[status].label}
          </span>
        ))}
        <span className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
          <Pip className="bg-warning" /> Late
        </span>
        <span className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
          <Pip className="bg-severity-orange" /> Undertime
        </span>
        <span className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
          <Pip className="bg-info" /> Overtime
        </span>
      </div>
    </div>
  );
}

function Pip({ className }: { className: string }) {
  return <span className={cn("inline-block size-1.5 rounded-full", className)} />;
}

function NavButton({
  children,
  label,
  onClick,
}: {
  children: React.ReactNode;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className="inline-flex size-7 items-center justify-center rounded-lg border border-input text-muted-foreground transition-colors hover:bg-muted"
    >
      {children}
    </button>
  );
}
