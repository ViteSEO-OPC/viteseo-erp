"use client";

import { useMemo, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { cn } from "@/lib/utils";
import type {
  LeaveRequest,
  HalfDayRequest,
  EarlyOutRequest,
} from "@/modules/hr/types/leave";

const WEEKDAYS = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];
const HALF_DAY_COLOR = "#F0A608";
const EARLY_OUT_COLOR = "#8B5CF6";

interface DayMark {
  color: string;
  label: string;
  approved: boolean;
}

function ymd(y: number, m: number, d: number): string {
  return `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
}

/**
 * Month calendar of the employee's time off — leave ranges and half-days shown as
 * coloured day pills (approved = solid, pending = outlined), with month navigation
 * and a legend by leave type.
 */
export function TimeOffCalendar({
  leaves,
  halfDays,
  earlyOuts = [],
}: {
  leaves: LeaveRequest[];
  halfDays: HalfDayRequest[];
  earlyOuts?: EarlyOutRequest[];
}) {
  const now = new Date();
  const [view, setView] = useState({ year: now.getFullYear(), month: now.getMonth() });

  // date (YYYY-MM-DD) → mark. Approved wins over pending on the same day.
  const marks = useMemo(() => {
    const map = new Map<string, DayMark>();
    const set = (key: string, mark: DayMark) => {
      const cur = map.get(key);
      if (!cur || (mark.approved && !cur.approved)) map.set(key, mark);
    };
    for (const l of leaves) {
      if (l.status === "rejected") continue;
      const s = new Date(l.start_date + "T00:00:00");
      const e = new Date(l.end_date + "T00:00:00");
      for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
        set(ymd(d.getFullYear(), d.getMonth(), d.getDate()), {
          color: l.leave_type.color ?? "#4FDBD7",
          label: l.leave_type.name,
          approved: l.status === "approved",
        });
      }
    }
    for (const h of halfDays) {
      if (h.status === "rejected") continue;
      set(h.work_date, {
        color: HALF_DAY_COLOR,
        label: `Half-day (${h.period})`,
        approved: h.status === "approved",
      });
    }
    for (const e of earlyOuts) {
      if (e.status === "rejected") continue;
      set(e.work_date, {
        color: EARLY_OUT_COLOR,
        label: `Early-out (${e.early_out_time})`,
        approved: e.status === "approved",
      });
    }
    return map;
  }, [leaves, halfDays, earlyOuts]);

  // Legend — unique {label→color} across all requests.
  const legend = useMemo(() => {
    const m = new Map<string, string>();
    for (const l of leaves) {
      if (l.status !== "rejected") m.set(l.leave_type.name, l.leave_type.color ?? "#4FDBD7");
    }
    if (halfDays.some((h) => h.status !== "rejected")) m.set("Half-day", HALF_DAY_COLOR);
    if (earlyOuts.some((e) => e.status !== "rejected")) m.set("Early-out", EARLY_OUT_COLOR);
    return [...m.entries()];
  }, [leaves, halfDays, earlyOuts]);

  // Build the 6×7 grid (Sunday-first), including adjacent-month spill days.
  const cells = useMemo(() => {
    const first = new Date(view.year, view.month, 1);
    const startOffset = first.getDay(); // 0 = Sunday
    const gridStart = new Date(view.year, view.month, 1 - startOffset);
    return Array.from({ length: 42 }, (_, i) => {
      const d = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
      return {
        key: ymd(d.getFullYear(), d.getMonth(), d.getDate()),
        day: d.getDate(),
        inMonth: d.getMonth() === view.month,
      };
    });
  }, [view]);

  const monthLabel = new Date(view.year, view.month, 1).toLocaleDateString("en-US", {
    month: "long",
    year: "numeric",
  });

  function shift(delta: number) {
    setView((v) => {
      const d = new Date(v.year, v.month + delta, 1);
      return { year: d.getFullYear(), month: d.getMonth() };
    });
  }

  return (
    <div className="rounded-card border border-border bg-card p-6">
      <div className="flex items-center justify-between">
        <h2 className="text-h3 font-bold text-foreground">{monthLabel}</h2>
        <div className="flex gap-1">
          <NavButton label="Previous month" onClick={() => shift(-1)}>
            <ChevronLeft className="size-4" />
          </NavButton>
          <NavButton label="Next month" onClick={() => shift(1)}>
            <ChevronRight className="size-4" />
          </NavButton>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-7 gap-y-2 text-center">
        {WEEKDAYS.map((w) => (
          <div key={w} className="text-label font-bold uppercase tracking-wide text-muted-foreground">
            {w}
          </div>
        ))}
        {cells.map((c) => {
          const mark = marks.get(c.key);
          return (
            <div key={c.key} className="flex items-center justify-center py-0.5">
              <span
                className={cn(
                  "inline-flex size-8 items-center justify-center rounded-full text-sm",
                  !c.inMonth && "text-muted-foreground/50",
                  c.inMonth && !mark && "text-foreground",
                  mark && "font-semibold",
                )}
                style={
                  mark
                    ? mark.approved
                      ? { backgroundColor: mark.color, color: "#0f172a" }
                      : { boxShadow: `inset 0 0 0 1.5px ${mark.color}`, color: mark.color }
                    : undefined
                }
                title={mark?.label}
              >
                {c.day}
              </span>
            </div>
          );
        })}
      </div>

      {legend.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-border pt-3">
          {legend.map(([label, color]) => (
            <span key={label} className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: color }} />
              {label}
            </span>
          ))}
        </div>
      )}
    </div>
  );
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
