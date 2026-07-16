"use client";

import { useEffect, useState } from "react";
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
  useClockIn,
  useClockOut,
  useToggleBreak,
} from "@/modules/hr/hooks/use-attendance";
import { TimeOutDialog } from "@/modules/hr/components/time-out-dialog";
import type { Attendance, ClockState, DayTask } from "@/modules/hr/types/attendance";

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

  const clockText = active
    ? new Date(now).toLocaleTimeString("en-PH", {
        timeZone: "Asia/Manila",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false,
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
    <div className="grid grid-cols-1 gap-6 rounded-card bg-dark-teal p-6 text-white lg:grid-cols-[1fr_auto_320px]">
      {/* Left: status + clock + actions */}
      <div>
        <div className="flex items-center gap-2">
          <span className={cn("size-2.5 rounded-full", meta.dot)} />
          <span className="text-sm font-semibold">{meta.label}</span>
        </div>

        <p className="mt-6 font-mono text-4xl font-bold tracking-wider text-brand">
          {clockText}
        </p>

        <p className="mt-3 text-sm text-white/70">
          {state === "not_clocked_in" && "You haven't clocked in yet today."}
          {state === "clocked_in" && `Clocked in at ${formatTimeManila(today?.time_in)}.`}
          {state === "on_lunch" && "You're on your lunch break."}
          {state === "on_break" && "You're on a short break."}
          {state === "clocked_out" && "You've clocked out for today."}
        </p>

        <div className="mt-5 flex flex-wrap gap-2">
          <button
            disabled={busy || state !== "not_clocked_in"}
            onClick={() => setConfirm("time_in")}
            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
          >
            Time in
          </button>
          <button
            disabled={busy || !(state === "clocked_in" || state === "on_lunch")}
            onClick={() => setConfirm("lunch")}
            className="rounded-lg border border-white/25 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-40"
          >
            {lunchActive ? "End lunch" : "Start lunch"}
          </button>
          <button
            disabled={busy || !(state === "clocked_in" || state === "on_break")}
            onClick={() => setConfirm("break")}
            className="rounded-lg border border-white/25 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-40"
          >
            {breakActive ? "End break" : "Start break"}
          </button>
          <button
            disabled={busy || state !== "clocked_in"}
            onClick={() => setTimeOutOpen(true)}
            className="rounded-lg bg-danger px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
          >
            Time out
          </button>
        </div>
      </div>

      {/* Divider */}
      <div className="hidden w-px bg-white/15 lg:block" />

      {/* Right: today's stats + week */}
      <div>
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
        <DialogContent>
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
