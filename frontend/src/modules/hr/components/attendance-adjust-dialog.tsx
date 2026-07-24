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
import { useAdjustAttendance, useViolations, useResolveViolation } from "@/modules/hr/hooks/use-attendance";
import { useAttendancePolicy } from "@/modules/hr/hooks/use-rules";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { TimePicker } from "@/modules/hr/ui/time-picker";
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

const STATUS_PILL: Record<string, { cls: string; label: string }> = {
  present: { cls: "bg-success-subtle text-success", label: "Checked in" },
  late: { cls: "bg-danger-subtle text-danger", label: "Late" },
  absent: { cls: "bg-danger-subtle text-danger", label: "Absent" },
};

// Month options for the period filter, value = 2-digit month.
const MONTHS = Array.from({ length: 12 }, (_, i) => ({
  value: String(i + 1).padStart(2, "0"),
  label: new Date(2000, i, 1).toLocaleDateString("en-PH", { month: "long" }),
}));

function titleCase(s: string): string {
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

// Current calendar year in Manila.
function manilaYear(): number {
  return Number(
    new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila", year: "numeric" }).format(new Date()),
  );
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

// "09:00" → "9:00 AM".
function to12h(hhmm: string | null | undefined): string {
  if (!hhmm) return "—";
  const [h, m] = hhmm.split(":").map(Number);
  const period = h >= 12 ? "PM" : "AM";
  const h12 = ((h + 11) % 12) + 1;
  return `${h12}:${String(m).padStart(2, "0")} ${period}`;
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

  // Status is not editable — it's derived from the times HR enters, matching the
  // server. No time-in disables (and clears) the time-out.
  const derivedStatus = deriveStatus(timeIn, row.schedule);
  const noTimeIn = !timeIn;

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
  const pill = STATUS_PILL[derivedStatus] ?? { cls: "bg-muted text-muted-foreground", label: titleCase(derivedStatus) };

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
            <Info
              label="Status"
              value={
                <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", pill.cls)}>
                  {pill.label}
                </span>
              }
            />
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
                  {to12h(row.schedule.start_time)}–{to12h(row.schedule.end_time)}
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
              <div className="space-y-1.5">
                <Label>Status (auto)</Label>
                <div className="flex h-8 items-center">
                  <span className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold", pill.cls)}>
                    {pill.label}
                  </span>
                </div>
              </div>
              <div className="space-y-1.5">
                <Label>Time In</Label>
                <TimePicker value={timeIn} onChange={setTimeIn} />
              </div>
              <div className="space-y-1.5">
                <Label>Time Out</Label>
                <TimePicker value={timeOut} onChange={setTimeOut} disabled={noTimeIn} />
              </div>
            </div>
            <p className="mt-2 text-caption text-muted-foreground">
              Status updates automatically from Time In. Clear Time In to mark the day absent.
            </p>
            <p className="mt-3 text-caption text-muted-foreground">
              Hours worked: <span className="font-semibold text-foreground">{Number(row.hours_worked.toFixed(1))}</span>
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
            pageSize={5}
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
          <Button disabled={adjustMut.isPending} onClick={onSave}>
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
