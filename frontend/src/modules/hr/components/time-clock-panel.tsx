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
import { format12Hour, manilaHm } from "@/modules/hr/lib/manila-date";
import {
  useMyAttendance,
  useMyViolations,
  useClockIn,
  useClockOut,
  useToggleBreak,
} from "@/modules/hr/hooks/use-attendance";
import {
  useMyRemoteCheckin,
  useSubmitRemoteCheckin,
} from "@/modules/hr/hooks/use-remote-checkins";
import { useCreateRestDayRequest } from "@/modules/hr/hooks/use-rest-day";
import { TimeOutDialog } from "@/modules/hr/components/time-out-dialog";
import { ClockInLocationDialog } from "@/modules/hr/components/clock-in-location-dialog";
import { SEVERITY_LABEL, SEVERITY_RANK } from "@/modules/hr/types/attendance";
import type {
  Attendance,
  ClockState,
  DayTask,
  LocationFix,
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
      <p className="text-[length:var(--text-label)] text-white sm:whitespace-nowrap">
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
  const activeState = state === "clocked_in" || state === "on_lunch" || state === "on_break";

  // Strict clock-in (Company → Rules). The server decides whether anything holds this
  // employee back and until when; we only compare that against the ticking clock, so the
  // button unlocks on the minute the shift opens without waiting for a refetch.
  const opensAt = data?.clock_in?.opens_at ?? null;
  const tooEarly = opensAt !== null && manilaHm(now) < opensAt;

  // Geofenced clock-in (Company → Rules). The server reports whether it is ACTUALLY
  // enforcing — an enabled-but-unconfigured policy fails open and reports false — so the
  // panel never asks a question whose answer would be ignored.
  const geofenced = data?.clock_in?.geofence ?? false;

  // Rest-day work (RD-2). Today isn't a scheduled workday, so the server refuses a punch and
  // takes a REQUEST instead — approving it stamps the time-in at the moment of the press, so
  // nothing is lost waiting for HR. Both facts come from the server: re-deriving "is today a
  // workday" from the weekday would ignore custom workdays and daily overrides.
  const isWorkday = data?.clock_in?.is_workday ?? true;
  const restReq = data?.clock_in?.rest_day_request ?? null;
  const restDayPending = restReq?.status === "pending";
  // Only worth saying while they are still not clocked in — once HR has approved something
  // else, or the day moved on, a stale refusal on screen is just noise.
  const restDayRejected = restReq?.status === "rejected" && state === "not_clocked_in";
  const restDayExpired = restReq?.status === "expired" && state === "not_clocked_in";
  // The button files a request rather than punching in.
  const needsRestDayRequest = !isWorkday && state === "not_clocked_in";
  const restDayMut = useCreateRestDayRequest();

  // `defer` mode: they pressed Time in before their shift, so the punch is stamped at the
  // shift start and is still in the future. Derived against the ticking clock rather than
  // read from the server, so it flips to a real clocked-in state on the minute — the same
  // trick `tooEarly` uses to unlock the button without waiting for a refetch.
  const queuedUntil =
    today?.time_in && new Date(today.time_in).getTime() > now ? today.time_in : null;
  // What a punch made RIGHT NOW would be recorded at, when defer mode is on and it's early.
  const defersTo = data?.clock_in?.defers_to ?? null;
  // A queued punch is NOT time on the clock — the running timer would be counting minutes
  // the employee is not being paid for.
  const active = activeState && queuedUntil === null;

  // The employee's own latest off-site submission. Only fetched when the feature is on —
  // there is nothing to show otherwise, and this is polled by the panel.
  const { data: myCheckin } = useMyRemoteCheckin(geofenced);
  const submitCheckinMut = useSubmitRemoteCheckin();
  // Awaiting review, and they haven't been clocked in by some other route since.
  const awaitingReview = myCheckin?.status === "pending" && state === "not_clocked_in";
  // A rejection they haven't acted on yet: still not clocked in, so it needs saying.
  const wasRejected = myCheckin?.status === "rejected" && state === "not_clocked_in";

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
  const [locationOpen, setLocationOpen] = useState(false);
  const [restDayOpen, setRestDayOpen] = useState(false);
  const [restDayReason, setRestDayReason] = useState("");
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
          // Explicitly no location: this is the plain confirm, only reachable when the
          // geofence isn't enforcing, so there is nothing to send and nothing to check.
          action: () => run(() => clockInMut.mutateAsync(null), "Clocked in"),
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

  // Geofenced office clock-in. `fix` is null whenever the browser couldn't produce one —
  // permission denied, no hardware, timed out — and that is sent as a clock-in with no
  // coordinates, which the office network can still verify on its own.
  async function onOfficeClockIn(fix: LocationFix | null) {
    const ok = await run(() => clockInMut.mutateAsync(fix), "Clocked in");
    if (ok) setLocationOpen(false);
  }

  // Off-site: the selfie goes to HR and the employee is stamped NOW. They are not on the
  // clock until it's approved, which the banner below spells out.
  async function onOutsideClockIn(photo: File, fix: LocationFix | null) {
    const ok = await run(
      () => submitCheckinMut.mutateAsync({ photo, fix }),
      "Sent to HR for approval",
    );
    if (ok) setLocationOpen(false);
  }

  // Rest-day work: this is the punch, even though nothing is written to attendance yet. The
  // toast quotes the time back so the promise ("you keep this moment") is visible immediately,
  // not only after HR gets round to it.
  async function onRestDayRequest() {
    const ok = await run(
      () => restDayMut.mutateAsync(restDayReason.trim() || undefined),
      "Sent to HR for approval",
    );
    if (ok) {
      setRestDayOpen(false);
      setRestDayReason("");
    }
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
        <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 lg:pr-5">
          <div className="flex items-center gap-2">
            <span
              className={cn("size-2.5 rounded-full", queuedUntil ? "bg-warning" : meta.dot)}
            />
            <span className="text-sm font-semibold">
              {queuedUntil ? "Queued" : meta.label}
            </span>
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
          {/* Rest-day branches come FIRST: on a day the schedule doesn't cover, "you haven't
              clocked in yet today" is not merely unhelpful, it implies they still can. */}
          {state === "not_clocked_in" && !isWorkday &&
            (restDayPending
              ? `Sent to HR at ${formatTimeManila(restReq?.requested_at)} — you'll be timed in at that moment once they approve it.`
              : restDayRejected
                ? `HR declined your request to work today: ${restReq?.review_note ?? "no reason given"}.`
                : restDayExpired
                  ? "Your earlier request for today lapsed without a decision — ask HR to add the day on the Attendance screen."
                  : "Today isn't one of your workdays. Ask HR to work it — you'll be timed in from the moment you ask, not when they approve.")}
          {state === "not_clocked_in" && isWorkday &&
            (awaitingReview
              ? `Sent to HR at ${formatTimeManila(myCheckin?.submitted_at)} — you'll be timed in at that moment once they approve it.`
              : wasRejected
                ? `HR couldn't accept your photo: ${myCheckin?.rejection_note ?? "no reason given"} — send another to time in.`
                : tooEarly
                  ? `Your shift starts at ${format12Hour(opensAt)} — you can time in from then.`
                  : defersTo
                    ? `You haven't clocked in yet today. Timing in now counts from ${format12Hour(defersTo)}, when your shift starts.`
                    : "You haven't clocked in yet today.")}
          {state === "clocked_in" &&
            (queuedUntil
              ? `You're queued — your time in counts from ${formatTimeManila(queuedUntil)}, when your shift starts.`
              : `Clocked in at ${formatTimeManila(today?.time_in)}.${
                  !isWorkday ? " HR approved this rest day — it earns a rest-day premium." : ""
                }`)}
          {state === "on_lunch" && "You're on your lunch break."}
          {state === "on_break" && "You're on a short break."}
          {state === "clocked_out" && "You've clocked out for today."}
        </p>

        {/* Stacked full-width on phones with a wider gap: wrapped side-by-side
            buttons put "Time out" a few pixels from "Start break", which is an
            easy mis-tap when the two do very different things. */}
        <div className="mt-5 grid gap-3 sm:flex sm:flex-wrap sm:gap-2">
          <button
            // Awaiting review disables it too: a second submission would be refused by the
            // server anyway, and the button offering it reads as "that didn't work". A pending
            // rest-day request is the same case with a different queue.
            disabled={
              busy ||
              restDayMut.isPending ||
              state !== "not_clocked_in" ||
              tooEarly ||
              awaitingReview ||
              restDayPending
            }
            onClick={() =>
              needsRestDayRequest
                ? setRestDayOpen(true)
                : geofenced
                  ? setLocationOpen(true)
                  : setConfirm("time_in")
            }
            title={tooEarly ? `Opens at ${format12Hour(opensAt)}` : undefined}
            className="rounded-lg bg-brand px-4 py-3 sm:py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-40"
          >
            {awaitingReview || restDayPending
              ? "Waiting for HR"
              : needsRestDayRequest
                ? "Request to work today"
                : "Time in"}
          </button>
          <button
            disabled={busy || !!queuedUntil || !(state === "clocked_in" || state === "on_lunch")}
            onClick={() => setConfirm("lunch")}
            className="rounded-lg border border-white/25 px-4 py-3 sm:py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-40"
          >
            {lunchActive ? "End lunch" : "Start lunch"}
          </button>
          <button
            disabled={busy || !!queuedUntil || !(state === "clocked_in" || state === "on_break")}
            onClick={() => setConfirm("break")}
            className="rounded-lg border border-white/25 px-4 py-3 sm:py-2 text-sm font-semibold text-white transition-colors hover:bg-white/10 disabled:opacity-40"
          >
            {breakActive ? "End break" : "Start break"}
          </button>
          <button
            disabled={busy || !!queuedUntil || state !== "clocked_in"}
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
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto lg:max-w-lg">
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

      {/* Rest-day work request — replaces the plain confirm when today isn't a workday.
          Its own dialog rather than a fourth `confirm` mode: this one takes an input and
          promises something the others don't. */}
      <Dialog
        open={restDayOpen}
        onOpenChange={(o) => {
          setRestDayOpen(o);
          if (!o) setRestDayReason("");
        }}
      >
        <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto lg:max-w-lg">
          <DialogHeader>
            <DialogTitle>Ask to work today?</DialogTitle>
            <DialogDescription>
              Today isn&apos;t one of your scheduled workdays, so HR has to agree to it. Sending
              this records your time in at <strong>right now</strong> — you keep this moment even
              if they approve it later. The day earns a rest-day premium once approved.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-1.5">
            <label htmlFor="rest-day-reason" className="text-sm font-medium">
              Reason <span className="font-normal text-muted-foreground">(optional)</span>
            </label>
            <textarea
              id="rest-day-reason"
              rows={2}
              value={restDayReason}
              onChange={(e) => setRestDayReason(e.target.value)}
              maxLength={1000}
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
              placeholder="e.g. client deadline, coverage for a teammate"
            />
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline">Cancel</Button>
            </DialogClose>
            <Button
              className="bg-brand text-brand-foreground hover:bg-brand/90"
              disabled={restDayMut.isPending}
              onClick={onRestDayRequest}
            >
              {restDayMut.isPending ? "Sending…" : "Send to HR"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Geofenced clock-in — replaces the plain confirm above when the server is
          enforcing a location check. Opening it starts the GPS fix immediately. */}
      <ClockInLocationDialog
        open={locationOpen}
        onOpenChange={setLocationOpen}
        onOffice={onOfficeClockIn}
        onOutside={onOutsideClockIn}
        pending={clockInMut.isPending || submitCheckinMut.isPending}
      />

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
