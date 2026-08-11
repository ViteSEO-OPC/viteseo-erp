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

type MarkState = "cancelled" | "pending" | "approved";

/**
 * Which request wins a day when several land on it: a live request always beats a
 * cancelled one, so re-filing on a date you'd cancelled restores a normal mark.
 */
const STATE_RANK: Record<MarkState, number> = { cancelled: 1, pending: 2, approved: 3 };

/** A rejected request never reaches the calendar; everything else marks its day. */
function markState(status: string): MarkState | null {
  if (status === "rejected") return null;
  if (status === "approved") return "approved";
  if (status === "cancelled") return "cancelled";
  return "pending";
}

interface DayMark {
  color: string;
  label: string;
  state: MarkState;
}

function ymd(y: number, m: number, d: number): string {
  return `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
}

/**
 * Month calendar of the employee's time off — leave ranges and half-days shown as
 * coloured day pills (approved = solid, pending = outlined, cancelled = struck
 * through), with month navigation and a legend by leave type.
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

  // date (YYYY-MM-DD) → mark, strongest state winning the day.
  const marks = useMemo(() => {
    const map = new Map<string, DayMark>();
    const set = (key: string, mark: DayMark) => {
      const cur = map.get(key);
      if (!cur || STATE_RANK[mark.state] > STATE_RANK[cur.state]) map.set(key, mark);
    };
    const label = (text: string, state: MarkState) =>
      state === "cancelled" ? `${text} · Cancelled` : text;

    for (const l of leaves) {
      const state = markState(l.status);
      if (!state) continue;
      const s = new Date(l.start_date + "T00:00:00");
      const e = new Date(l.end_date + "T00:00:00");
      for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
        set(ymd(d.getFullYear(), d.getMonth(), d.getDate()), {
          color: l.leave_type.color ?? "#4FDBD7",
          label: label(l.leave_type.name, state),
          state,
        });
      }
    }
    for (const h of halfDays) {
      const state = markState(h.status);
      if (!state) continue;
      set(h.work_date, {
        color: HALF_DAY_COLOR,
        label: label(`Half-day (${h.period})`, state),
        state,
      });
    }
    for (const e of earlyOuts) {
      const state = markState(e.status);
      if (!state) continue;
      set(e.work_date, {
        color: EARLY_OUT_COLOR,
        label: label(`Early-out (${e.early_out_time})`, state),
        state,
      });
    }
    return map;
  }, [leaves, halfDays, earlyOuts]);

  // Legend — unique {label→color} across all requests. Cancelled ones get one
  // shared grey entry instead of a colour, matching how their days now render.
  const legend = useMemo(() => {
    const m = new Map<string, string>();
    const isLive = (status: string) => {
      const state = markState(status);
      return state === "approved" || state === "pending";
    };
    const isCancelled = (r: { status: string }) => markState(r.status) === "cancelled";

    for (const l of leaves) {
      if (isLive(l.status)) m.set(l.leave_type.name, l.leave_type.color ?? "#4FDBD7");
    }
    for (const h of halfDays) if (isLive(h.status)) m.set("Half-day", HALF_DAY_COLOR);
    for (const e of earlyOuts) if (isLive(e.status)) m.set("Early-out", EARLY_OUT_COLOR);

    return {
      entries: [...m.entries()],
      hasCancelled:
        leaves.some(isCancelled) ||
        halfDays.some(isCancelled) ||
        earlyOuts.some(isCancelled),
    };
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
          // A cancelled day drops the leave-type colour for a struck-through grey
          // ring, so it can't be read as time off that still stands.
          const cancelled = mark?.state === "cancelled";
          const markStyle = !mark
            ? undefined
            : cancelled
              ? { boxShadow: "inset 0 0 0 1.5px currentColor" }
              : mark.state === "approved"
                ? { backgroundColor: mark.color, color: "#0f172a" }
                : { boxShadow: `inset 0 0 0 1.5px ${mark.color}`, color: mark.color };

          return (
            <div key={c.key} className="flex items-center justify-center py-0.5">
              <span
                className={cn(
                  "inline-flex size-8 items-center justify-center rounded-full text-sm",
                  // Spill days from the neighbouring months recede whether or not
                  // they carry a mark — a mark's inline colours would otherwise
                  // make one shout louder than the month being viewed.
                  !c.inMonth && "opacity-40",
                  !mark && (c.inMonth ? "text-foreground" : "text-muted-foreground"),
                  mark && "font-semibold",
                  cancelled && "text-muted-foreground line-through",
                )}
                style={markStyle}
                title={mark?.label}
              >
                {c.day}
              </span>
            </div>
          );
        })}
      </div>

      {(legend.entries.length > 0 || legend.hasCancelled) && (
        <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-border pt-3">
          {legend.entries.map(([label, color]) => (
            <span key={label} className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: color }} />
              {label}
            </span>
          ))}
          {legend.hasCancelled && (
            <span className="inline-flex items-center gap-1.5 text-caption text-muted-foreground">
              <span
                className="size-2.5 rounded-full"
                style={{ boxShadow: "inset 0 0 0 1.5px currentColor" }}
              />
              <span className="line-through">Cancelled</span>
            </span>
          )}
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
