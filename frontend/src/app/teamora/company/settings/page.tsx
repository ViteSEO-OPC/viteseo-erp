"use client";

import { useState, type ReactNode } from "react";
import { Plus, Pencil, Trash2, Check, MapPin } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { PageHeader } from "@/modules/hr/components/page-header";
import { CompanyDetailsForm } from "@/modules/hr/components/company-details-form";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { WorkScheduleFormDialog } from "@/modules/hr/components/work-schedule-form-dialog";
import {
  useWorkSchedules,
  useDeleteWorkSchedule,
  useAttendancePolicy,
  useUpdateAttendancePolicy,
} from "@/modules/hr/hooks/use-rules";
import {
  usePayrollSettings,
  useUpdatePayrollSettings,
} from "@/modules/hr/hooks/use-payroll";
import type { PayrollSettings } from "@/modules/hr/types/payroll";
import { PAY_FREQUENCIES, payFrequencyLabel } from "@/modules/hr/types/employee";
import {
  formatWorkdays,
  type DailyRateBasis,
  type EarlyClockInMode,
  type LateDeductionMode,
  type LateMinutesFrom,
  type LateTier,
  type OvertimeMode,
  type UndertimeDeductionMode,
  type ViolationRule,
  type ViolationRules,
  type WorkSchedule,
} from "@/modules/hr/types/rules";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { AxiosError } from "axios";

/**
 * The server's own words, when it has any.
 *
 * A 422 carries the reason in `errors.<field>[0]`, and a flat "Could not save." in its place
 * leaves HR staring at a form that refuses to save without ever saying why. That is not
 * hypothetical: the rules here are refused outright when a change would mis-price a period that
 * has already been run, and the refusal is only useful if its reason arrives with it.
 */
function saveError(err: unknown, fallback = "Could not save."): string {
  if (!(err instanceof AxiosError)) return fallback;

  const errors = err.response?.data?.errors as Record<string, string[]> | undefined;
  const firstField = errors ? Object.values(errors)[0]?.[0] : undefined;

  return firstField ?? err.response?.data?.message ?? fallback;
}

export default function RulesPage() {
  const { data: schedules, isLoading, isError } = useWorkSchedules();
  const deleteMut = useDeleteWorkSchedule();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<WorkSchedule | null>(null);
  const [deleting, setDeleting] = useState<WorkSchedule | null>(null);

  function openCreate() {
    setEditing(null);
    setDialogOpen(true);
  }
  function openEdit(s: WorkSchedule) {
    setEditing(s);
    setDialogOpen(true);
  }
  async function performDelete(s: WorkSchedule) {
    try {
      await deleteMut.mutateAsync(s.id);
      toast.success("Schedule deleted");
    } catch {
      // Backend blocks deleting the default schedule (422).
      toast.error("Could not delete — the default schedule can't be removed.");
    }
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Company Settings"
        subtitle="Company details, work schedules per role, attendance settings, and per-employee overrides."
      />

      <div className="mt-8 space-y-8">
        {/*
         * Section A — who the employer is. First on the page because it is the one thing here
         * that appears on documents people outside HR read: every payslip, export and report
         * prints this letterhead.
         */}
        <CompanyDetailsForm />

        {/* Section B — schedules */}
        <section className="space-y-6">
          {/*
           * The heading carries "Add Schedule" rather than the page header: the page now edits
           * four unrelated things, so a button parked at the top reads as applying to all of
           * them. Beside the table it fills, it can only mean one thing.
           */}
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="space-y-1">
              <h2 className="text-h3 font-bold text-foreground">Work schedules</h2>
              <p className="text-sm text-muted-foreground">
                Working days and hours per role. Lateness is measured against these, plus each
                schedule&rsquo;s own grace period.
              </p>
            </div>
            <Button onClick={openCreate}>
              <Plus />
              Add Schedule
            </Button>
          </div>

          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading schedules…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">Could not load schedules. Please refresh.</p>
          ) : (
            <SchedulesTable
              rows={schedules ?? []}
              onEdit={openEdit}
              onDelete={setDeleting}
            />
          )}
        </section>

        {/* Section C — attendance settings */}
        <AttendancePolicyForm />

        {/*
         * Section D — payroll settings. Kept visually separate from the attendance settings
         * rather than folded into its card grid: both sections carry an "Effective from"
         * date, but they mean DIFFERENT things (see the two banners), and the payroll rules
         * have nothing to do with the violation engine the attendance heading describes.
         */}
        <PayrollSettingsForm />
      </div>

      <WorkScheduleFormDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        schedule={editing}
      />

      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete schedule"
        entityName={deleting?.name}
        description="This action cannot be undone. Employees assigned to this schedule will need a new one."
        onConfirm={() => performDelete(deleting!)}
      />
    </div>
  );
}

// ---- Section B: schedules table ----

// Column widths shared by the header and every row so they line up. Matches the
// Figma frame (Schedule / Workdays / Hours / Grace / Actions).
const COL = {
  schedule: "w-[24%] min-w-[160px]",
  workdays: "flex-1 min-w-[160px]",
  hours: "w-[130px]",
  grace: "w-[100px]",
  actions: "w-[80px]",
};

function SchedulesTable({
  rows,
  onEdit,
  onDelete,
}: {
  rows: WorkSchedule[];
  onEdit: (s: WorkSchedule) => void;
  onDelete: (s: WorkSchedule) => void;
}) {
  return (
    <div className="w-full overflow-hidden rounded-lg border border-border bg-card">
      <div className="overflow-x-auto">
        <div className="min-w-[700px]">
          {/* Header strip — shaded, uppercase gray labels. */}
          <div className="flex items-center gap-4 border-b border-border bg-muted/60 px-5 py-3 text-label font-bold uppercase tracking-wide text-muted-foreground">
            <div className={COL.schedule}>Schedule</div>
            <div className={COL.workdays}>Workdays</div>
            <div className={COL.hours}>Hours</div>
            <div className={COL.grace}>Grace</div>
            <div className={cn(COL.actions, "text-right")}>Actions</div>
          </div>

          {rows.length === 0 ? (
            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
              No schedules yet. Add your first one.
            </p>
          ) : (
            rows.map((s) => (
              <div
                key={s.id}
                className="flex items-center gap-4 border-b border-border px-5 py-4 last:border-0"
              >
                <div className={cn(COL.schedule, "flex items-center gap-2.5")}>
                  <span className="font-semibold text-foreground">{s.name}</span>
                  {s.is_default && (
                    <span className="rounded-full bg-success-subtle px-2.5 py-0.5 text-xs font-semibold text-success">
                      Default
                    </span>
                  )}
                </div>
                <div className={cn(COL.workdays, "text-sm text-foreground")}>
                  {formatWorkdays(s.workdays)}
                </div>
                <div className={cn(COL.hours, "text-sm text-foreground")}>
                  {s.start_time}–{s.end_time}
                </div>
                <div className={cn(COL.grace, "text-sm text-foreground")}>
                  {s.grace_minutes} min
                </div>
                <div className={cn(COL.actions, "flex justify-end gap-1")}>
                  <Button size="icon-sm" variant="ghost" onClick={() => onEdit(s)}>
                    <Pencil />
                    <span className="sr-only">Edit</span>
                  </Button>
                  <Button size="icon-sm" variant="ghost" onClick={() => onDelete(s)}>
                    <Trash2 />
                    <span className="sr-only">Delete</span>
                  </Button>
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}

// ---- Section C: attendance settings + task format ----

function AttendancePolicyForm() {
  const { data: policy } = useAttendancePolicy();

  return (
    <section className="space-y-6">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">Attendance settings</h2>
        <p className="text-sm text-muted-foreground">
          Handbook thresholds the violation system reads.
        </p>
      </div>
      {policy ? (
        <AttendancePolicyEditor key={policy.id} />
      ) : (
        <p className="text-sm text-muted-foreground">Loading policy…</p>
      )}
    </section>
  );
}

/**
 * The policy is edited one group at a time, each behind its own card. The card
 * carries the summary HR reads to decide whether to open it, so the title and
 * description live here rather than inside the dialog.
 */
type PolicyCardKey =
  | "violation"
  | "time_deductions"
  | "early_ot"
  | "rest_day_work"
  | "management"
  | "geofence"
  | "premium_rates"
  | "basis"
  | "break";

interface PolicyCard {
  key: PolicyCardKey;
  title: string;
  description: string;
}

// Order matches the Figma grid, read left to right.
const POLICY_CARDS: PolicyCard[] = [
  {
    key: "violation",
    title: "Violation flags",
    description:
      "The four alert levels shown in the Attendance modal — set what makes a late, an absence, or a missed clock-out each colour. These are disciplinary flags; they never deduct pay.",
  },
  {
    key: "time_deductions",
    title: "Time-based pay deductions",
    description:
      "How missed time (lateness and leaving early) reduces pay when payroll runs. Deducts only the actual time missed unless you set a threshold rule for lateness.",
  },
  {
    key: "early_ot",
    title: "Early arrival & overtime",
    description: "Clocking in early, and how extra time past the schedule is treated.",
  },
  {
    key: "rest_day_work",
    title: "Working a rest day",
    description:
      "Whether someone can time in on a day their schedule doesn't cover, or has to ask you first. The day is paid the same either way.",
  },
  {
    key: "management",
    title: "Management attendance",
    description:
      "Whether management staff are timed in and out automatically instead of using the time clock, and whether they earn the premium on a holiday they work.",
  },
  {
    key: "geofence",
    title: "Geofenced clock-in",
    description:
      "Check where someone is when they time in. Off, the time clock works exactly as it does today.",
  },
  {
    key: "premium_rates",
    title: "Premium & overtime rates",
    description:
      "Legal pay multipliers for overtime, holidays, rest days and night work. Raise any of them, but never below the DOLE minimum.",
  },
  {
    key: "basis",
    title: "Daily rate basis",
    description:
      "How one day of pay is priced — this affects absence deductions for everyone, not just minutes.",
  },
  {
    key: "break",
    title: "Break overage",
    description:
      "How far past their own break allowance a day may run before it is flagged. Over-long breaks are never deducted from pay.",
  },
];

const CARD = Object.fromEntries(POLICY_CARDS.map((c) => [c.key, c])) as Record<
  PolicyCardKey,
  PolicyCard
>;

// Read a level's threshold out of the stored violation ladder, falling back to default.
function rungValue(
  rules: ViolationRules | null | undefined,
  category: keyof ViolationRules,
  severity: ViolationRule["severity"],
  key: "streak_min" | "monthly_min" | "occurrence_min",
  fallback: number,
): number {
  const rung = (rules?.[category] ?? []).find((r) => r.severity === severity);
  return rung?.[key] ?? fallback;
}

// How lateness is charged. "exact" bills the precise minutes late; "custom" is a single
// threshold rule — if late by MORE than `over` minutes, charge a flat `charge` minutes —
// stored as a one-row late_tiers ladder (late_deduction_mode = 'tiered'). With
// `late_tier_recurring` on, that rung repeats per whole block of `over` minutes instead.
type ChargeMode = "exact" | "custom";

// The single threshold rung the UI edits, read out of the stored late_tiers (minutes).
function thresholdFrom(tiers: LateTier[] | null | undefined): { over: number; charge: number } {
  const row = tiers?.[0];
  if (!row) return { over: 15, charge: 60 }; // sensible starting point
  return { over: Number(row.over ?? 15), charge: Math.round(Number(row.hours ?? 1) * 60) };
}

/**
 * What the threshold rule costs at a given number of CHARGEABLE late minutes — mirrors
 * DeductionService::tierMinutes() exactly, including the recurring branch's `- 1`. Both modes
 * share one boundary: strictly MORE than `over`, so repeating changes how often the rung is
 * charged and never when it starts.
 */
function thresholdCost(lateMinutes: number, over: number, charge: number, recurring: boolean): number {
  if (!Number.isFinite(over) || !Number.isFinite(charge) || charge < 0) return 0;
  if (recurring) return over >= 1 ? Math.floor(Math.max(0, lateMinutes - 1) / over) * charge : 0;
  return lateMinutes > over ? charge : 0;
}

/** The bits of a work schedule the late preview needs, all as Manila minutes-of-day. */
type SampleDay = {
  startMin: number;
  graceMin: number;
  mealStartMin: number | null; // the unpaid meal, which is never charged as missed time
  mealEndMin: number | null;
  mealCap: number;
};

/** The lateness rule as this card has it configured. */
type LateRule = {
  basis: LateMinutesFrom;
  mode: ChargeMode;
  over: number;
  charge: number;
  recurring: boolean;
};

/**
 * The whole late calculation as payroll runs it, from a clock-in to minutes of pay — mirrors
 * DeductionService::forDay(). Grace GATES: an arrival inside it costs nothing on either basis.
 * Past the gate, the basis decides what the charge is measured from. The unpaid meal is netted
 * off an `exact` charge (an arrival after lunch did not miss the lunch hour of work) but never
 * off a threshold charge — that is a ladder rung, not a span of clock, and has no meal inside it.
 */
function lateCostAt(timeIn: number, day: SampleDay, rule: LateRule): number {
  const graceEnd = day.startMin + Math.max(0, day.graceMin);
  if (timeIn <= graceEnd) return 0;

  const from = rule.basis === "after_grace" ? graceEnd : day.startMin;
  const chargeable = Math.max(0, timeIn - from);
  if (rule.mode !== "exact") {
    return thresholdCost(chargeable, rule.over, rule.charge, rule.recurring);
  }

  const { mealStartMin, mealEndMin } = day;
  const meal =
    mealStartMin === null || mealEndMin === null || mealEndMin <= mealStartMin
      ? 0
      : Math.min(
          Math.max(0, day.mealCap),
          Math.max(0, Math.min(timeIn, mealEndMin) - Math.max(from, mealStartMin)),
        );

  return Math.max(0, chargeable - meal);
}

/** The first clock minute the rule costs anything, searching one shift's worth. */
function firstChargedMinute(day: SampleDay, rule: LateRule): number | null {
  for (let t = day.startMin + Math.max(0, day.graceMin) + 1; t <= day.startMin + 480; t++) {
    if (lateCostAt(t, day, rule) > 0) return t;
  }
  return null;
}

/** "08:00" → 480. Unparseable falls back to 8am so the preview always renders something. */
function toMinutes(hhmm: string | null | undefined): number {
  const m = /^(\d{1,2}):(\d{2})/.exec(hhmm ?? "");
  return m ? Number(m[1]) * 60 + Number(m[2]) : 480;
}

/** 511 → "8:31 AM" — the preview reads in clock time, the way HR asks the question. */
function toClock(minutes: number): string {
  const m = ((minutes % 1440) + 1440) % 1440;
  const h24 = Math.floor(m / 60);
  const h = h24 % 12 === 0 ? 12 : h24 % 12;
  return `${h}:${String(m % 60).padStart(2, "0")} ${h24 < 12 ? "AM" : "PM"}`;
}

/** 90 → "1h 30m", 120 → "2h", 45 → "45m". Deduction figures read as time, not decimals. */
function asDuration(minutes: number): string {
  if (minutes <= 0) return "no deduction";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return [h > 0 ? `${h}h` : null, m > 0 ? `${m}m` : null].filter(Boolean).join(" ");
}

// The four alert levels must strictly ascend. Returns an error message, or null if OK.
function ladderError(late: number[], absent: number[], missed: number[], longBreak: number[]): string | null {
  const ascends = (a: number[]) => a.every((v, i) => i === 0 || v > a[i - 1]);
  if (!ascends(late)) return "Lateness levels must increase: Low < Caution < Warning < Critical.";
  if (!ascends(absent)) return "Absence levels must increase: Low < Caution < Warning < Critical.";
  if (!ascends(missed)) return "Missed clock-out levels must increase: Low < Caution < Warning < Critical.";
  if (!ascends(longBreak)) return "Over-long break levels must increase: Low < Caution < Warning < Critical.";
  return null;
}

function AttendancePolicyEditor() {
  const { data: policy } = useAttendancePolicy();
  const update = useUpdateAttendancePolicy();

  // The default work schedule, read ONLY so the late preview can be shown in clock times
  // instead of abstract minutes. Served from the same cached useWorkSchedules() the table
  // above calls, so this costs no second request. Grace lives on the schedule, not on the
  // policy, which is why the preview has to name which schedule it is illustrating.
  const { data: schedules } = useWorkSchedules();
  const sample = schedules?.find((s) => s.is_default) ?? schedules?.[0];
  const sampleStart = toMinutes(sample?.start_time);
  const sampleGrace = Math.max(0, sample?.grace_minutes ?? 15);
  const sampleDay: SampleDay = {
    startMin: sampleStart,
    graceMin: sampleGrace,
    // The meal sits between the two half-day windows, exactly as DeductionService reads it.
    mealStartMin: sample?.am_end_time ? toMinutes(sample.am_end_time) : null,
    mealEndMin: sample?.pm_start_time ? toMinutes(sample.pm_start_time) : null,
    mealCap: Math.max(0, sample?.unpaid_break_minutes ?? 0),
  };

  // Violation flags — the four alert levels HR configures, read out of the stored ladder.
  // Lateness is by lates-this-month; absence by consecutive days. Reassembled on save.
  const vr = policy?.violation_rules;
  const [lateLow, setLateLow] = useState(String(rungValue(vr, "late", "green", "monthly_min", 1)));
  const [lateCaution, setLateCaution] = useState(String(rungValue(vr, "late", "yellow", "monthly_min", 3)));
  const [lateWarning, setLateWarning] = useState(String(rungValue(vr, "late", "orange", "monthly_min", 4)));
  const [lateCritical, setLateCritical] = useState(String(rungValue(vr, "late", "red", "monthly_min", 6)));
  const [absentLow, setAbsentLow] = useState(String(rungValue(vr, "absence", "green", "streak_min", 1)));
  const [absentCaution, setAbsentCaution] = useState(String(rungValue(vr, "absence", "yellow", "streak_min", 2)));
  const [absentWarning, setAbsentWarning] = useState(String(rungValue(vr, "absence", "orange", "streak_min", 3)));
  const [absentCritical, setAbsentCritical] = useState(String(rungValue(vr, "absence", "red", "streak_min", 4)));
  // Missed clock-out — by count of missed clock-outs this month (like lateness).
  const [missedLow, setMissedLow] = useState(String(rungValue(vr, "missed_time_out", "green", "occurrence_min", 1)));
  const [missedCaution, setMissedCaution] = useState(String(rungValue(vr, "missed_time_out", "yellow", "occurrence_min", 2)));
  const [missedWarning, setMissedWarning] = useState(String(rungValue(vr, "missed_time_out", "orange", "occurrence_min", 3)));
  const [missedCritical, setMissedCritical] = useState(String(rungValue(vr, "missed_time_out", "red", "occurrence_min", 4)));
  // Long break -- by count of over-long-break days this month.
  const [breakLow, setBreakLow] = useState(String(rungValue(vr, "long_break", "green", "occurrence_min", 1)));
  const [breakCaution, setBreakCaution] = useState(String(rungValue(vr, "long_break", "yellow", "occurrence_min", 2)));
  const [breakWarning, setBreakWarning] = useState(String(rungValue(vr, "long_break", "orange", "occurrence_min", 3)));
  const [breakCritical, setBreakCritical] = useState(String(rungValue(vr, "long_break", "red", "occurrence_min", 4)));
  // Whether the late tally resets each calendar month (on) or carries over (off).
  const [monthlyReset, setMonthlyReset] = useState(policy?.monthly_late_reset ?? true);

  // Tardiness pay deduction — two MUTUALLY EXCLUSIVE switches; at most one is ever on:
  // • lateChargeOn       = deduct for the actual time late (exact minutes, or a threshold rule)
  // • latePenaltyEnabled = deduct 1 extra full day for every N lates in the pay period
  // Turning either on turns the other off, so the same late is never charged twice.
  const [lateChargeOn, setLateChargeOn] = useState((policy?.late_deduction_mode ?? "none") !== "none");
  const [latePenaltyEnabled, setLatePenaltyEnabled] = useState(policy?.late_penalty_enabled ?? false);
  const [latesPerDeduction, setLatesPerDeduction] = useState(String(policy?.lates_per_deduction ?? 3));

  // Undertime.
  const [undertimeMode, setUndertimeMode] = useState<UndertimeDeductionMode>(policy?.undertime_deduction_mode ?? "none");

  // Early arrival & overtime.
  const [earlyClockIn, setEarlyClockIn] = useState<EarlyClockInMode>(
    policy?.early_clock_in_mode ?? "allow",
  );

  // Working a rest day. On (the default) = the work-request queue; off = an ordinary punch.
  const [restDayNeedsRequest, setRestDayNeedsRequest] = useState(
    policy?.rest_day_work_requires_request ?? true,
  );

  // Management attendance. On (the default) = they never punch and the platform writes their
  // days; off = they use the time clock like everyone else. The premium switch is separate
  // because it is a PAY question, not an attendance one.
  const [managementAuto, setManagementAuto] = useState(
    policy?.management_auto_attendance ?? true,
  );
  const [managementHolidayPremium, setManagementHolidayPremium] = useState(
    policy?.management_holiday_premium ?? false,
  );

  // Geofenced clock-in. lat/lng are kept as STRINGS so an empty box stays empty ("no pin
  // set") instead of collapsing to 0 — 0,0 is a real coordinate in the Atlantic.
  const [geofenceOn, setGeofenceOn] = useState(policy?.geofence_enabled ?? false);
  const [officeLat, setOfficeLat] = useState(policy?.office_lat?.toString() ?? "");
  const [officeLng, setOfficeLng] = useState(policy?.office_lng?.toString() ?? "");
  const [officeRadius, setOfficeRadius] = useState(String(policy?.office_radius_m ?? 200));
  const [officeIps, setOfficeIps] = useState<string[]>(policy?.office_ips ?? []);
  const [locating, setLocating] = useState(false);
  const [overtimeMode, setOvertimeMode] = useState<OvertimeMode>(policy?.overtime_mode ?? "approved_only");
  const [overtimeThreshold, setOvertimeThreshold] = useState(String(policy?.overtime_threshold_minutes ?? 0));
  // How much auto-mode overtime pays without an approval. 0 = uncapped (the default),
  // so an existing company's pay does not move until HR deliberately sets a figure.
  const [otDailyCap, setOtDailyCap] = useState(String(policy?.auto_overtime_daily_cap_minutes ?? 0));
  const [otPeriodCap, setOtPeriodCap] = useState(String(policy?.auto_overtime_period_cap_minutes ?? 0));
  // Item 7 — flags a long day for review WITHOUT withholding any of it. Deliberately not a third
  // cap: the caps above decide what pays, this decides what HR gets told about.
  const [otReviewMinutes, setOtReviewMinutes] = useState(String(policy?.auto_overtime_review_minutes ?? 240));
  // Ticked to confirm "0 = unlimited" when saving auto mode with both caps off. One-shot by
  // construction: once saved, the stored policy already IS uncapped auto and the box stops asking.
  const [capsAcknowledged, setCapsAcknowledged] = useState(false);

  // Premium & overtime rates (Phase 0) — whole-percent, floored at the DOLE minimum (UI + server).
  const [premRestDay, setPremRestDay] = useState(String(policy?.premium_rest_day_pct ?? 130));
  const [premSpecialDay, setPremSpecialDay] = useState(String(policy?.premium_special_day_pct ?? 130));
  const [premRegHoliday, setPremRegHoliday] = useState(String(policy?.premium_regular_holiday_pct ?? 200));
  const [otOrdinary, setOtOrdinary] = useState(String(policy?.overtime_ordinary_pct ?? 125));
  const [otRestSpecial, setOtRestSpecial] = useState(String(policy?.overtime_rest_special_pct ?? 169));
  const [otRegHoliday, setOtRegHoliday] = useState(String(policy?.overtime_regular_holiday_pct ?? 260));
  const [nightDiff, setNightDiff] = useState(String(policy?.night_diff_pct ?? 10));
  const [nightDiffStart, setNightDiffStart] = useState(policy?.night_diff_start_time || "22:00");
  const [nightDiffEnd, setNightDiffEnd] = useState(policy?.night_diff_end_time || "06:00");

  // How lateness is charged: "exact" (precise minutes) or "custom" (a single threshold rule).
  // Custom is stored as a one-row late_tiers ladder — see chargeFields() below.
  const th = thresholdFrom(policy?.late_tiers);
  const [chargeMode, setChargeMode] = useState<ChargeMode>(policy?.late_deduction_mode === "tiered" ? "custom" : "exact");
  const [thresholdOver, setThresholdOver] = useState(String(th.over));
  const [thresholdCharge, setThresholdCharge] = useState(String(th.charge));
  // Whether that one rung REPEATS. Off (the default, and every existing policy) charges it
  // once however late the arrival — the behaviour this page has always had.
  const [thresholdRecurring, setThresholdRecurring] = useState(policy?.late_tier_recurring ?? false);
  const [lateFrom, setLateFrom] = useState(policy?.late_minutes_from ?? "start_time");
  // Item 5 — how long past someone's own shift end an open punch is still "in progress".
  const [timeoutAllowance, setTimeoutAllowance] = useState(
    String(policy?.missing_timeout_allowance_minutes ?? 180),
  );
  // daily_deduction_cap is fixed at 1.0 (a day can never cost more than a day's pay) and not
  // HR-editable — a sub-1 cap only ever mattered with rounding and just confused users.

  // Daily-rate basis.
  const [rateBasis, setRateBasis] = useState<DailyRateBasis>(policy?.daily_rate_basis ?? "period_workdays");
  const [divisor, setDivisor] = useState(String(policy?.annual_divisor ?? 261));
  const [hoursPerDay, setHoursPerDay] = useState(String(policy?.hours_per_day_divisor ?? 8));

  // Break overage tolerances (flag only). The allowances live on the work schedule; lunch and
  // break are judged separately, and 0 means no tolerance.
  const [lunchGrace, setLunchGrace] = useState(String(policy?.lunch_grace_minutes ?? 15));
  const [breakGrace, setBreakGrace] = useState(String(policy?.break_grace_minutes ?? 15));

  // Which card's dialog is open, and which one is currently saving (so only its
  // button shows a spinner).
  const [openCard, setOpenCard] = useState<PolicyCardKey | null>(null);
  const [saving, setSaving] = useState<PolicyCardKey | null>(null);

  // The date this save counts from. Payroll resolves the rules in force at a cycle's END, so
  // today (the default) reaches the open cycle and every future one, but never a cycle that has
  // already closed. Back-dating is how HR corrects a cycle that has already been run — without
  // it, a fix typed after the cycle ended could never reach the payslips it was meant to fix.

  // Save just ONE card's fields (partial update — the API only touches keys it's sent).
  async function saveCard(
    key: PolicyCardKey,
    fields: Record<string, unknown>,
    guard?: () => boolean,
  ) {
    // A failed guard leaves the dialog open on the offending field rather than
    // closing over a toast the user then can't act on.
    if (guard && !guard()) return;
    setSaving(key);
    try {
      // No date is sent: a rule applies from today, and the server refuses a past one. The
      // server versions the WHOLE policy row, so today's save dates every rule on this page as
      // it stands, not only this card's.
      await update.mutateAsync(fields);
      toast.success("Saved");
      setOpenCard(null);
    } catch (err) {
      toast.error(saveError(err));
    } finally {
      setSaving(null);
    }
  }

  function guardLadder(): boolean {
    const err = ladderError(
      [lateLow, lateCaution, lateWarning, lateCritical].map(Number),
      [absentLow, absentCaution, absentWarning, absentCritical].map(Number),
      [missedLow, missedCaution, missedWarning, missedCritical].map(Number),
      [breakLow, breakCaution, breakWarning, breakCritical].map(Number),
    );
    if (err) {
      toast.error(err);
      return false;
    }
    return true;
  }

  function guardTimeoutAllowance(): boolean {
    const n = Number(timeoutAllowance);
    if (!Number.isInteger(n) || n < 30 || n > 720) {
      toast.error("The wait after the shift ends must be between 30 minutes and 12 hours.");
      return false;
    }
    return true;
  }

  /**
   * Everything the time-deduction card has to be true before it saves. The recurring rule is
   * the addition: a 0-minute block would repeat once per minute late, which the server rejects
   * — caught here so HR gets a sentence instead of a 422.
   */
  function guardTimeDeductions(): boolean {
    if (!guardTimeoutAllowance()) return false;
    if (lateChargeOn && chargeMode === "custom" && thresholdRecurring && Number(thresholdOver) < 1) {
      toast.error("A repeating late rule needs a threshold of at least 1 minute.");
      return false;
    }
    return true;
  }

  /**
   * Auto mode with both caps at 0 means overtime pays from the clock with NO ceiling — and 0 is
   * the shipped default, so this is reached by leaving fields alone rather than by typing a number.
   * Confirmed once, on the save that ENTERS that state; editing anything else in the card later
   * does not ask again, because nagging about a setting the company has already chosen is how a
   * confirmation stops being read.
   */
  function guardOvertimeCaps(): boolean {
    if (needsCapAck && !capsAcknowledged) {
      toast.error("Tick the box to confirm overtime should pay with no ceiling.");
      return false;
    }
    return true;
  }

  function guardNightWindow(): boolean {
    if (!nightDiffStart || !nightDiffEnd) {
      toast.error("Enter both night differential times.");
      return false;
    }
    if (nightDiffStart === nightDiffEnd) {
      toast.error("Night differential start and end times must be different.");
      return false;
    }
    return true;
  }

  const violationFields = () => ({
    monthly_late_reset: monthlyReset,
    violation_rules: {
      late: [
        { monthly_min: Number(lateCritical), severity: "red" },
        { monthly_min: Number(lateWarning), severity: "orange" },
        { monthly_min: Number(lateCaution), severity: "yellow" },
        { monthly_min: Number(lateLow), severity: "green" },
      ],
      absence: [
        { streak_min: Number(absentCritical), severity: "red", type: "awol" },
        { streak_min: Number(absentWarning), severity: "orange", type: "ua" },
        { streak_min: Number(absentCaution), severity: "yellow", type: "ua" },
        { streak_min: Number(absentLow), severity: "green", type: "ua" },
      ],
      missed_time_out: [
        { occurrence_min: Number(missedCritical), severity: "red" },
        { occurrence_min: Number(missedWarning), severity: "orange" },
        { occurrence_min: Number(missedCaution), severity: "yellow" },
        { occurrence_min: Number(missedLow), severity: "green" },
      ],
      long_break: [
        { occurrence_min: Number(breakCritical), severity: "red" },
        { occurrence_min: Number(breakWarning), severity: "orange" },
        { occurrence_min: Number(breakCaution), severity: "yellow" },
        { occurrence_min: Number(breakLow), severity: "green" },
      ],
    } satisfies ViolationRules,
  });

  // Lateness charge → policy fields. When on: "exact" = per_hour (bill the precise minutes),
  // "custom" = a one-row tiered ladder (charge Y min once more than X min late). Rounding
  // stays exact — undertime is always billed to the exact minute. late_tiers clears otherwise.
  const lateMode: LateDeductionMode = !lateChargeOn ? "none" : chargeMode === "custom" ? "tiered" : "per_hour";
  const chargeFields = () => ({
    late_deduction_mode: lateMode,
    late_tiers: lateMode === "tiered" ? [{ over: Number(thresholdOver), hours: Number(thresholdCharge) / 60 }] : null,
    // Meaningless without a threshold rule, and the server rejects it on a multi-rung ladder —
    // so it is forced off whenever the rule that carries it isn't the one being saved.
    late_tier_recurring: lateMode === "tiered" ? thresholdRecurring : false,
    deduction_rounding: "exact" as const,
    deduction_block_minutes: 60,
    late_minutes_from: lateFrom,
  });

  // The lateness charge controls, shown when the per-time switch is on. "Start counting late
  // from" comes first, then how the time is charged: Exact, or a single threshold rule.
  const chargeControls = () => {
    const rule: LateRule = {
      basis: lateFrom,
      mode: chargeMode,
      over: Number(thresholdOver),
      charge: Number(thresholdCharge),
      recurring: thresholdRecurring,
    };
    // Three clock moments worth showing: the last free minute, the first minute the rule costs
    // anything, and two hours in — where a repeating rule visibly diverges from a flat one.
    // Deduped and sorted, because with a small threshold they can land on the same minute.
    const first = firstChargedMinute(sampleDay, rule);
    const previewMarks = [
      ...new Set(
        [sampleStart + sampleGrace, first, sampleStart + 120].filter(
          (t): t is number => t !== null,
        ),
      ),
    ].sort((a, b) => a - b);

    return (
      <div className="ml-7 space-y-3 border-l-2 border-border pl-4">
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-late-from"
            label="Start counting late from"
            value={lateFrom}
            onChange={(v) => setLateFrom(v as "start_time" | "after_grace")}
            options={[
              { value: "start_time", label: "Scheduled start time" },
              { value: "after_grace", label: "End of the grace period" },
            ]}
          />
          <FieldSelect
            id="pol-charge-mode"
            label="Charge the time as"
            value={chargeMode}
            onChange={(v) => setChargeMode(v as ChargeMode)}
            options={[
              { value: "exact", label: "Exact time missed" },
              { value: "custom", label: "Custom — threshold rule" },
            ]}
          />
        </div>

        {/* What "Start counting late from" does. The control used to be a bare label with two
            options and no explanation, which is how "scheduled start" got read as "grace is
            ignored" — it never means that: grace gates lateness on both settings. */}
        <Hint>
          Grace always applies first — nobody who clocks in inside it is late, on either setting.
          Past grace, this picks what the charge is measured from:{" "}
          <strong className="text-foreground">scheduled start</strong> counts from{" "}
          {toClock(sampleStart)}, so a threshold bites sooner;{" "}
          <strong className="text-foreground">end of the grace period</strong> counts from{" "}
          {toClock(sampleStart + sampleGrace)}, so the same threshold bites {sampleGrace} minutes
          later.
        </Hint>

        {chargeMode === "custom" && (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm text-foreground">
                {thresholdRecurring ? "For every" : "If late by more than"}
              </span>
              <Input
                id="pol-th-over"
                type="number"
                min={thresholdRecurring ? 1 : 0}
                max={480}
                value={thresholdOver}
                onChange={(e) => setThresholdOver(e.target.value)}
                className="w-20"
              />
              <span className="text-sm text-foreground">
                {thresholdRecurring ? "minutes late, charge" : "minutes, charge"}
              </span>
              <Input
                id="pol-th-charge"
                type="number"
                min={0}
                max={480}
                value={thresholdCharge}
                onChange={(e) => setThresholdCharge(e.target.value)}
                className="w-20"
              />
              <span className="text-sm text-foreground">minutes&apos; pay.</span>
            </div>

            {/* The toggle. Off = charge the rung once (how this rule has always worked);
                on = charge it again for every further whole block of `over` minutes. */}
            <CheckBox
              checked={thresholdRecurring}
              onChange={setThresholdRecurring}
              label="Repeat the charge for every block of late minutes"
            />

          </div>
        )}

        {/* What it actually costs, at real clock times, computed the way the payroll engine does.
            "31 minutes late" was never the question HR asks — "what time can somebody walk in
            before this costs them" is, and the answer moves with grace, the basis and the toggle.
            Shown for BOTH charge modes: under `exact` the grace gate is the whole story. */}
        <div className="ml-7 space-y-1 border-l-2 border-border pl-4">
          <p className="text-xs text-muted-foreground">
            Example — {sample ? `${sample.name}: ` : ""}
            {toClock(sampleStart)} start, {sampleGrace} min grace.
          </p>
          {previewMarks.map((t) => (
            <p key={t} className="text-xs text-muted-foreground">
              In at <strong className="text-foreground">{toClock(t)}</strong> →{" "}
              <strong className="text-foreground">
                {asDuration(lateCostAt(t, sampleDay, rule))}
              </strong>{" "}
              deducted{t <= sampleStart + sampleGrace ? " (still within grace)" : ""}
            </p>
          ))}
        </div>

        {chargeMode === "exact" ? (
          <Hint>
            Exact charges only the minutes late — the proportionate, Art. 113-safe default. Grace is
            still honoured: an arrival inside it costs nothing.
          </Hint>
        ) : (
          <div className="rounded-lg border border-destructive bg-destructive/5 p-3">
            <p className="text-xs text-destructive">
              A threshold rule charges a flat amount that can exceed the actual minutes late (e.g. 16 min late
              → a full hour docked). This may be an unlawful penalty under Labor Code Art. 113 — enable only
              with legal review.
              {thresholdRecurring && (
                <>
                  {" "}
                  <strong>
                    Repeating multiplies that gap: the charge keeps compounding with the lateness, so a
                    long delay can reach a whole day&apos;s pay.
                  </strong>{" "}
                  A single day is still capped at one day&apos;s pay, but nothing below that cap is.
                </>
              )}
            </p>
          </div>
        )}
        <Hint>Leaving early is always billed to the exact minute. A single day can never cost more than one day&apos;s pay.</Hint>
      </div>
    );
  };

  // --- Geofenced clock-in ---

  // Drop this browser's fix into the pin boxes. HR sets this up sitting in the office, so
  // "where I am now" IS the office — far more reliable than typing coordinates by hand.
  function pinCurrentLocation() {
    if (!navigator.geolocation) {
      toast.error("This browser can't read a location.");
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setOfficeLat(pos.coords.latitude.toFixed(7));
        setOfficeLng(pos.coords.longitude.toFixed(7));
        setLocating(false);
        toast.success(`Pinned — accurate to about ${Math.round(pos.coords.accuracy)} m.`);
      },
      () => {
        setLocating(false);
        // The usual cause is an insecure origin: geolocation only works on https or
        // localhost, so this is also what HR will see on an http:// deployment.
        toast.error("Couldn't read this device's location. Allow location access, and check the site is on https.");
      },
      { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
    );
  }

  // Blank rows are the user still typing, not a value — they never reach the API.
  const cleanIps = () => officeIps.map((ip) => ip.trim()).filter(Boolean);

  const geofenceFields = () => ({
    geofence_enabled: geofenceOn,
    office_lat: officeLat.trim() === "" ? null : Number(officeLat),
    office_lng: officeLng.trim() === "" ? null : Number(officeLng),
    office_radius_m: Number(officeRadius),
    office_ips: cleanIps(),
  });

  function guardGeofence(): boolean {
    const bad = cleanIps().find((ip) => !isIpish(ip));
    if (bad) {
      toast.error(`"${bad}" isn't a valid IP address.`);
      return false;
    }
    // A lone coordinate is meaningless — the server rejects it, so catch it here with a
    // sentence HR can act on. An empty pair is fine: that's "no pin set yet".
    if ((officeLat.trim() === "") !== (officeLng.trim() === "")) {
      toast.error("Enter both a latitude and a longitude, or leave both blank.");
      return false;
    }
    return true;
  }

  // On, but with nothing to check against. The server fails OPEN on this (§2.5) — better a
  // normal clock-in than a company locked out — but HR must be told it isn't enforcing.
  const formUncappedAuto =
    overtimeMode === "auto_after_threshold" &&
    Number(otDailyCap) === 0 &&
    Number(otPeriodCap) === 0;
  const storedUncappedAuto =
    policy?.overtime_mode === "auto_after_threshold" &&
    (policy?.auto_overtime_daily_cap_minutes ?? 0) === 0 &&
    (policy?.auto_overtime_period_cap_minutes ?? 0) === 0;
  const needsCapAck = formUncappedAuto && !storedUncappedAuto;

  const geofenceIncomplete =
    geofenceOn && (cleanIps().length === 0 || officeLat.trim() === "" || officeLng.trim() === "");

  return (
    <div className="space-y-6">
      {/*
       * HOW FAR A SAVE ON THIS PAGE REACHES. Stated ONCE, at the top, rather than repeated in
       * each money-moving dialog: every rule here is read live by PayrollService at compute time
       * (there is no effective-date on the attendance policy, unlike allowances and salary), so
       * the caveat is identical for all of them — and a per-card notice would silently miss
       * whichever card is added next. The cards' own warnings say what a rule DOES; this says
       * which payslips it can still change.
       */}
      <div className="rounded-lg border border-success bg-success-subtle p-4">
        <p className="text-xs text-foreground">
          <strong>A change here reaches the whole of the cycle that is still open — it is not
          split at the date you save it.</strong>{" "}
          Payroll prices each cycle using the rules in force on its <strong>last day</strong>,
          so saving today covers the open cycle and every cycle after it, and seals when that
          cycle ends. Cycles that have already ended keep the rules they were run under, and
          cannot be changed by editing this page — fix one by correcting its payslips. A{" "}
          <strong>locked</strong> run and an already-<strong>disbursed</strong> payslip can
          never be repriced at all.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {POLICY_CARDS.map((card) => (
          <button
            key={card.key}
            type="button"
            onClick={() => setOpenCard(card.key)}
            className="rounded-card border border-border bg-card p-6 text-left transition-colors hover:border-brand/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
          >
            <h3 className="text-h3 font-bold text-foreground">{card.title}</h3>
            <p className="mt-1.5 text-sm text-muted-foreground">{card.description}</p>
          </button>
        ))}
      </div>

      {/*
       * Violation flags — the colour ladder HR sets. Uses the same four alert levels as
       * the Attendance modal legend (Low/Informational → Caution → Warning → Critical).
       * Disciplinary only; never pay.
       */}
      <SettingsDialog
        card={CARD.violation}
        open={openCard === "violation"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "violation"}
        onSave={() => saveCard("violation", violationFields(), guardLadder)}
      >
        <div className="space-y-3">
          <p className="text-[13px] font-semibold text-foreground">Lateness — lates in a month</p>
          <div className="grid grid-cols-2 items-end gap-4 sm:grid-cols-4">
            <NumberField id="pol-late-low" label="Low / Informational at" value={lateLow} onChange={setLateLow} min={0} max={31} dot="green" />
            <NumberField id="pol-late-caution" label="Caution at" value={lateCaution} onChange={setLateCaution} min={Number(lateLow) + 1} max={31} dot="yellow" />
            <NumberField id="pol-late-warning" label="Warning at" value={lateWarning} onChange={setLateWarning} min={Number(lateCaution) + 1} max={31} dot="orange" />
            <NumberField id="pol-late-critical" label="Critical at" value={lateCritical} onChange={setLateCritical} min={Number(lateWarning) + 1} max={31} dot="red" />
          </div>
        </div>
        <div className="space-y-3">
          <p className="text-[13px] font-semibold text-foreground">
            Unauthorised absence — consecutive days with no approved leave
          </p>
          <div className="grid grid-cols-2 items-end gap-4 sm:grid-cols-4">
            <NumberField id="pol-absent-low" label="Low / Informational at" value={absentLow} onChange={setAbsentLow} min={0} max={31} dot="green" />
            <NumberField id="pol-absent-caution" label="Caution at" value={absentCaution} onChange={setAbsentCaution} min={Number(absentLow) + 1} max={31} dot="yellow" />
            <NumberField id="pol-absent-warning" label="Warning at" value={absentWarning} onChange={setAbsentWarning} min={Number(absentCaution) + 1} max={31} dot="orange" />
            <NumberField id="pol-absent-critical" label="Critical / AWOL at" value={absentCritical} onChange={setAbsentCritical} min={Number(absentWarning) + 1} max={31} dot="red" />
          </div>
          <Hint>
            Counts only days with <strong>no leave of any kind</strong> — including unpaid leave
            (LWOP). A day covered by any approved leave is never an unauthorised absence.
          </Hint>
        </div>
        <div className="space-y-3">
          <p className="text-[13px] font-semibold text-foreground">
            Missed clock-out — times this month
          </p>
          <div className="grid grid-cols-2 items-end gap-4 sm:grid-cols-4">
            <NumberField id="pol-missed-low" label="Low / Informational at" value={missedLow} onChange={setMissedLow} min={0} max={31} dot="green" />
            <NumberField id="pol-missed-caution" label="Caution at" value={missedCaution} onChange={setMissedCaution} min={Number(missedLow) + 1} max={31} dot="yellow" />
            <NumberField id="pol-missed-warning" label="Warning at" value={missedWarning} onChange={setMissedWarning} min={Number(missedCaution) + 1} max={31} dot="orange" />
            <NumberField id="pol-missed-critical" label="Critical at" value={missedCritical} onChange={setMissedCritical} min={Number(missedWarning) + 1} max={31} dot="red" />
          </div>
          <Hint>
            A forgotten clock-out is auto-credited to the scheduled end time — it never docks pay and
            never counts as overtime; these levels only set its colour in the Attendance flags.
          </Hint>
        </div>
        {/* over-long breaks became a real flag; these set its colour. */}
        <div className="space-y-3">
          <p className="text-[13px] font-semibold text-foreground">
            Over-long break — days this month
          </p>
          <div className="grid grid-cols-2 items-end gap-4 sm:grid-cols-4">
            <NumberField id="pol-break-low" label="Low / Informational at" value={breakLow} onChange={setBreakLow} min={0} max={31} dot="green" />
            <NumberField id="pol-break-caution" label="Caution at" value={breakCaution} onChange={setBreakCaution} min={Number(breakLow) + 1} max={31} dot="yellow" />
            <NumberField id="pol-break-warning" label="Warning at" value={breakWarning} onChange={setBreakWarning} min={Number(breakCaution) + 1} max={31} dot="orange" />
            <NumberField id="pol-break-critical" label="Critical at" value={breakCritical} onChange={setBreakCritical} min={Number(breakWarning) + 1} max={31} dot="red" />
          </div>
          <Hint>
            A day counts when break + lunch runs past that employee&apos;s own schedule allowance by
            more than the grace set under <strong>Break overage</strong>. Flag only — a long
            break never docks pay.
          </Hint>
        </div>
        <CheckBox
          checked={monthlyReset}
          onChange={setMonthlyReset}
          label="Reset the late tally at the start of each calendar month"
        />
        <Hint>
          Off = the late count carries over month to month, coming down only when HR marks a
          violation resolved.
        </Hint>
        <Hint>
          Each level must use a higher number than the one before it (0 is allowed for the lowest).
          These exact numbers appear in the Attendance modal&apos;s Alert Color Legend.
        </Hint>
      </SettingsDialog>

      {/* Time-based pay deductions — lateness (two exclusive switches) + undertime. */}
      <SettingsDialog
        card={CARD.time_deductions}
        open={openCard === "time_deductions"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "time_deductions"}
        onSave={() =>
          saveCard("time_deductions", {
            ...chargeFields(),
            late_penalty_enabled: latePenaltyEnabled,
            lates_per_deduction: Number(latesPerDeduction),
            undertime_deduction_mode: undertimeMode,
            missing_timeout_allowance_minutes: Number(timeoutAllowance),
            daily_deduction_cap: 1,
          }, guardTimeDeductions)
        }
      >
        {/* Lateness — pick at most ONE of the two charges. */}
        <SubHeading>When someone is late</SubHeading>
        <p className="text-xs text-muted-foreground">
          Choose at most one way to charge a late — switching one on switches the other off, so the
          same late arrival is never deducted twice.
        </p>

        {/* Switch 1 — deduct the actual minutes late. Turns the penalty off. */}
        <CheckBox
          checked={lateChargeOn}
          onChange={(v) => {
            setLateChargeOn(v);
            if (v) setLatePenaltyEnabled(false);
          }}
          label="Deduct pay for the actual time they were late"
        />
        {lateChargeOn && chargeControls()}

        {/* Switch 2 — extra full-day penalty per N lates. Turns the per-time charge off. */}
        <SubDivider />
        <CheckBox
          checked={latePenaltyEnabled}
          onChange={(v) => {
            setLatePenaltyEnabled(v);
            if (v) setLateChargeOn(false);
          }}
          label="Add an extra full-day penalty for repeated lates"
        />
        {latePenaltyEnabled && (
          <div className="ml-7 flex flex-wrap items-center gap-2 border-l-2 border-border pl-4">
            <span className="text-sm text-foreground">Deduct 1 full day for every</span>
            <Input
              id="pol-late-n"
              type="number"
              min={1}
              max={30}
              value={latesPerDeduction}
              onChange={(e) => setLatesPerDeduction(e.target.value)}
              className="w-20"
            />
            <span className="text-sm text-foreground">lates in a pay period.</span>
          </div>
        )}

        <SubDivider />

        {/* Undertime & missing clock-out */}
        <SubHeading>When someone leaves early</SubHeading>
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-undertime-mode"
            label="Deduct pay for leaving early?"
            value={undertimeMode}
            onChange={(v) => setUndertimeMode(v as UndertimeDeductionMode)}
            options={[
              { value: "none", label: "No — leaving early is free" },
              { value: "per_hour", label: "Yes — deduct for the time they left short" },
            ]}
          />
        </div>
        <Hint>Leaving early is billed to the exact minute.</Hint>

        <SubDivider />

        {/* Item 5 — the missing time-out policy. */}
        <SubHeading>When someone forgets to clock out</SubHeading>
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm text-foreground">Wait</span>
          <Input
            id="pol-timeout-allowance"
            type="number"
            min={30}
            max={720}
            step={30}
            value={timeoutAllowance}
            onChange={(e) => setTimeoutAllowance(e.target.value)}
            className="w-24"
          />
          <span className="text-sm text-foreground">
            minutes after their shift ends, then treat the shift as finished.
          </span>
        </div>
        <Hint>
          Counted from <strong>each person&apos;s own shift end</strong>, never a fixed hour — so a
          9-to-6 shift is settled at 9 PM and a 10 PM–6 AM shift at 9 AM the next morning. Anyone
          who clocks out themselves before then is untouched, so there is no rush while they finish
          their task list.
        </Hint>
        <Hint>
          The shift is then paid up to its <strong>scheduled end</strong>: a full ordinary day,
          night differential included, with <strong>no overtime and no undertime</strong>. An
          approved overtime request does not extend it — overtime needs a real clock-out, so those
          minutes only pay once HR corrects the record. It stays flagged in Violation history (set
          its alert levels under <strong>Violation flags</strong>).
        </Hint>
        {/*
         * Item 11 — the rule was stated but not where its consequences land. HR reading this
         * should not have to discover the queue that lists the days it could not settle.
         */}
        <Hint>
          Days this <em>cannot</em> settle — someone who clocked in on a rest day, has no schedule
          assigned, or has an approved overtime request with no clock-out behind it — are listed
          under <strong>Open shifts</strong> on the Attendance page, where you can correct them.
        </Hint>
      </SettingsDialog>

      {/* Early arrival & overtime. */}
      <SettingsDialog
        card={CARD.early_ot}
        open={openCard === "early_ot"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "early_ot"}
        //  / item 8 — early_arrival_mode is deliberately NOT sent, and no longer has a
        // control at all: no engine reads it and the API rejects anything but "none", so echoing a
        // stored value back would 422 and block HR from saving the overtime settings.
        onSave={() =>
          saveCard(
            "early_ot",
            {
              early_clock_in_mode: earlyClockIn,
              overtime_mode: overtimeMode,
              overtime_threshold_minutes: Number(overtimeThreshold),
              auto_overtime_daily_cap_minutes: Number(otDailyCap),
              auto_overtime_period_cap_minutes: Number(otPeriodCap),
              auto_overtime_review_minutes: Number(otReviewMinutes),
            },
            guardOvertimeCaps,
          )
        }
      >
        <SubHeading>When someone times in before their shift starts</SubHeading>
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-early-clock-in"
            label="Early time in"
            value={earlyClockIn}
            onChange={(v) => setEarlyClockIn(v as EarlyClockInMode)}
            options={[
              { value: "allow", label: "Record it at the time they arrived" },
              { value: "defer", label: "Accept it, but count it from their shift start" },
              { value: "block", label: "Don't let them time in yet" },
            ]}
          />
        </div>
        <Hint>
          {earlyClockIn === "block" ? (
            <>
              The <strong>Time in</strong> button stays disabled until each person&apos;s own
              scheduled start, and the server refuses an early punch either way.
            </>
          ) : earlyClockIn === "defer" ? (
            <>
              They can press <strong>Time in</strong> the moment they arrive and forget about
              it — but someone who arrives at 7:30 for an 8:00 shift is recorded as timing in
              at <strong>8:00</strong>. Nobody waits at the door, nobody is paid for arriving
              early, and a deferred time in is never late. Until their shift starts their
              time in shows as <strong>queued</strong>, and breaks and time out stay locked.
            </>
          ) : (
            "Anyone can time in as early as they like, and the punch is recorded at the actual time they pressed it."
          )}
        </Hint>
        <Hint>
          Staff with no schedule assigned are held to the default one. On a day that
          isn&apos;t one of their workdays this never applies — there&apos;s no shift to be
          early for, so the punch is recorded as-is. Lateness is unaffected either way.
        </Hint>
        <SubDivider />

        {/*
         * Item 8 — the disabled "Early arrival" select that used to head this row is GONE. It
         * offered three ways to value early minutes, no engine read any of them, and a control
         * that cannot be operated still reads as a feature that exists. Early minutes are simply
         * ignored; the sentence below says so, which is the honest version of the same fact.
         */}
        <SubHeading>How extra time is paid</SubHeading>
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-ot"
            label="Overtime"
            value={overtimeMode}
            onChange={(v) => setOvertimeMode(v as OvertimeMode)}
            options={[
              { value: "none", label: "No overtime" },
              { value: "approved_only", label: "Only when approved" },
              { value: "auto_after_threshold", label: "Auto after a threshold" },
            ]}
          />
          {overtimeMode === "auto_after_threshold" && (
            <>
              <NumberField id="pol-ot-threshold" label="Minimum overtime (minutes)" value={overtimeThreshold} onChange={setOvertimeThreshold} min={0} max={1440} />
              {/*
               * The ceiling on what pays with nobody looking. 0 = uncapped, which is
               * what every existing company is on, so the labels have to say so.
               */}
              <NumberField
                id="pol-ot-daily-cap"
                label="Auto-pay cap per day (minutes, 0 = no cap)"
                value={otDailyCap}
                onChange={setOtDailyCap}
                min={0}
                max={1440}
              />
              <NumberField
                id="pol-ot-period-cap"
                label="Auto-pay cap per pay period (minutes, 0 = no cap)"
                value={otPeriodCap}
                onChange={setOtPeriodCap}
                min={0}
                max={65535}
              />
              {/*
               * Item 7 — the one number here that touches no money. It puts a day in front of HR
               * and pays it in full either way, which is why it can be on by default while the
               * caps above are off.
               */}
              <NumberField
                id="pol-ot-review"
                label="Flag a day for review over (minutes, 0 = never)"
                value={otReviewMinutes}
                onChange={setOtReviewMinutes}
                min={0}
                max={1440}
              />
            </>
          )}
        </div>
        {overtimeMode === "auto_after_threshold" && (
          <Hint>
            <strong>Flag for review</strong> is not a cap. A day over it is still paid in full and
            automatically — it just appears under <strong>Overtime to review</strong> on the
            Overtime page so somebody sees it. Days that were both late and overtime are listed
            there too, whatever this is set to.
          </Hint>
        )}
        {overtimeMode === "auto_after_threshold" && (
          <div className="rounded-lg border border-warning bg-warning-subtle p-3">
            <p className="text-xs text-foreground">
              Auto mode pays overtime from the <strong>time clock alone</strong> — staying past the
              scheduled end by at least this many minutes is paid without anyone approving it. Philippine
              practice is that overtime should be <strong>authorised</strong>; &ldquo;Only when approved&rdquo;
              is the safer setting. A day&apos;s whole overrun is paid once it passes the minimum, and a
              missing clock-out never counts as overtime.
            </p>
            {/*
             * The late-arrival case surprises people, so say it outright rather than let HR
             * discover it on a payslip. See AttendanceService::autoOvertimeMinutesFor.
             */}
            <p className="mt-2 text-xs text-foreground">
              <strong>Arriving late does not cancel out overtime.</strong>{" "}
              Someone who comes in an hour
              late and stays an hour past the shift is paid that overtime, and the late hour is deducted
              separately. The two are never netted off — Philippine law (Art. 88) forbids offsetting
              undertime with overtime, because overtime is worth more per hour. If that trade looks
              wrong for your company, use &ldquo;Only when approved&rdquo; instead.
            </p>
          </div>
        )}
        {/*
         * 0 caps = UNLIMITED, and 0 is what the fields start on — so this state is reached by
         * leaving them alone, not by choosing it. Confirmed once, on the save that enters it.
         */}
        {needsCapAck && (
          <div className="rounded-lg border border-danger bg-danger-subtle p-3">
            <p className="text-xs text-foreground">
              <strong>Both auto-pay caps are 0, which means no ceiling.</strong> Every minute
              anyone clocks past their shift will be paid at the overtime rate with nobody
              approving it. A clock-out corrected to the next morning would pay the whole night.
              Set a cap to hold the excess for approval instead.
            </p>
            <div className="mt-2">
              <CheckBox
                checked={capsAcknowledged}
                onChange={setCapsAcknowledged}
                label="I understand — pay automatic overtime with no ceiling"
              />
            </div>
          </div>
        )}
        <Hint>
          <strong>Arriving early is never paid.</strong> Turning up at 7:30 for an 8:00 shift earns
          nothing extra and cannot offset leaving early later the same day — only time past the
          scheduled <em>end</em> can become overtime.
        </Hint>
      </SettingsDialog>

      {/*
       * Working a rest day. Its own card rather than a line in "Early arrival & overtime":
       * this decides whether a whole request queue exists, and HR looks for it by name.
       */}
      <SettingsDialog
        card={CARD.rest_day_work}
        open={openCard === "rest_day_work"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "rest_day_work"}
        onSave={() =>
          saveCard("rest_day_work", { rest_day_work_requires_request: restDayNeedsRequest })
        }
      >
        <SubHeading>When someone times in on a day that isn&apos;t theirs</SubHeading>
        <CheckBox
          checked={restDayNeedsRequest}
          onChange={setRestDayNeedsRequest}
          label="They have to ask HR first"
        />
        <Hint>
          {restDayNeedsRequest ? (
            <>
              <strong>Time in</strong> becomes <strong>Request to work today</strong>, and the
              request lands under <strong>Work Requests</strong> on the Work Schedule requests
              page. Approving it times them in at <strong>the moment they pressed the button</strong>
              , not the moment you approve — so however long you take costs them nothing. A request
              nobody decides lapses after 24 hours, because approving it later would back-date a
              shift.
            </>
          ) : (
            <>
              They time in as they would on any other day and the punch is recorded straight away.
              Nothing reaches your queue.
            </>
          )}
        </Hint>
        <Hint>
          <strong>The pay is the same either way.</strong> A rest day that gets worked earns the
          rest-day premium off the attendance record itself, not off an approval — so this only
          decides who has to agree before the day is recorded. There is no lateness on a rest day
          under either setting: there is no shift to be late for.
        </Hint>
        <Hint>
          Turn it off when weekend or rota coverage is routine and the queue would only ever be
          rubber-stamped. Leave it on when a worked rest day is exceptional and someone should be
          choosing to spend the premium.
        </Hint>
        <Hint>
          This does not cover <strong>work suspensions</strong> — a typhoon or a shutdown. Whether
          people may time in on one of those is set per declaration, on{" "}
          <strong>Company → Holidays &amp; Suspensions</strong>.
        </Hint>
      </SettingsDialog>

      {/*
       * Management attendance. Two switches that look alike and are not: the first decides
       * whether the exemption exists at all, the second is a pay rule that applies only while
       * someone is flagged management.
       */}
      <SettingsDialog
        card={CARD.management}
        open={openCard === "management"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "management"}
        onSave={() =>
          saveCard("management", {
            management_auto_attendance: managementAuto,
            management_holiday_premium: managementHolidayPremium,
          })
        }
      >
        <SubHeading>Automatic time in and time out</SubHeading>
        <CheckBox
          checked={managementAuto}
          onChange={setManagementAuto}
          label="Time management staff in and out automatically"
        />
        <Hint>
          {managementAuto ? (
            <>
              Anyone marked <strong>Management</strong> on their record never uses the time
              clock. Each night the platform writes their day from their own schedule, so they
              show as <strong>present</strong> on the roster, raise no late or absent flags, and
              cannot file half-day, early-out or overtime requests. Leave still works normally.
            </>
          ) : (
            <>
              Management uses the time clock like everyone else — they time in and out, arrive
              late, and are flagged for it. Nothing is generated for them from tonight on;{" "}
              <strong>days already generated stay</strong>, because they are the record of the
              period they were exempt.
            </>
          )}
        </Hint>
        <Hint>
          <strong>Their pay does not change either way.</strong> Management is salaried on a
          fixed basis, so attendance never prorates it: no absence deduction, no lateness
          charge, no overtime or night differential. This switch decides only who has to punch.
        </Hint>

        <SubHeading>Holidays they work</SubHeading>
        <CheckBox
          checked={managementHolidayPremium}
          onChange={setManagementHolidayPremium}
          label="Pay management the premium for a holiday they worked"
        />
        <Hint>
          {managementHolidayPremium ? (
            <>
              A regular holiday or special non-working day they worked earns the same statutory
              premium everyone else gets, at the rates in{" "}
              <strong>Premium &amp; overtime rates</strong>.
            </>
          ) : (
            <>
              A holiday they worked pays nothing extra — the day is already inside their fixed
              salary. This is the usual treatment for salaried management, and today&apos;s
              behaviour.
            </>
          )}
        </Hint>
        <Hint>
          Payroll reads this rule <strong>as it stood during the cycle</strong>, so turning it on
          today never reprices a period that has already been paid. To pay a past holiday, save
          this with an earlier <strong>effective from</strong> date and re-run that cycle.
        </Hint>
      </SettingsDialog>

      {/*
       * Geofenced clock-in. The switch is the kill switch: off = today's clock-in, with
       * nothing else in the app changing. On but half-configured fails OPEN by design.
       */}
      <SettingsDialog
        card={CARD.geofence}
        open={openCard === "geofence"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "geofence"}
        onSave={() => saveCard("geofence", geofenceFields(), guardGeofence)}
      >
        <CheckBox
          checked={geofenceOn}
          onChange={setGeofenceOn}
          label="Check where staff are when they time in"
        />

        {!geofenceOn && (
          <Hint>
            Off — anyone can time in from anywhere, and nothing else in the app changes. Turn
            this on to make office time-ins prove they&apos;re at the office, and send everyone
            else through a selfie you approve.
          </Hint>
        )}

        {geofenceOn && (
          <div className="ml-7 space-y-5 border-l-2 border-border pl-4">
            <div className="space-y-3">
              <SubHeading>Where the office is</SubHeading>
              <div className="flex flex-wrap items-end gap-4">
                <NumberField
                  id="pol-office-lat"
                  label="Latitude"
                  value={officeLat}
                  onChange={setOfficeLat}
                  min={-90}
                  max={90}
                  step={0.0000001}
                />
                <NumberField
                  id="pol-office-lng"
                  label="Longitude"
                  value={officeLng}
                  onChange={setOfficeLng}
                  min={-180}
                  max={180}
                  step={0.0000001}
                />
                <NumberField
                  id="pol-office-radius"
                  label="Allowed distance (metres)"
                  value={officeRadius}
                  onChange={setOfficeRadius}
                  min={20}
                  max={5000}
                />
                <Button
                  type="button"
                  variant="outline"
                  onClick={pinCurrentLocation}
                  disabled={locating}
                >
                  <MapPin />
                  {locating ? "Locating…" : "Use my location"}
                </Button>
              </div>
              <Hint>
                Set this while you&apos;re sitting in the office — <strong>Use my location</strong>{" "}
                fills both boxes from this device. Phone GPS is only accurate to roughly 10–20 m,
                so keep the distance generous; <strong>200 m</strong> covers a building and its
                car park without reaching the next block.
              </Hint>
            </div>

            <SubDivider />

            <div className="space-y-3">
              <SubHeading>The office internet connection</SubHeading>
              <IpList
                ips={officeIps}
                onChange={setOfficeIps}
                yourIp={policy?.your_ip ?? null}
              />
              <Hint>
                Everyone on the office Wi-Fi shares one public IP address, which is what makes
                this work. Add it while you&apos;re <strong>on the office network</strong> — the
                button above fills in the address this connection is using right now. Location
                and IP must <strong>both</strong> match, so a spoofed GPS on home broadband still
                fails.
              </Hint>
              <Hint>
                If office time-ins suddenly start failing for everyone, the internet provider has
                probably changed your address — come back here and add the new one. Ask them for a{" "}
                <strong>static IP</strong> to stop that happening.
              </Hint>
            </div>

            {geofenceIncomplete && (
              <div className="rounded-lg border border-warning bg-warning-subtle p-3">
                <p className="text-xs text-foreground">
                  This is switched on but not finished — it needs <strong>both</strong> an office
                  location and at least one IP address. Until then it is{" "}
                  <strong>not being enforced</strong>: everyone times in normally, exactly as if
                  the switch were off. That is deliberate — a half-finished setting must never
                  lock the whole company out of the time clock.
                </p>
              </div>
            )}
          </div>
        )}
      </SettingsDialog>

      {/* Premium & overtime rates (Premium Pay epic, Phase 0). Editable, floored at DOLE minimums. */}
      <SettingsDialog
        card={CARD.premium_rates}
        open={openCard === "premium_rates"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "premium_rates"}
        onSave={() =>
          saveCard("premium_rates", {
            premium_rest_day_pct: Number(premRestDay),
            premium_special_day_pct: Number(premSpecialDay),
            premium_regular_holiday_pct: Number(premRegHoliday),
            overtime_ordinary_pct: Number(otOrdinary),
            overtime_rest_special_pct: Number(otRestSpecial),
            overtime_regular_holiday_pct: Number(otRegHoliday),
            night_diff_pct: Number(nightDiff),
            night_diff_start_time: nightDiffStart,
            night_diff_end_time: nightDiffEnd,
          }, guardNightWindow)
        }
      >
        <div className="space-y-3">
          <SubHeading>Working a premium day (first 8 hours)</SubHeading>
          <div className="flex flex-wrap items-end gap-4">
            <NumberField id="pol-prem-rest" label="Rest day worked %" value={premRestDay} onChange={setPremRestDay} min={130} max={1000} />
            <NumberField id="pol-prem-special" label="Special day worked %" value={premSpecialDay} onChange={setPremSpecialDay} min={130} max={1000} />
            <NumberField id="pol-prem-holiday" label="Regular holiday worked %" value={premRegHoliday} onChange={setPremRegHoliday} min={200} max={1000} />
          </div>
        </div>
        <div className="space-y-3">
          <SubHeading>Overtime (hours beyond 8)</SubHeading>
          <div className="flex flex-wrap items-end gap-4">
            <NumberField id="pol-ot-ordinary" label="Ordinary day OT %" value={otOrdinary} onChange={setOtOrdinary} min={125} max={1000} />
            <NumberField id="pol-ot-restspecial" label="Rest / special day OT %" value={otRestSpecial} onChange={setOtRestSpecial} min={169} max={1000} />
            <NumberField id="pol-ot-holiday" label="Regular holiday OT %" value={otRegHoliday} onChange={setOtRegHoliday} min={260} max={1000} />
          </div>
        </div>
        <div className="space-y-3">
          <SubHeading>Night differential</SubHeading>
          <div className="flex flex-wrap items-end gap-4">
            <NumberField id="pol-night" label="Night differential (+%)" value={nightDiff} onChange={setNightDiff} min={10} max={100} />
            <div className="space-y-1.5">
              <Label htmlFor="pol-night-start">Starts at</Label>
              <Input
                id="pol-night-start"
                type="time"
                value={nightDiffStart}
                onChange={(event) => setNightDiffStart(event.target.value)}
                className="w-36"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="pol-night-end">Ends at</Label>
              <Input
                id="pol-night-end"
                type="time"
                value={nightDiffEnd}
                onChange={(event) => setNightDiffEnd(event.target.value)}
                className="w-36"
              />
            </div>
          </div>
          <Hint>
            The default is <strong>10:00 PM to 6:00 AM</strong>. If the end is earlier than the
            start, the window continues through midnight.
          </Hint>
        </div>
        <div className="rounded-lg border border-warning bg-warning-subtle p-3">
          <p className="text-xs text-foreground">
            Minimums (DOLE): rest/special worked <strong>130%</strong>, regular holiday <strong>200%</strong>;
            overtime <strong>125%</strong> ordinary / <strong>169%</strong> rest·special / <strong>260%</strong>{" "}
            regular holiday; night differential <strong>+10%</strong>. The server rejects anything below these.
          </p>
        </div>
        {/* Item 13b.7 — this used to say only the holiday rate was live. All of them are. */}
        <Hint>
          <strong>All of these move money now.</strong> Holiday premium, overtime, the rest-day
          premium and the night differential each read their rate here on the next payroll run —
          changing one changes what people are paid.
        </Hint>
        {/*
         * Item 9 — the differential compounds, so raising a DAY rate raises the night pay on that
         * day too. Worth saying outright: HR would otherwise read these as five independent
         * numbers and be surprised when moving one moved another line on the payslip.
         */}
        <Hint>
          <strong>The night differential stacks on the day&apos;s own rate.</strong> It is a
          percentage of what the hour is already worth, not of the basic rate — so a night hour on
          a regular holiday earns 10% of the <em>200%</em> rate, and a night hour of overtime earns
          10% of the overtime rate. Raising a holiday, rest-day or overtime rate above therefore
          raises the night pay on those days with it.
        </Hint>
      </SettingsDialog>

      {/* Daily-rate basis. */}
      <SettingsDialog
        card={CARD.basis}
        open={openCard === "basis"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "basis"}
        onSave={() =>
          saveCard("basis", {
            daily_rate_basis: rateBasis,
            annual_divisor: Number(divisor),
            hours_per_day_divisor: Number(hoursPerDay),
          })
        }
      >
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-basis"
            label="Basis"
            value={rateBasis}
            onChange={(v) => setRateBasis(v as DailyRateBasis)}
            options={[
              { value: "period_workdays", label: "This cutoff's workdays (varies)" },
              { value: "fixed_divisor", label: "Fixed divisor (constant all year)" },
            ]}
          />
          {rateBasis === "fixed_divisor" && (
            <NumberField id="pol-divisor" label="Annual divisor (261 / 313 / 365)" value={divisor} onChange={setDivisor} min={1} max={366} />
          )}
          <NumberField id="pol-hours" label="Hours per day" value={hoursPerDay} onChange={setHoursPerDay} min={1} max={24} />
        </div>
        <Hint>
          261 = Mon–Fri office · 313 = Mon–Sat · 365 = every day paid. An hour of lateness is priced
          as the daily rate ÷ hours per day (DOLE uses 8).
        </Hint>
        {rateBasis === "fixed_divisor" && (
          <div className="rounded-lg border border-warning bg-warning-subtle p-3">
            <p className="text-xs text-foreground">
              Changing the basis or divisor changes <strong>all absence deductions</strong>, not just
              minute-based ones.
            </p>
          </div>
        )}
      </SettingsDialog>

      {/*
       * Break overage (flag only). The LIMIT lives on each work schedule; this is the
       * tolerance on top of it — see ViolationService.
       */}
      <SettingsDialog
        card={CARD.break}
        open={openCard === "break"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "break"}
        onSave={() =>
          saveCard("break", {
            lunch_grace_minutes: Number(lunchGrace),
            break_grace_minutes: Number(breakGrace),
          })
        }
      >
        <div className="flex flex-wrap items-end gap-4">
          <NumberField id="pol-lunch-grace" label="Lunch grace (minutes)" value={lunchGrace} onChange={setLunchGrace} min={0} max={240} />
          <NumberField id="pol-break-grace" label="Break grace (minutes)" value={breakGrace} onChange={setBreakGrace} min={0} max={240} />
        </div>
        <Hint>
          The allowances themselves come from each employee&apos;s <strong>work schedule</strong>
          {" "}(unpaid lunch + paid break) — set them there, per schedule, not here. A shorter-lunch
          schedule therefore gets a proportionally lower threshold instead of one company-wide
          number for everyone.
        </Hint>
        <Hint>
          Lunch and breaks are judged <strong>separately</strong>, so a short lunch can&apos;t pay
          for a long break. Set a grace to <strong>0</strong> for no tolerance at all — exactly the
          allowance still passes, one minute over is flagged.
        </Hint>
      </SettingsDialog>
    </div>
  );
}

// ---- Section D: payroll settings ----

/**
 * Company-wide payroll configuration. Structurally the same animal as the attendance
 * policy — one singleton row, dated into a versions table — with one crucial difference:
 * payroll REPLAYS these. PayrollService resolves them as of each cycle's end, so a period
 * already run keeps what it was priced with, whereas the attendance rules are read live.
 * That difference is why the two sections keep separate dates and separate banners rather
 * than sharing one control that would be a lie about one of them.
 */
type PayrollCardKey = "schedule" | "minimum_wage";

interface PayrollCard {
  key: PayrollCardKey;
  title: string;
  description: string;
}

const PAYROLL_CARDS: PayrollCard[] = [
  {
    key: "schedule",
    title: "Pay schedule & deduction timing",
    description:
      "The default pay frequency, and which cutoff in a month carries the government contributions and the withholding tax.",
  },
  {
    key: "minimum_wage",
    title: "Minimum wage check",
    description:
      "The regional daily floor from your DOLE wage order. Every run is checked against it before the money is committed. Blank skips the check.",
  },
];

const PAYROLL_CARD = Object.fromEntries(PAYROLL_CARDS.map((c) => [c.key, c])) as Record<
  PayrollCardKey,
  PayrollCard
>;

// When a month's statutory amounts land. "every" splits them across that month's cutoffs.
const TIMING_OPTIONS = [
  { value: "every", label: "Every cutoff (split)" },
  { value: "first", label: "First cutoff only" },
  { value: "last", label: "Last cutoff only" },
];

/** Contributions have no "first cutoff only". */
const CONTRIBUTION_TIMING_OPTIONS = TIMING_OPTIONS.filter((o) => o.value !== "first");

function PayrollSettingsForm() {
  const { data: settings } = usePayrollSettings();

  return (
    <section className="space-y-6">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">Payroll settings</h2>
        <p className="text-sm text-muted-foreground">
          How and when pay is computed, for every pay group.
        </p>
      </div>
      {settings ? (
        <PayrollSettingsEditor settings={settings} />
      ) : (
        <p className="text-sm text-muted-foreground">Loading payroll settings…</p>
      )}
    </section>
  );
}

function PayrollSettingsEditor({ settings }: { settings: PayrollSettings }) {
  const update = useUpdatePayrollSettings();

  const [frequency, setFrequency] = useState(settings.frequency);
  const [deductionTiming, setDeductionTiming] = useState(settings.deduction_timing);
  const [taxTiming, setTaxTiming] = useState(settings.tax_timing);
  const [allowanceTiming, setAllowanceTiming] = useState(settings.allowance_timing);
  // How many people this default actually decides the pay group for.
  const inheritingCount = settings.inheriting_employee_count ?? 0;
  // A STRING so an empty box stays empty ("no floor set") instead of collapsing to 0, which
  // would be a wage order of nothing rather than no wage order at all.
  const [minimumWage, setMinimumWage] = useState(settings.minimum_daily_wage?.toString() ?? "");
  // Blank = today, which is what every change used to be recorded as whether or not it was true.

  const [openCard, setOpenCard] = useState<PayrollCardKey | null>(null);
  const [saving, setSaving] = useState<PayrollCardKey | null>(null);

  // Re-seed from the server on open so Cancel genuinely discards. Without this, an edit
  // abandoned in one dialog would ride along with the next dialog's save — the API takes
  // the whole settings object, so every save writes every field.
  function openCardFresh(key: PayrollCardKey) {
    setFrequency(settings.frequency);
    setDeductionTiming(settings.deduction_timing);
    setTaxTiming(settings.tax_timing);
    setAllowanceTiming(settings.allowance_timing);
    setMinimumWage(settings.minimum_daily_wage?.toString() ?? "");
    setOpenCard(key);
  }

  async function save(key: PayrollCardKey) {
    setSaving(key);
    try {
      await update.mutateAsync({
        frequency,
        deduction_timing: deductionTiming,
        tax_timing: taxTiming,
        allowance_timing: allowanceTiming,
        minimum_daily_wage: minimumWage.trim() === "" ? null : Number(minimumWage),
      });
      toast.success("Saved");
      setOpenCard(null);
    } catch (err) {
      toast.error(saveError(err));
    } finally {
      setSaving(null);
    }
  }

  return (
    <div className="space-y-6">
      {/*
       * Deliberately not the colour or the wording of the attendance banner above: these
       * settings are replayed per cycle, those are read live. Two notices that looked the
       * same would teach HR the wrong rule about one of them.
       */}
      <div className="rounded-lg border border-info bg-info-subtle p-4">
        <p className="text-xs text-foreground">
          <strong>Each cycle is priced with the settings in force for that cycle.</strong>{" "}
          A change saved today does not reprice a period that has already been run, and
          re-running an old cycle keeps what it was priced with. To change a period that has
          already been run, correct its payslips instead.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {PAYROLL_CARDS.map((card) => (
          <button
            key={card.key}
            type="button"
            onClick={() => openCardFresh(card.key)}
            className="rounded-card border border-border bg-card p-6 text-left transition-colors hover:border-brand/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
          >
            <h3 className="text-h3 font-bold text-foreground">{card.title}</h3>
            <p className="mt-1.5 text-sm text-muted-foreground">{card.description}</p>
          </button>
        ))}
      </div>

      <SettingsDialog
        card={PAYROLL_CARD.schedule}
        open={openCard === "schedule"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "schedule"}
        onSave={() => save("schedule")}
      >
        <FieldSelect
          id="pay-frequency"
          label="Default pay schedule"
          value={frequency}
          onChange={setFrequency}
          options={PAY_FREQUENCIES.map((f) => ({ value: f.value, label: f.label }))}
        />
        <Hint>
          Used for employees who have no frequency of their own. Changing it moves those
          employees into a different pay group.
        </Hint>

        {/*
         * THE COUNT, BEFORE THE SAVE.
         * Pay group membership is COALESCE(pay_frequency, <this setting>), so this one field
         * decides the pay group for everyone who never set their own. Changing it re-assigns all
         * of them at once and writes nothing to their records — the first symptom is a payroll
         * run that no longer finds them, or a re-run that cannot clean up after them because
         * their old payslip is already committed to a payment batch. Shown only once the value
         * has actually been changed, so it reads as a consequence rather than decoration.
         */}
        {inheritingCount > 0 && frequency !== settings.frequency && (
          <div className="mt-2 rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2 text-xs text-foreground">
            <strong>
              {inheritingCount} employee{inheritingCount === 1 ? "" : "s"}
            </strong>{" "}
            {inheritingCount === 1 ? "has" : "have"} no pay schedule of their own and follow this
            setting. Saving moves them from the{" "}
            {payFrequencyLabel(settings.frequency).toLowerCase()} pay group to the{" "}
            {payFrequencyLabel(frequency).toLowerCase()} one. Nothing is written to their records —
            set a pay schedule on the employee to pin them.
          </div>
        )}

        <SubDivider />

        <FieldSelect
          id="pay-deduction-timing"
          label="Government contributions"
          value={deductionTiming}
          onChange={setDeductionTiming}
          options={CONTRIBUTION_TIMING_OPTIONS}
        />
        <Hint>
          When SSS, PhilHealth and Pag-IBIG come out. Split evenly, the first cutoff charges a
          provisional half against the contracted salary and the last cutoff corrects it to what
          the month actually earned. On the last cutoff only, the whole month is charged once,
          when the earnings are already known. Either way the month totals the same — there is no
          &ldquo;first cutoff only&rdquo;, because the first cutoff cannot know what the month
          will earn.
        </Hint>

        <SubDivider />

        <FieldSelect
          id="pay-tax-timing"
          label="Withholding tax"
          value={taxTiming}
          onChange={setTaxTiming}
          options={TIMING_OPTIONS}
        />
        <Hint>When income tax is withheld.</Hint>

        <SubDivider />

        <FieldSelect
          id="pay-allowance-timing"
          label="Allowances"
          value={allowanceTiming}
          onChange={setAllowanceTiming}
          options={TIMING_OPTIONS}
        />
        <Hint>
          Allowances are configured as monthly amounts. Split evenly, a ₱1,500 allowance shows as
          ₱750 on each of two semi-monthly payslips; paid on one cutoff it arrives whole. The
          monthly total is the same either way.
        </Hint>
      </SettingsDialog>

      <SettingsDialog
        card={PAYROLL_CARD.minimum_wage}
        open={openCard === "minimum_wage"}
        onOpenChange={(o) => !o && setOpenCard(null)}
        pending={saving === "minimum_wage"}
        onSave={() => save("minimum_wage")}
      >
        <div className="w-full max-w-[360px] space-y-1.5">
          <Label htmlFor="pay-min-wage">Minimum daily wage (₱)</Label>
          <Input
            id="pay-min-wage"
            type="number"
            min={0}
            max={99999}
            step={0.01}
            placeholder="Not set"
            value={minimumWage}
            onChange={(e) => setMinimumWage(e.target.value)}
          />
        </div>
        <Hint>
          From the DOLE wage order for your region. Before a run is paid out, every employee it
          covers is checked against this figure and anyone below it is flagged.
        </Hint>
        <Hint>
          Leave it blank to skip the check. There is no national figure to fall back on, so a
          guess here would flag the wrong people.
        </Hint>
      </SettingsDialog>
    </div>
  );
}

/**
 * One settings group's editor, shared by the attendance and payroll sections. The
 * card's own title and description head the dialog — HR opened it on the strength
 * of that summary, so repeating it here is what keeps the two readable as the same
 * thing.
 */
function SettingsDialog({
  card,
  open,
  onOpenChange,
  pending,
  onSave,
  children,
}: {
  card: { title: string; description: string };
  open: boolean;
  onOpenChange: (open: boolean) => void;
  pending: boolean;
  onSave: () => void;
  children: ReactNode;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{card.title}</DialogTitle>
          <DialogDescription>{card.description}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">{children}</div>

        <DialogFooter>
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Cancel
            </Button>
          </DialogClose>
          <Button type="button" onClick={onSave} disabled={pending}>
            {pending ? "Saving…" : "Save"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// A cheap client-side sanity check so a typo gets a plain sentence instead of a 422 keyed
// by array index. The server's `ip` rule is the real gate — this only has to be close.
function isIpish(value: string): boolean {
  if (value.includes(":")) return /^[0-9a-fA-F:]+$/.test(value); // IPv6, loosely
  const parts = value.split(".");
  return (
    parts.length === 4 &&
    parts.every((p) => /^\d{1,3}$/.test(p) && Number(p) <= 255)
  );
}

// The allowed office IPs — one editable row each, plus a one-click add of whatever address
// the SERVER currently sees this browser as (so HR never has to find it themselves).
function IpList({
  ips,
  onChange,
  yourIp,
}: {
  ips: string[];
  onChange: (next: string[]) => void;
  yourIp: string | null;
}) {
  const alreadyListed = yourIp != null && ips.map((i) => i.trim()).includes(yourIp);

  return (
    <div className="space-y-2">
      {ips.length === 0 && (
        <p className="text-sm text-muted-foreground">No addresses added yet.</p>
      )}
      {ips.map((ip, i) => (
        <div key={i} className="flex items-center gap-2">
          <Input
            aria-label={`Office IP address ${i + 1}`}
            value={ip}
            placeholder="203.0.113.10"
            onChange={(e) => onChange(ips.map((v, j) => (j === i ? e.target.value : v)))}
            className="w-56"
          />
          <Button
            size="icon-sm"
            variant="ghost"
            type="button"
            onClick={() => onChange(ips.filter((_, j) => j !== i))}
          >
            <Trash2 />
            <span className="sr-only">Remove address</span>
          </Button>
        </div>
      ))}
      <div className="flex flex-wrap items-center gap-2 pt-1">
        <Button size="sm" variant="outline" type="button" onClick={() => onChange([...ips, ""])}>
          <Plus />
          Add address
        </Button>
        {yourIp && (
          <Button
            size="sm"
            variant="outline"
            type="button"
            disabled={alreadyListed}
            onClick={() => onChange([...ips, yourIp])}
          >
            {alreadyListed ? `This connection (${yourIp}) is listed` : `Use this connection (${yourIp})`}
          </Button>
        )}
      </div>
    </div>
  );
}

function Hint({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={cn("text-xs text-muted-foreground", className)}>{children}</p>;
}

// Subsection heading inside a combined policy card.
function SubHeading({ children }: { children: ReactNode }) {
  return <p className="text-[13px] font-semibold text-foreground">{children}</p>;
}

// Light divider between subsections of one card.
function SubDivider() {
  return <div className="h-px w-full bg-border" />;
}

function FieldSelect({
  id,
  label,
  value,
  onChange,
  options,
  disabled,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: { value: string; label: string }[];
  disabled?: boolean;
}) {
  return (
    <div className="w-full max-w-[360px] space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <SimpleSelect id={id} className="h-10" value={value} onValueChange={onChange} options={options} disabled={disabled} />
    </div>
  );
}

// Severity dot colours — the SAME classes the Attendance modal legend uses, so the
// Rules card and the modal read as one system.
const SEVERITY_DOT: Record<"green" | "yellow" | "orange" | "red", string> = {
  green: "bg-success",
  yellow: "bg-warning",
  orange: "bg-severity-orange",
  red: "bg-danger",
};

function NumberField({
  id,
  label,
  value,
  onChange,
  min,
  max,
  step,
  dot,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  min?: number;
  max?: number;
  step?: number;
  dot?: keyof typeof SEVERITY_DOT;
}) {
  return (
    // max-w-full so the same field also fits a narrow grid cell inside a dialog.
    <div className="w-48 max-w-full space-y-1.5">
      <Label htmlFor={id} className="flex items-center gap-1.5">
        {dot && <span className={cn("size-2 shrink-0 rounded-full", SEVERITY_DOT[dot])} />}
        {label}
      </Label>
      <Input id={id} type="number" min={min} max={max} step={step} value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}

// A green filled checkbox matching the Figma design: filled brand-green when on,
// empty bordered box when off. Controlled via `checked` / `onChange`.
function CheckBox({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  label: string;
}) {
  return (
    <label className="flex cursor-pointer items-center gap-2.5">
      <button
        type="button"
        role="checkbox"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={cn(
          "flex size-5 items-center justify-center rounded transition-colors",
          checked ? "bg-success text-white" : "border border-input bg-card",
        )}
      >
        {checked && <Check className="size-3" strokeWidth={3.5} />}
      </button>
      <span className="text-sm text-foreground">{label}</span>
    </label>
  );
}
