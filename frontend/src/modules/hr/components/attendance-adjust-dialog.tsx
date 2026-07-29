"use client";

import { useMemo, useState } from "react";
import { Clock, CalendarPlus } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DataTable, type Column } from "@/core/components/data-table";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import { toManilaTimeInput, formatDate } from "@/core/lib/format";
import { format12Hour, manilaYear } from "@/modules/hr/lib/manila-date";
import { useAdjustAttendance, useViolations, useResolveViolation } from "@/modules/hr/hooks/use-attendance";
import { useAttendancePolicy } from "@/modules/hr/hooks/use-rules";
import { SimpleSelect } from "@/modules/hr/ui/select";

/**
 * What HR can assert for a day (Slice 16). Blank derives it from the times, as before.
 * Every value here has a defined pay meaning in AttendanceService::unpaidDaysDetailFor() —
 * don't add one without giving it a rule there, or it becomes a control that does nothing.
 */
const STATUS_OPTIONS = [
  { value: "", label: "Auto (from Time In)" },
  { value: "present", label: "Present — paid" },
  { value: "late", label: "Late — paid, flagged" },
  { value: "absent", label: "Absent — 1 unpaid day" },
  { value: "on_leave", label: "On leave — follows the leave request" },
  { value: "half_day", label: "Half day — 0.5 unpaid" },
  { value: "rest_day", label: "Rest day — not a workday" },
  { value: "holiday", label: "Holiday — paid" },
];

/** Mirror of Attendance::HR_STATUSES — the values this dialog is allowed to send. */
const HR_STATUSES = new Set(STATUS_OPTIONS.map((o) => o.value).filter(Boolean));
import { TimePicker } from "@/modules/hr/ui/time-picker";
import { SoftBadge, hrStatusBadge } from "@/modules/hr/lib/attendance-display";
import { SEVERITY_LABEL } from "@/modules/hr/types/attendance";
import type { HrAttendanceRow, Severity, Violation } from "@/modules/hr/types/attendance";
import type { ViolationRules } from "@/modules/hr/types/rules";

const DOT: Record<Severity, string> = {
  green: "bg-success",
  yellow: "bg-warning",
  orange: "bg-severity-orange",
  red: "bg-danger",
};

// Severity order for the legend (worst last), label pulled from SEVERITY_LABEL.
const SEVERITY_ORDER: Severity[] = ["green", "yellow", "orange", "red"];

/** A level's threshold from the ladder (monthly_min for late, streak_min for absence). */
function threshold(rules: ViolationRules | null | undefined, category: keyof ViolationRules, severity: Severity, key: "monthly_min" | "streak_min", fallback: number): number {
  return (rules?.[category] ?? []).find((r) => r.severity === severity)?.[key] ?? fallback;
}

/**
 * Build the legend descriptions from the CONFIGURED ladder (Company → Rules), so the
 * numbers HR set there are exactly what shows here. Each level shows its late-count band
 * and its absence-days band. Falls back to today's defaults when the policy isn't loaded.
 */
function legendDescriptions(rules: ViolationRules | null | undefined): Record<Severity, string> {
  const lLow = threshold(rules, "late", "green", "monthly_min", 1);
  const lCaution = threshold(rules, "late", "yellow", "monthly_min", 3);
  const lWarning = threshold(rules, "late", "orange", "monthly_min", 4);
  const lCritical = threshold(rules, "late", "red", "monthly_min", 6);
  const aLow = threshold(rules, "absence", "green", "streak_min", 1);
  const aCaution = threshold(rules, "absence", "yellow", "streak_min", 2);
  const aWarning = threshold(rules, "absence", "orange", "streak_min", 3);
  const aCritical = threshold(rules, "absence", "red", "streak_min", 4);

  const band = (from: number, to: number, unit: string) =>
    from >= to ? `${from}+ ${unit}` : `${from}–${to} ${unit}`;

  return {
    green: `${band(lLow, lCaution - 1, "lates/mo")}; ${band(aLow, aCaution - 1, "unauth. days")}; missed clock-out`,
    yellow: `${band(lCaution, lWarning - 1, "lates/mo")}; ${band(aCaution, aWarning - 1, "unauth. days")}`,
    orange: `${band(lWarning, lCritical - 1, "lates/mo")}; ${band(aWarning, aCritical - 1, "unauth. days")}`,
    red: `${lCritical}+ lates/mo; ${aCritical}+ consecutive days, no leave (AWOL)`,
  };
}

// Month options for the period filter, value = 2-digit month.
const MONTHS = Array.from({ length: 12 }, (_, i) => ({
  value: String(i + 1).padStart(2, "0"),
  label: new Date(2000, i, 1).toLocaleDateString("en-PH", { month: "long" }),
}));

function titleCase(s: string): string {
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

// Mirror of the backend rule: status follows the time-in — none = absent; otherwise
// late if past the schedule start + grace, else present. (Both are Manila "HH:MM".)
function deriveStatus(timeIn: string, schedule: HrAttendanceRow["schedule"]): "present" | "late" | "absent" {
  if (!timeIn) return "absent";
  const start = schedule?.start_time;
  if (!start) return "present";
  const [sh, sm] = start.split(":").map(Number);
  const cutoff = sh * 60 + sm + (schedule?.grace_minutes ?? 0);
  const [th, tm] = timeIn.split(":").map(Number);
  return th * 60 + tm > cutoff ? "late" : "present";
}

/**
 * Which status the dropdown opens on. "Auto" only ever yields present/late/absent, so a
 * day the roster settled some other way — on leave, a rest day, a holiday, or management
 * reported present without a punch — arrives pre-selected: leaving it on Auto would turn
 * an untouched Save into an absence. Statuses HR can't assert (`not_checked_in`, a day
 * still in progress) stay on Auto, since sending them back is not an option.
 */
function initialStatus(row: HrAttendanceRow): string {
  if (!HR_STATUSES.has(row.status)) return "";

  const autoWouldBe = deriveStatus(toManilaTimeInput(row.time_in), row.schedule);

  return row.status_override || row.status !== autoWouldBe ? row.status : "";
}

/** "09:30" → 570 minutes past midnight. */
function minutesOf(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return h * 60 + m;
}

const WEEKDAY_ABBR = ["", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]; // ISO 1..7
function formatWorkdays(days: number[] | undefined): string {
  if (!days || days.length === 0) return "—";
  return [...days].sort((a, b) => a - b).map((d) => WEEKDAY_ABBR[d] ?? d).join(", ");
}

export function AttendanceAdjustDialog({
  open,
  onOpenChange,
  row,
  onScheduleMeeting,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  row: HrAttendanceRow | null;
  /** Opens the "schedule a meeting with this employee" flow. */
  onScheduleMeeting?: () => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto overflow-x-hidden sm:max-w-lg">
        {/* Keyed so state re-initialises from a fresh row (Radix unmounts on close). */}
        {row && (
          <Details
            key={`${row.employee_id}-${row.work_date}`}
            row={row}
            onDone={() => onOpenChange(false)}
            onScheduleMeeting={onScheduleMeeting}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function Details({
  row,
  onDone,
  onScheduleMeeting,
}: {
  row: HrAttendanceRow;
  onDone: () => void;
  onScheduleMeeting?: () => void;
}) {
  const adjustMut = useAdjustAttendance();
  const resolve = useResolveViolation();

  const [timeIn, setTimeIn] = useState(toManilaTimeInput(row.time_in));
  const [timeOut, setTimeOut] = useState(toManilaTimeInput(row.time_out));
  const [note, setNote] = useState(row.note ?? "");
  // "" = derive from the times (the default). Any other value is an explicit HR override.
  const [status, setStatus] = useState<string>(initialStatus(row));

  // With no override, the status follows the times HR enters, matching the server.
  // No time-in disables the time-out.
  const derivedStatus = deriveStatus(timeIn, row.schedule);
  const noTimeIn = !timeIn;
  // What this day will actually be once saved: HR's pick wins, else the times decide.
  const effectiveStatus = status || derivedStatus;

  // Both punches are on the same date, so an out earlier than the in is not a
  // shift — it's a typo. The server rejects it; catch it before the round-trip.
  const timesReversed = !!timeIn && !!timeOut && minutesOf(timeOut) <= minutesOf(timeIn);

  // Hours as they will be recomputed on save: (out − in) − unpaid lunch. Shown live,
  // because the saved figure is stale the moment HR edits a time.
  const pendingHours =
    !timeIn || !timeOut || timesReversed
      ? 0
      : Math.max(0, minutesOf(timeOut) - minutesOf(timeIn) - row.lunch_minutes) / 60;

  // Violation history: fetch ALL months, filter client-side by an optional period.
  const { data: violations } = useViolations(row.employee_id);
  const { data: policy } = useAttendancePolicy();
  const allViolations = useMemo(() => violations ?? [], [violations]);

  const currentYear = manilaYear();
  const [periodMonth, setPeriodMonth] = useState("all"); // "all" | "01".."12"
  const [periodYear, setPeriodYear] = useState(currentYear);

  // Year options: the employee's hire year → this year (newest first).
  const years = useMemo(() => {
    const hireYear = row.hire_date ? Number(row.hire_date.slice(0, 4)) : currentYear;
    const start = Math.min(hireYear, currentYear);
    return Array.from({ length: currentYear - start + 1 }, (_, i) => currentYear - i);
  }, [row.hire_date, currentYear]);

  const filteredViolations = useMemo(() => {
    if (periodMonth === "all") return allViolations;
    const prefix = `${periodYear}-${periodMonth}`; // 'YYYY-MM'
    return allViolations.filter((v) => v.work_date.startsWith(prefix));
  }, [allViolations, periodMonth, periodYear]);

  async function onSave() {
    try {
      await adjustMut.mutateAsync({
        employee_id: row.employee_id,
        work_date: row.work_date,
        time_in: timeIn || null,
        time_out: timeIn && timeOut ? timeOut : null,
        // Only send a status when HR actually picked one — otherwise the server derives it.
        status: status || null,
        note: note || null,
      });
      toast.success("Attendance updated");
      onDone();
    } catch {
      toast.error("Could not update attendance. Check the times.");
    }
  }

  const currentSev: Severity = row.flag?.severity ?? "green";
  const level = { sev: currentSev, label: SEVERITY_LABEL[currentSev] };
  // Legend descriptions reflect the thresholds HR configured (Company → Rules).
  const legendDesc = useMemo(() => legendDescriptions(policy?.violation_rules), [policy]);
  const pill = hrStatusBadge(effectiveStatus);

  // Three different things "a status is selected" can mean, and HR needs to be able to
  // tell them apart: nothing picked, something the roster already settled, or a hand
  // override. Only the untouched pre-selection is safe to describe as "already".
  const statusHint = !status
    ? "Status follows Time In automatically. Clear Time In to mark the day absent, or pick a status to override it."
    : status === row.status && !row.status_override
      ? `This day is already ${hrStatusBadge(row.status).label.toLowerCase()}, so it's pre-selected — saving keeps it that way. Switch to Auto only if the times should decide instead.`
      : "You've set this status manually — it overrides the times, and payroll and violation flags follow it.";

  // Violation-history table columns (unresolved float to the top via `needsAction`).
  const violationColumns: Column<Violation>[] = [
    { header: "Date", cell: (v) => formatDate(v.work_date), className: "whitespace-nowrap" },
    { header: "Type", cell: (v) => <span className="font-medium text-foreground">{titleCase(v.type)}</span> },
    { header: "Details", cell: (v) => <span className="text-muted-foreground">{v.details ?? "—"}</span> },
    {
      header: "Alert",
      className: "text-center",
      cell: (v) => <span className={cn("mx-auto inline-block size-2.5 rounded-full", DOT[v.severity])} />,
    },
    {
      header: "Status",
      align: "right",
      cell: (v) =>
        v.resolved ? (
          <span className="text-caption text-muted-foreground">Resolved</span>
        ) : (
          <Button
            size="xs"
            variant="outline"
            disabled={resolve.isPending}
            onClick={() => resolve.mutate(v.id)}
          >
            Resolve
          </Button>
        ),
    },
  ];

  const periodToolbar = (
    <div className="flex items-center gap-1.5">
      <SimpleSelect
        className="w-36"
        value={periodMonth}
        onValueChange={setPeriodMonth}
        aria-label="Filter by month"
        options={[
          { value: "all", label: "All time" },
          ...MONTHS.map((m) => ({ value: m.value, label: m.label })),
        ]}
      />
      <SimpleSelect
        className="w-28"
        value={String(periodYear)}
        disabled={periodMonth === "all"}
        onValueChange={(v) => setPeriodYear(Number(v))}
        aria-label="Filter by year"
        options={years.map((y) => ({ value: String(y), label: String(y) }))}
      />
    </div>
  );

  return (
    <>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <span className="flex size-8 items-center justify-center rounded-lg bg-brand-subtle text-brand-foreground">
            <Clock className="size-4" />
          </span>
          Employee Attendance Details
        </DialogTitle>
        <DialogDescription>View attendance records, violations, and alert status.</DialogDescription>
      </DialogHeader>

      <div className="max-h-[65vh] space-y-6 overflow-y-auto overflow-x-hidden px-0.5">
        {/* Employee */}
        <Section title="Employee">
          <div className="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-4">
            <Info label="Name" value={<span className="font-semibold text-foreground">{row.name ?? "—"}</span>} />
            <Info label="Department" value={row.department ?? "—"} />
            <Info label="Status" value={<SoftBadge tone={pill.tone}>{pill.label}</SoftBadge>} />
            <Info
              label="Current Alert Level"
              value={
                <span className="inline-flex items-center gap-1.5">
                  <span className={cn("size-2.5 rounded-full", DOT[level.sev])} />
                  {level.label}
                </span>
              }
            />
          </div>
          <div className="mt-4">
            <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">Work Schedule</p>
            <div className="mt-1 text-sm text-foreground">
              <span className="font-medium">{row.schedule?.name ?? "—"}</span>
              {row.schedule?.start_time && (
                <span className="text-muted-foreground">
                  {" · "}
                  {format12Hour(row.schedule.start_time)}–{format12Hour(row.schedule.end_time)}
                  {" · "}
                  {formatWorkdays(row.schedule.workdays)}
                  {" · "}
                  {row.schedule.grace_minutes}m grace
                </span>
              )}
            </div>
          </div>
        </Section>

        {/* Today's record — editable */}
        <Section title="Today's Record">
          <div className="rounded-lg border border-border p-4">
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <Info label="Date" value={formatDate(row.work_date)} />
              {/* Slice 16 — HR can set the status outright. "Auto" keeps the old behaviour
                  (derived from Time In); anything else wins, even over the clock. */}
              <div className="space-y-1.5">
                <Label>Status</Label>
                <SimpleSelect
                  value={status}
                  onValueChange={setStatus}
                  options={STATUS_OPTIONS}
                />
              </div>
              <div className="space-y-1.5">
                <Label>Time In</Label>
                <TimePicker value={timeIn} onChange={setTimeIn} />
              </div>
              <div className="space-y-1.5">
                <Label>Time Out</Label>
                <TimePicker
                  value={timeOut}
                  onChange={setTimeOut}
                  disabled={noTimeIn}
                  aria-invalid={timesReversed}
                />
              </div>
            </div>
            {timesReversed && (
              <p className="mt-2 text-caption font-medium text-danger">
                Time Out has to be later than Time In.
              </p>
            )}
            <p className="mt-2 text-caption text-muted-foreground">{statusHint}</p>
            <p className="mt-3 text-caption text-muted-foreground">
              Hours worked: <span className="font-semibold text-foreground">{Number(pendingHours.toFixed(1))}</span>
              {row.lunch_minutes > 0 && (
                <span> · after {row.lunch_minutes}m unpaid lunch</span>
              )}
              {row.break_minutes > 0 && (
                <span> · {row.break_minutes}m paid break</span>
              )}
            </p>
            <div className="mt-3 space-y-1.5">
              <Label>Note (optional)</Label>
              <Input
                placeholder="e.g. Forgot to clock in — confirmed present"
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </div>
          </div>
        </Section>

        {/* Tasks the employee logged at time-out */}
        <Section title="Tasks Done">
          {row.tasks.length === 0 ? (
            <p className="text-sm text-muted-foreground">No tasks logged for this day.</p>
          ) : (
            <ul className="space-y-2">
              {row.tasks.map((t, i) => (
                <li key={i} className="flex items-start gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={t.done}
                    disabled
                    className="mt-0.5 size-4 shrink-0 rounded border-input accent-brand"
                  />
                  <span className={cn(t.done ? "text-foreground" : "text-muted-foreground")}>
                    {t.text}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Section>

        {/* Alert color legend — thresholds come from Company → Rules (Violation flags). */}
        <Section title="Alert Color Legend">
          <div className="space-y-2">
            {SEVERITY_ORDER.map((sev) => (
              <div key={sev} className="flex items-start gap-3 text-sm">
                <span className={cn("mt-1.5 size-2.5 shrink-0 rounded-full", DOT[sev])} />
                <span className="w-40 shrink-0 font-medium text-foreground">{SEVERITY_LABEL[sev]}</span>
                <span className="text-muted-foreground">{legendDesc[sev]}</span>
              </div>
            ))}
          </div>
        </Section>

        {/* Violation history — all months by default; filter by month + year. */}
        <Section title="Violation History">
          <DataTable
            columns={violationColumns}
            rows={filteredViolations}
            rowKey={(v) => v.id}
            needsAction={(v) => !v.resolved}
            pageSize={10}
            searchPlaceholder="Search violations…"
            toolbar={periodToolbar}
            empty={
              periodMonth === "all"
                ? "No violations on record."
                : "No violations for the selected period."
            }
          />
        </Section>
      </div>

      <DialogFooter className="sm:justify-between">
        {onScheduleMeeting ? (
          <Button type="button" variant="outline" onClick={onScheduleMeeting}>
            <CalendarPlus />
            Schedule Meeting
          </Button>
        ) : (
          <span />
        )}
        <div className="flex gap-2">
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button disabled={adjustMut.isPending || timesReversed} onClick={onSave}>
            {adjustMut.isPending ? "Saving…" : "Save changes"}
          </Button>
        </div>
      </DialogFooter>
    </>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="mb-3 border-b border-border pb-2 text-label font-semibold uppercase tracking-wide text-brand">
        {title}
      </p>
      {children}
    </div>
  );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="mt-1 text-sm text-foreground">{value}</div>
    </div>
  );
}
