"use client";

import { useEffect, useMemo, useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { useAuth } from "@/core/auth-context";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { formatTimeManila, formatHm } from "@/core/lib/format";
import {
  useMyAttendance,
  useMyViolations,
  useClockIn,
  useClockOut,
  useToggleBreak,
} from "@/modules/hr/hooks/use-attendance";
import { TimeOutDialog } from "@/modules/hr/components/time-out-dialog";
import { SEVERITY_LABEL, SEVERITY_RANK } from "@/modules/hr/types/attendance";
import type {
  Attendance,
  ClockState,
  DayTask,
  Severity,
  Violation,
} from "@/modules/hr/types/attendance";

// Banner fill per severity — the Figma draws the yellow ("Caution") case; the
// other three reuse the same tokens the HR roster dot already uses.
const BANNER_FILL: Record<Severity, string> = {
  green: "bg-success",
  yellow: "bg-warning",
  orange: "bg-severity-orange",
  red: "bg-danger",
};

const BANNER_MARK: Record<Severity, string> = {
  green: "text-success",
  yellow: "text-warning",
  orange: "text-severity-orange",
  red: "text-danger",
};

/** The employee's own attendance flag, mirroring HR's violation view. */
function AttendanceAlertBanner({ violation }: { violation: Violation }) {
  return (
    <div
      role="status"
      className={cn(
        "flex items-center gap-3 rounded-xl px-4 py-2.5",
        BANNER_FILL[violation.severity],
      )}
    >
      <span
        aria-hidden
        className={cn(
          "flex size-5 shrink-0 items-center justify-center rounded-full bg-white font-bold",
          BANNER_MARK[violation.severity],
        )}
      >
        !
      </span>
      <p className="text-[length:var(--text-label)] whitespace-nowrap text-white">
        <span className="font-bold">{SEVERITY_LABEL[violation.severity]}:</span>{" "}
        {violation.details}
      </p>
    </div>
  );
}

const STATUS_META: Record<ClockState, { label: string; dot: string }> = {
  not_clocked_in: { label: "Clocked out", dot: "bg-danger" },
  clocked_in: { label: "Clocked in", dot: "bg-success" },
  on_lunch: { label: "On lunch", dot: "bg-warning" },
  on_break: { label: "On break", dot: "bg-warning" },
  clocked_out: { label: "Clocked out", dot: "bg-danger" },
};

const WEEK_DAYS = [
  { label: "M", dow: 1 },
  { label: "T", dow: 2 },
  { label: "W", dow: 3 },
  { label: "T", dow: 4 },
  { label: "F", dow: 5 },
];

function liveWorkedMinutes(a: Attendance, now: number): number {
  if (!a.time_in) return 0;
  if (a.state === "clocked_out") return a.hours_worked * 60;
  const gross = (now - new Date(a.time_in).getTime()) / 60000;
  return Math.max(0, gross - a.lunch_minutes - a.break_minutes);
}

export function TimeClockPanel() {
  const { employee } = useAuth();
  const { data, isLoading } = useMyAttendance();
  const clockInMut = useClockIn();
  const clockOutMut = useClockOut();
  const breakMut = useToggleBreak();

  // Live ticking clock (client-side, 1s).
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);

  const today = data?.today ?? null;
  const state: ClockState = today?.state ?? "not_clocked_in";
  const meta = STATUS_META[state];
  const busy = clockInMut.isPending || clockOutMut.isPending || breakMut.isPending;
  const active = state === "clocked_in" || state === "on_lunch" || state === "on_break";

  // The employee's own flag for the current Manila month. Only unresolved,
  // above-green flags surface — a clean record shows no banner — and the worst
  // one wins so a red AWOL is never hidden behind a newer yellow lateness.
  const manilaMonth = new Date()
    .toLocaleDateString("en-CA", { timeZone: "Asia/Manila" })
    .slice(0, 7);
  const { data: myViolations } = useMyViolations(manilaMonth);
  const alertFlag = useMemo(() => {
    const open = (myViolations ?? []).filter(
      (v) => !v.resolved && v.severity !== "green",
    );
    return (
      [...open].sort(
        (a, b) =>
          SEVERITY_RANK[b.severity] - SEVERITY_RANK[a.severity] ||
          b.work_date.localeCompare(a.work_date),
      )[0] ?? null
    );
  }, [myViolations]);

  // 12-hour to match every other time in the app (formatTimeManila renders
  // "01:30 PM"); 2-digit hour keeps the mono digits from shifting at 9→10.
  const clockText = active
    ? new Date(now).toLocaleTimeString("en-PH", {
        timeZone: "Asia/Manila",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
      })
    : "– – : – – : – –";

  const workedMin = today ? liveWorkedMinutes(today, now) : 0;

  // Which confirmation modal is open ("time_out" has its own tasks dialog).
  const [confirm, setConfirm] = useState<"time_in" | "lunch" | "break" | null>(null);
  const [timeOutOpen, setTimeOutOpen] = useState(false);
  const lunchActive = state === "on_lunch";
  const breakActive = state === "on_break";

  async function run(fn: () => Promise<unknown>, msg: string) {
    try {
      await fn();
      toast.success(msg);
      return true;
    } catch (err) {
      const m =
        (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
          .response?.data;
      toast.error(m?.message ?? Object.values(m?.errors ?? {})[0]?.[0] ?? "Action failed.");
      return false;
    }
  }

  // Content for the simple confirm modal (time in / lunch / break).
  const confirmMeta =
    confirm === "time_in"
      ? {
          title: "Clock in?",
          desc: "Start your work day now — your time in is recorded at the current time.",
          label: "Time in",
          className: "bg-brand text-brand-foreground hover:bg-brand/90",
          action: () => run(() => clockInMut.mutateAsync(), "Clocked in"),
        }
      : confirm === "lunch"
        ? {
            title: lunchActive ? "End lunch break?" : "Start lunch break?",
            desc: lunchActive
              ? "End your lunch and go back on the clock?"
              : "Start your lunch break? Your worked hours pause until you return.",
            label: lunchActive ? "End lunch" : "Start lunch",
            className: "",
            action: () =>
              run(() => breakMut.mutateAsync("lunch"), lunchActive ? "Lunch ended" : "Lunch started"),
          }
        : confirm === "break"
          ? {
              title: breakActive ? "End break?" : "Start a short break?",
              desc: breakActive
                ? "End your break and go back on the clock?"
                : "Start a short break? Your worked hours pause until you return.",
              label: breakActive ? "End break" : "Start break",
              className: "",
              action: () =>
                run(() => breakMut.mutateAsync("break"), breakActive ? "Break ended" : "Break started"),
            }
          : null;

  async function onConfirm() {
    if (!confirmMeta) return;
    const ok = await confirmMeta.action();
    if (ok) setConfirm(null);
  }

  async function onTimeOut(tasks: DayTask[]) {
    const ok = await run(() => clockOutMut.mutateAsync(tasks), "Clocked out");
    if (ok) setTimeOutOpen(false);
  }

  // Management staff are exempt from attendance — no clock panel, just a note.
  if (employee?.is_management) {
    return (
      <div className="flex items-center gap-3 rounded-card bg-dark-teal p-6 text-white">
        <span className="size-2.5 rounded-full bg-success" />
        <div>
          <p className="text-sm font-semibold">You&apos;re exempt from attendance (Management).</p>
          <p className="mt-0.5 text-sm text-white/70">
            No clock-in required — you&apos;re shown as present on scheduled workdays.
          </p>
        </div>
      </div>
    );
  }

  return (
    // Column widths follow the Figma: a 420px right rail (40px of it inner
    // padding) butted straight against the divider, so the left column doesn't
    // stretch and strand the two halves apart.
    <div className="grid grid-cols-1 gap-6 rounded-card bg-dark-teal p-8 text-white lg:grid-cols-[1fr_auto_420px] lg:gap-x-0">
      {/* Left: status + clock + actions */}
      <div>
        {/* pr keeps the alert off the divider — the row is the only thing that
            reaches the column's right edge. */}
        <div className="flex items-center justify-between gap-4 lg:pr-5">
          <div className="flex items-center gap-2">
            <span className={cn("size-2.5 rounded-full", meta.dot)} />
            <span className="text-sm font-semibold">{meta.label}</span>
          </div>
          {alertFlag && <AttendanceAlertBanner violation={alertFlag} />}
        </div>

        {/* Smaller + tighter below `sm`: at 320px the card's inner width is
            ~240px, and "01:30:45 PM" at text-4xl/tracking-wider needs ~253px,
            which wrapped the AM/PM onto its own line. */}
        <p className="mt-6 font-mono text-3xl font-bold tracking-wide whitespace-nowrap text-brand sm:text-4xl sm:tracking-wider">
          {clockText}
        </p>

        <p className="mt-3 text-sm text-white/70">
          {state === "not_clocked_in" && "You haven't clocked in yet today."}
          {state === "clocked_in" && `Clocked in at ${formatTimeManila(today?.time_in)}.`}
          {state === "on_lunch" && "You're on your lunch break."}
          {state === "on_break" && "You're on a short break."}
          {state === "clocked_out" && "You've clocked out for today."}
        </p>

        {/* Stacked full-width on phones with a wider gap: wrapped side-by-side
            buttons put "Time out" a few pixels from "Start break", which is an
            easy mis-tap when the two do very different things. */}
        <div className="mt-5 grid gap-3 sm:flex sm:flex-wrap sm:gap-2">
          <button
            disabled={busy || state !== "not_clocked_in"}
            onClick={() => setConfirm("time_in")}
            className="rounded-lg bg-brand px-4 py-3 sm:py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
          >
            Time in
          </button>
          <button
            disabled={busy || !(state === "clocked_in" || state === "on_lunch")}
            onClick={() => setConfirm("lunch")}
            className="rounded-lg border border-white/25 px-4 py-3 sm:py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-40"
          >
            {lunchActive ? "End lunch" : "Start lunch"}
          </button>
          <button
            disabled={busy || !(state === "clocked_in" || state === "on_break")}
            onClick={() => setConfirm("break")}
            className="rounded-lg border border-white/25 px-4 py-3 sm:py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-40"
          >
            {breakActive ? "End break" : "Start break"}
          </button>
          <button
            disabled={busy || state !== "clocked_in"}
            onClick={() => setTimeOutOpen(true)}
            className="rounded-lg bg-danger px-4 py-3 sm:py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
          >
            Time out
          </button>
        </div>
      </div>

      {/* Divider */}
      <div className="hidden w-px bg-white/15 lg:block" />

      {/* Right: today's stats + week */}
      <div className="lg:pl-10">
        <p className="text-label font-bold uppercase tracking-wide text-white/50">
          Today&apos;s Stats
        </p>
        <div className="mt-2 flex justify-between text-sm">
          <span>
            <span className="text-white/50">In</span>{" "}
            {formatTimeManila(today?.time_in)}
          </span>
          <span>
            <span className="text-white/50">Out</span>{" "}
            {formatTimeManila(today?.time_out)}
          </span>
          <span>
            <span className="text-white/50">Worked</span>{" "}
            <span className="font-semibold text-brand">{formatHm(workedMin)}</span>
          </span>
        </div>
        <div className="mt-1 flex gap-6 text-sm text-white/70">
          <span>Break: {formatHm(today?.break_minutes ?? 0)}</span>
          <span>Lunch: {formatHm(today?.lunch_minutes ?? 0)}</span>
        </div>

        <p className="mt-5 text-label font-bold uppercase tracking-wide text-white/50">
          This Week
        </p>
        <WeekMini week={data?.week ?? []} loading={isLoading} />
      </div>

      {/* Confirmation modal for time in / lunch / break */}
      <Dialog open={confirm !== null} onOpenChange={(o) => !o && setConfirm(null)}>
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto">
          {confirmMeta && (
            <>
              <DialogHeader>
                <DialogTitle>{confirmMeta.title}</DialogTitle>
                <DialogDescription>{confirmMeta.desc}</DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <DialogClose asChild>
                  <Button variant="outline">Cancel</Button>
                </DialogClose>
                <Button className={confirmMeta.className} disabled={busy} onClick={onConfirm}>
                  {busy ? "Working…" : confirmMeta.label}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* Time-out modal with the day's tasks */}
      <TimeOutDialog
        open={timeOutOpen}
        onOpenChange={setTimeOutOpen}
        onConfirm={onTimeOut}
        pending={clockOutMut.isPending}
      />
    </div>
  );
}

function WeekMini({ week, loading }: { week: Attendance[]; loading: boolean }) {
  const byDow = new Map<number, Attendance>();
  for (const row of week) {
    const dow = new Date(row.work_date + "T12:00:00").getDay();
    byDow.set(dow, row);
  }
  const worked = week.filter((r) => r.hours_worked > 0);
  const avg =
    worked.length > 0
      ? worked.reduce((s, r) => s + r.hours_worked, 0) / worked.length
      : 0;

  return (
    <div className="mt-2">
      <div className="grid grid-cols-5 gap-2 text-center">
        {WEEK_DAYS.map(({ label, dow }, i) => {
          const row = byDow.get(dow);
          return (
            <div key={i}>
              <p className="text-xs text-white/50">{label}</p>
              <p className="mt-1 text-sm font-medium">
                {loading ? "…" : row && row.hours_worked > 0 ? formatHm(row.hours_worked * 60) : "—"}
              </p>
            </div>
          );
        })}
      </div>
      <p className="mt-3 text-xs text-white/50">
        Weekly average:{" "}
        <span className="font-medium text-brand">{avg.toFixed(1)} hrs</span>
      </p>
    </div>
  );
}
