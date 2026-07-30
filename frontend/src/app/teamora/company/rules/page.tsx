"use client";

import { useState, type ReactNode } from "react";
import { Plus, Pencil, Trash2, Check } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PageHeader } from "@/modules/hr/components/page-header";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { WorkScheduleFormDialog } from "@/modules/hr/components/work-schedule-form-dialog";
import {
  useWorkSchedules,
  useDeleteWorkSchedule,
  useAttendancePolicy,
  useUpdateAttendancePolicy,
} from "@/modules/hr/hooks/use-rules";
import {
  formatWorkdays,
  type DailyRateBasis,
  type EarlyArrivalMode,
  type LateDeductionMode,
  type LateTier,
  type OvertimeMode,
  type UndertimeDeductionMode,
  type ViolationRule,
  type ViolationRules,
  type WorkSchedule,
} from "@/modules/hr/types/rules";
import { SimpleSelect } from "@/modules/hr/ui/select";

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
        title="Company Rules"
        subtitle="Work schedules and the attendance policy the violation system reads. Set individual schedules in each employee's profile."
        actions={
          <Button onClick={openCreate}>
            <Plus />
            Add Schedule
          </Button>
        }
      />

      <div className="mt-8 space-y-8">
        {/* Section A — schedules */}
        <section>
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

        {/* Section B — attendance policy */}
        <AttendancePolicyForm />
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

// ---- Section A: schedules table ----

// Column widths shared by the header and every row so they line up. Matches the
// Figma frame (Schedule / Workdays / Hours / Grace / Actions).
const COL = {
  schedule: "w-[26%] min-w-[150px]",
  workdays: "flex-1 min-w-[140px]",
  hours: "w-[120px]",
  paid: "w-[120px]",
  grace: "w-[90px]",
  actions: "w-[80px]",
};

function paidDayLabel(mins: number | null): string {
  if (mins == null) return "—";
  return `${Math.floor(mins / 60)}h ${mins % 60}m`;
}

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
        <div className="min-w-[820px]">
          {/* Header strip — shaded, uppercase gray labels. */}
          <div className="flex items-center gap-4 border-b border-border bg-muted/60 px-5 py-3 text-label font-bold uppercase tracking-wide text-muted-foreground">
            <div className={COL.schedule}>Schedule</div>
            <div className={COL.workdays}>Workdays</div>
            <div className={COL.hours}>Hours</div>
            <div className={COL.paid}>Paid day</div>
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
                <div className={cn(COL.paid, "text-sm text-foreground")}>
                  {paidDayLabel(s.expected_minutes)}
                  <span className="block text-xs text-muted-foreground">
                    {s.unpaid_break_minutes}m lunch
                  </span>
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

// ---- Section B: attendance policy + task format ----

function AttendancePolicyForm() {
  const { data: policy } = useAttendancePolicy();

  return (
    <section className="space-y-3">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">Attendance policy</h2>
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
// stored as a one-row late_tiers ladder (late_deduction_mode = 'tiered').
type ChargeMode = "exact" | "custom";

// The single threshold rung the UI edits, read out of the stored late_tiers (minutes).
function thresholdFrom(tiers: LateTier[] | null | undefined): { over: number; charge: number } {
  const row = tiers?.[0];
  if (!row) return { over: 15, charge: 60 }; // sensible starting point
  return { over: Number(row.over ?? 15), charge: Math.round(Number(row.hours ?? 1) * 60) };
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
  // Long break -- by count of over-long-break days this month (Slice 9).
  const [breakLow, setBreakLow] = useState(String(rungValue(vr, "long_break", "green", "occurrence_min", 1)));
  const [breakCaution, setBreakCaution] = useState(String(rungValue(vr, "long_break", "yellow", "occurrence_min", 2)));
  const [breakWarning, setBreakWarning] = useState(String(rungValue(vr, "long_break", "orange", "occurrence_min", 3)));
  const [breakCritical, setBreakCritical] = useState(String(rungValue(vr, "long_break", "red", "occurrence_min", 4)));
  // Whether the late tally resets each calendar month (on) or carries over (off).
  const [monthlyReset, setMonthlyReset] = useState(policy?.monthly_late_reset ?? true);

  // Tardiness pay deduction — two INDEPENDENT switches that can both be on:
  //   • lateChargeOn       = deduct for the actual time late (exact minutes, or a threshold rule)
  //   • latePenaltyEnabled = deduct 1 extra full day for every N lates in the pay period
  const [lateChargeOn, setLateChargeOn] = useState((policy?.late_deduction_mode ?? "none") !== "none");
  const [latePenaltyEnabled, setLatePenaltyEnabled] = useState(policy?.late_penalty_enabled ?? false);
  const [latesPerDeduction, setLatesPerDeduction] = useState(String(policy?.lates_per_deduction ?? 3));

  // Undertime.
  const [undertimeMode, setUndertimeMode] = useState<UndertimeDeductionMode>(policy?.undertime_deduction_mode ?? "none");

  // Early arrival & overtime.
  const [earlyArrival, setEarlyArrival] = useState<EarlyArrivalMode>(policy?.early_arrival_mode ?? "none");
  const [blockEarlyClockIn, setBlockEarlyClockIn] = useState(policy?.block_early_clock_in ?? false);
  const [overtimeMode, setOvertimeMode] = useState<OvertimeMode>(policy?.overtime_mode ?? "approved_only");
  const [overtimeThreshold, setOvertimeThreshold] = useState(String(policy?.overtime_threshold_minutes ?? 0));

  // Premium & overtime rates (Phase 0) — whole-percent, floored at the DOLE minimum (UI + server).
  const [premRestDay, setPremRestDay] = useState(String(policy?.premium_rest_day_pct ?? 130));
  const [premSpecialDay, setPremSpecialDay] = useState(String(policy?.premium_special_day_pct ?? 130));
  const [premRegHoliday, setPremRegHoliday] = useState(String(policy?.premium_regular_holiday_pct ?? 200));
  const [otOrdinary, setOtOrdinary] = useState(String(policy?.overtime_ordinary_pct ?? 125));
  const [otRestSpecial, setOtRestSpecial] = useState(String(policy?.overtime_rest_special_pct ?? 169));
  const [otRegHoliday, setOtRegHoliday] = useState(String(policy?.overtime_regular_holiday_pct ?? 260));
  const [nightDiff, setNightDiff] = useState(String(policy?.night_diff_pct ?? 10));

  // How lateness is charged: "exact" (precise minutes) or "custom" (a single threshold rule).
  // Custom is stored as a one-row late_tiers ladder — see chargeFields() below.
  const th = thresholdFrom(policy?.late_tiers);
  const [chargeMode, setChargeMode] = useState<ChargeMode>(policy?.late_deduction_mode === "tiered" ? "custom" : "exact");
  const [thresholdOver, setThresholdOver] = useState(String(th.over));
  const [thresholdCharge, setThresholdCharge] = useState(String(th.charge));
  const [lateFrom, setLateFrom] = useState(policy?.late_minutes_from ?? "start_time");
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

  // Which card is currently saving (so only its button shows a spinner).
  const [saving, setSaving] = useState<string | null>(null);

  // Save just ONE card's fields (partial update — the API only touches keys it's sent).
  async function saveCard(key: string, fields: Record<string, unknown>, guard?: () => boolean) {
    if (guard && !guard()) return;
    setSaving(key);
    try {
      await update.mutateAsync(fields);
      toast.success("Saved");
    } catch {
      toast.error("Could not save.");
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
    deduction_rounding: "exact" as const,
    deduction_block_minutes: 60,
    late_minutes_from: lateFrom,
  });

  // The lateness charge controls, shown when the per-time switch is on. "Start counting late
  // from" comes first, then how the time is charged: Exact, or a single threshold rule.
  const chargeControls = () => (
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

      {chargeMode === "custom" && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm text-foreground">If late by more than</span>
          <Input
            id="pol-th-over"
            type="number"
            min={0}
            max={480}
            value={thresholdOver}
            onChange={(e) => setThresholdOver(e.target.value)}
            className="w-20"
          />
          <span className="text-sm text-foreground">minutes, charge</span>
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
      )}

      {chargeMode === "exact" ? (
        <Hint>Exact charges only the minutes late — the proportionate, Art. 113-safe default.</Hint>
      ) : (
        <div className="rounded-lg border border-destructive bg-destructive/5 p-3">
          <p className="text-xs text-destructive">
            A threshold rule charges a flat amount that can exceed the actual minutes late (e.g. 16 min late
            → a full hour docked). This may be an unlawful penalty under Labor Code Art. 113 — enable only
            with legal review.
          </p>
        </div>
      )}
      <Hint>Leaving early is always billed to the exact minute. A single day can never cost more than one day&apos;s pay.</Hint>
    </div>
  );

  return (
    <div className="w-full overflow-hidden rounded-card border border-border bg-card">
      {/* Violation flags — the colour ladder HR sets. Uses the same four alert levels as
          the Attendance modal legend (Low/Informational → Caution → Warning → Critical).
          Disciplinary only; never pay. */}
      <PolicySection
        title="Violation flags"
        description="The four alert levels shown in the Attendance modal — set what makes a late, an absence, or a missed clock-out each colour. These are disciplinary flags; they never deduct pay."
      >
        <div className="space-y-3">
          <p className="text-[13px] font-semibold text-foreground">Lateness — lates in a month</p>
          <div className="flex flex-wrap items-end gap-4">
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
          <div className="flex flex-wrap items-end gap-4">
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
          <div className="flex flex-wrap items-end gap-4">
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
        {/* Slice 9 — over-long breaks became a real flag; these set its colour. */}
        <div className="space-y-3">
          <p className="text-[13px] font-semibold text-foreground">
            Over-long break — days this month
          </p>
          <div className="flex flex-wrap items-end gap-4">
            <NumberField id="pol-break-low" label="Low / Informational at" value={breakLow} onChange={setBreakLow} min={0} max={31} dot="green" />
            <NumberField id="pol-break-caution" label="Caution at" value={breakCaution} onChange={setBreakCaution} min={Number(breakLow) + 1} max={31} dot="yellow" />
            <NumberField id="pol-break-warning" label="Warning at" value={breakWarning} onChange={setBreakWarning} min={Number(breakCaution) + 1} max={31} dot="orange" />
            <NumberField id="pol-break-critical" label="Critical at" value={breakCritical} onChange={setBreakCritical} min={Number(breakWarning) + 1} max={31} dot="red" />
          </div>
          <Hint>
            A day counts when break + lunch runs past that employee&apos;s own schedule allowance by
            more than the grace set under <strong>Break overage</strong> below. Flag only — a long
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
        <SaveButton pending={saving === "violation"} onClick={() => saveCard("violation", violationFields(), guardLadder)} />
      </PolicySection>

      <Divider />

      {/* Time-based pay deductions — lateness (two independent switches) + undertime. */}
      <PolicySection
        title="Time-based pay deductions"
        description="How missed time (lateness and leaving early) reduces pay when payroll runs. Deducts only the actual time missed unless you set a threshold rule for lateness."
      >
        {/* Lateness — two switches that can BOTH be on. */}
        <SubHeading>When someone is late</SubHeading>

        {/* Switch 1 — deduct the actual minutes late. */}
        <CheckBox
          checked={lateChargeOn}
          onChange={setLateChargeOn}
          label="Deduct pay for the actual time they were late"
        />
        {lateChargeOn && chargeControls()}

        {/* Switch 2 — extra full-day penalty per N lates (independent of the charge above). */}
        <SubDivider />
        <CheckBox
          checked={latePenaltyEnabled}
          onChange={setLatePenaltyEnabled}
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

        {/* Both on = the same lateness is charged twice. Intentional stacking, flagged. */}
        {lateChargeOn && latePenaltyEnabled && (
          <div className="rounded-lg border border-warning bg-warning-subtle p-3">
            <p className="text-xs text-foreground">
              Both lateness deductions are on, so a single late arrival is charged{" "}
              <strong>twice</strong> — once as the actual time, and again toward the full-day penalty.
              This is deliberate stacking; confirm it against your handbook and Labor Code Art. 113.
            </p>
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
        <Hint>
          Leaving early is billed to the exact minute. A <strong>forgotten clock-out</strong> is
          auto-credited to the scheduled end time — it never docks pay and never counts as overtime; it&apos;s
          flagged in Violation history (set its alert levels under <strong>Violation flags</strong> above).
        </Hint>

        <SaveButton
          pending={saving === "time_deductions"}
          onClick={() =>
            saveCard("time_deductions", {
              ...chargeFields(),
              late_penalty_enabled: latePenaltyEnabled,
              lates_per_deduction: Number(latesPerDeduction),
              undertime_deduction_mode: undertimeMode,
              daily_deduction_cap: 1,
            })
          }
        />
      </PolicySection>

      <Divider />

      {/* Early arrival & overtime. */}
      <PolicySection
        title="Early arrival &amp; overtime"
        description="Clocking in early, and how extra time past the schedule is treated."
      >
        <SubHeading>Clocking in early</SubHeading>
        <CheckBox
          checked={blockEarlyClockIn}
          onChange={setBlockEarlyClockIn}
          label="Only let staff time in once their shift has started"
        />
        <Hint>
          {blockEarlyClockIn ? (
            <>
              The <strong>Time in</strong> button stays disabled until each person&apos;s own
              scheduled start, and the server refuses an early punch either way. Staff with no
              schedule assigned are held to the default one. On a day that isn&apos;t one of their
              workdays they can still clock in whenever — there&apos;s no shift that day to be early
              for. Lateness is unaffected: this only sets the earliest they may start.
            </>
          ) : (
            "Anyone can time in as early as they like, and the punch is recorded at the actual time. Turn this on to hold everyone to their scheduled start."
          )}
        </Hint>
        <SubDivider />

        <SubHeading>What early minutes are worth</SubHeading>
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-early"
            label="Early arrival"
            value={earlyArrival}
            onChange={(v) => setEarlyArrival(v as EarlyArrivalMode)}
            disabled
            options={[
              { value: "none", label: "Ignore early minutes" },
              { value: "offset_undertime", label: "Offset same-day undertime" },
              { value: "overtime_eligible", label: "Count toward overtime (with approval)" },
            ]}
          />
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
            <NumberField id="pol-ot-threshold" label="Minimum overtime (minutes)" value={overtimeThreshold} onChange={setOvertimeThreshold} min={0} max={1440} />
          )}
        </div>
        {overtimeMode === "auto_after_threshold" && (
          <div className="rounded-lg border border-warning bg-warning-subtle p-3">
            <p className="text-xs text-foreground">
              Auto mode pays overtime from the <strong>time clock alone</strong> — staying past the
              scheduled end by at least this many minutes is paid without anyone approving it. Philippine
              practice is that overtime should be <strong>authorised</strong>; &ldquo;Only when approved&rdquo;
              is the safer setting. A day&apos;s whole overrun is paid once it passes the minimum, and a
              missing clock-out never counts as overtime.
            </p>
            {/* The late-arrival case surprises people, so say it outright rather than let HR
                discover it on a payslip. See AttendanceService::autoOvertimeMinutesFor. */}
            <p className="mt-2 text-xs text-foreground">
              <strong>Arriving late does not cancel out overtime.</strong> Someone who comes in an hour
              late and stays an hour past the shift is paid that overtime, and the late hour is deducted
              separately. The two are never netted off — Philippine law (Art. 88) forbids offsetting
              undertime with overtime, because overtime is worth more per hour. If that trade looks
              wrong for your company, use &ldquo;Only when approved&rdquo; instead.
            </p>
          </div>
        )}
        <Hint>
          Early-arrival handling isn&apos;t active yet — early minutes are always ignored for now, whatever
          this shows. Overtime is live: <strong>Only when approved</strong> pays each approved overtime
          request; <strong>No overtime</strong> switches it off. Rest-day work is paid only through an
          approved request, never auto-detected.
        </Hint>
        <SaveButton
          pending={saving === "early_ot"}
          // Slice 14 — early_arrival_mode is deliberately NOT sent: the control is disabled
          // (no engine reads it) and the API now rejects anything but "none", so echoing a
          // stored value back would 422 and block HR from saving the overtime settings.
          onClick={() =>
            saveCard("early_ot", {
              block_early_clock_in: blockEarlyClockIn,
              overtime_mode: overtimeMode,
              overtime_threshold_minutes: Number(overtimeThreshold),
            })
          }
        />
      </PolicySection>

      <Divider />

      {/* Premium & overtime rates (Premium Pay epic, Phase 0). Editable, floored at DOLE minimums. */}
      <PolicySection
        title="Premium &amp; overtime rates"
        description="Legal pay multipliers for overtime, holidays, rest days and night work. Raise any of them, but never below the DOLE minimum."
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
          <NumberField id="pol-night" label="Night differential (+%)" value={nightDiff} onChange={setNightDiff} min={10} max={100} />
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
        <SaveButton
          pending={saving === "premium_rates"}
          onClick={() =>
            saveCard("premium_rates", {
              premium_rest_day_pct: Number(premRestDay),
              premium_special_day_pct: Number(premSpecialDay),
              premium_regular_holiday_pct: Number(premRegHoliday),
              overtime_ordinary_pct: Number(otOrdinary),
              overtime_rest_special_pct: Number(otRestSpecial),
              overtime_regular_holiday_pct: Number(otRegHoliday),
              night_diff_pct: Number(nightDiff),
            })
          }
        />
      </PolicySection>

      {/* Daily-rate basis. */}
      <PolicySection
        title="Daily rate basis"
        description="How one day of pay is priced — this affects absence deductions for everyone, not just minutes."
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
        <SaveButton
          pending={saving === "basis"}
          onClick={() => saveCard("basis", { daily_rate_basis: rateBasis, annual_divisor: Number(divisor), hours_per_day_divisor: Number(hoursPerDay) })}
        />
      </PolicySection>

      <Divider />

      {/* Break overage (flag only). The LIMIT lives on each work schedule; this is the
          tolerance on top of it — see ViolationService (Slice 9). */}
      <PolicySection
        title="Break overage"
        description="How far past their own break allowance a day may run before it is flagged. Over-long breaks are never deducted from pay."
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
        <SaveButton
          pending={saving === "break"}
          onClick={() => saveCard("break", {
            lunch_grace_minutes: Number(lunchGrace),
            break_grace_minutes: Number(breakGrace),
          })}
        />
      </PolicySection>

    </div>
  );
}

function Divider() {
  return <div className="h-px w-full bg-border" />;
}

function PolicySection({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <div className="space-y-4 p-6">
      <div className="space-y-1">
        <h3 className="text-base font-bold text-foreground">{title}</h3>
        <p className="text-[13px] text-muted-foreground">{description}</p>
      </div>
      {children}
    </div>
  );
}

function Hint({ children }: { children: ReactNode }) {
  return <p className="text-xs text-muted-foreground">{children}</p>;
}

// Subsection heading inside a combined policy card.
function SubHeading({ children }: { children: ReactNode }) {
  return <p className="text-[13px] font-semibold text-foreground">{children}</p>;
}

// Light divider between subsections of one card.
function SubDivider() {
  return <div className="h-px w-full bg-border" />;
}

// Per-card save — each policy section saves only its own fields (partial update).
function SaveButton({ onClick, pending }: { onClick: () => void; pending: boolean }) {
  return (
    <div className="pt-1">
      <Button size="sm" onClick={onClick} disabled={pending}>
        {pending ? "Saving…" : "Save"}
      </Button>
    </div>
  );
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
    <div className="w-48 space-y-1.5">
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
