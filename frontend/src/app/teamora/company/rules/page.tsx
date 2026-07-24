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
  usePreviewDeductions,
} from "@/modules/hr/hooks/use-rules";
import {
  formatWorkdays,
  type DailyRateBasis,
  type DeductionRounding,
  type EarlyArrivalMode,
  type LateDeductionMode,
  type MissingClockoutMode,
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

function peso(n: number): string {
  return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(n);
}

// Read a level's threshold out of the stored violation ladder, falling back to default.
function rungValue(
  rules: ViolationRules | null | undefined,
  category: keyof ViolationRules,
  severity: ViolationRule["severity"],
  key: "streak_min" | "monthly_min",
  fallback: number,
): number {
  const rung = (rules?.[category] ?? []).find((r) => r.severity === severity);
  return rung?.[key] ?? fallback;
}

// The four alert levels must strictly ascend. Returns an error message, or null if OK.
function ladderError(late: number[], absent: number[]): string | null {
  const ascends = (a: number[]) => a.every((v, i) => i === 0 || v > a[i - 1]);
  if (!ascends(late)) return "Lateness levels must increase: Low < Caution < Warning < Critical.";
  if (!ascends(absent)) return "Absence levels must increase: Low < Caution < Warning < Critical.";
  return null;
}

function AttendancePolicyEditor() {
  const { data: policy } = useAttendancePolicy();
  const update = useUpdateAttendancePolicy();
  const preview = usePreviewDeductions();

  const [monthlyReset, setMonthlyReset] = useState(policy?.monthly_late_reset ?? true);

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

  // Tardiness pay deduction.
  const [lateMode, setLateMode] = useState<LateDeductionMode>(policy?.late_deduction_mode ?? "none");
  const [latesPerDeduction, setLatesPerDeduction] = useState(String(policy?.lates_per_deduction ?? 3));

  // Undertime & missing clock-out.
  const [undertimeMode, setUndertimeMode] = useState<UndertimeDeductionMode>(policy?.undertime_deduction_mode ?? "none");
  const [missingClockout, setMissingClockout] = useState<MissingClockoutMode>(policy?.missing_clockout_mode ?? "no_deduction_flag_for_review");

  // Early arrival & overtime.
  const [earlyArrival, setEarlyArrival] = useState<EarlyArrivalMode>(policy?.early_arrival_mode ?? "none");
  const [overtimeMode, setOvertimeMode] = useState<OvertimeMode>(policy?.overtime_mode ?? "approved_only");
  const [overtimeThreshold, setOvertimeThreshold] = useState(String(policy?.overtime_threshold_minutes ?? 0));

  // Rounding, grace basis & cap.
  const [rounding, setRounding] = useState<DeductionRounding>(policy?.deduction_rounding ?? "exact");
  const [block, setBlock] = useState(String(policy?.deduction_block_minutes ?? 60));
  const [lateFrom, setLateFrom] = useState(policy?.late_minutes_from ?? "start_time");
  const [cap, setCap] = useState(String(policy?.daily_deduction_cap ?? 1));

  // Daily-rate basis.
  const [rateBasis, setRateBasis] = useState<DailyRateBasis>(policy?.daily_rate_basis ?? "period_workdays");
  const [divisor, setDivisor] = useState(String(policy?.annual_divisor ?? 261));
  const [hoursPerDay, setHoursPerDay] = useState(String(policy?.hours_per_day_divisor ?? 8));

  // Break limit (flag only).
  const [maxBreak, setMaxBreak] = useState(String(policy?.max_break_minutes_per_day ?? 120));

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
      missed_time_out: [{ occurrence_min: 1, severity: "green" }],
    } satisfies ViolationRules,
  });

  // Preview reads the current (unsaved) deduction settings across all pay cards.
  function deductionFields() {
    return {
      late_deduction_mode: lateMode,
      lates_per_deduction: Number(latesPerDeduction),
      undertime_deduction_mode: undertimeMode,
      missing_clockout_mode: missingClockout,
      early_arrival_mode: earlyArrival,
      overtime_mode: overtimeMode,
      overtime_threshold_minutes: Number(overtimeThreshold),
      deduction_rounding: rounding,
      deduction_block_minutes: Number(block),
      late_minutes_from: lateFrom,
      daily_deduction_cap: Number(cap),
      daily_rate_basis: rateBasis,
      annual_divisor: Number(divisor),
      hours_per_day_divisor: Number(hoursPerDay),
    };
  }

  async function runPreview() {
    try {
      await preview.mutateAsync(deductionFields());
    } catch {
      toast.error("Could not run the preview.");
    }
  }

  return (
    <div className="w-full overflow-hidden rounded-card border border-border bg-card">
      {/* Violation flags — the colour ladder HR sets. Uses the same four alert levels as
          the Attendance modal legend (Low/Informational → Caution → Warning → Critical).
          Disciplinary only; never pay. */}
      <PolicySection
        title="Violation flags"
        description="The four alert levels shown in the Attendance modal — set what makes a late or an absence each colour. These are disciplinary flags; they never deduct pay."
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
        <CheckBox
          checked={monthlyReset}
          onChange={setMonthlyReset}
          label="Reset the late tally each calendar month"
        />
        <Hint>
          Each level must use a higher number than the one before it (0 is allowed for the lowest).
          These exact numbers appear in the Attendance modal&apos;s Alert Color Legend.
        </Hint>
        <SaveButton pending={saving === "violation"} onClick={() => saveCard("violation", violationFields(), guardLadder)} />
      </PolicySection>

      <Divider />

      {/* Tardiness pay deduction. */}
      <PolicySection
        title="Tardiness pay deduction"
        description="How lateness reduces pay when payroll runs. Deducts only the actual time missed unless you turn on rounding below."
      >
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-late-mode"
            label="Mode"
            value={lateMode}
            onChange={(v) => setLateMode(v as LateDeductionMode)}
            options={[
              { value: "none", label: "No deduction" },
              { value: "per_lates", label: "Deduct a day every N lates" },
              { value: "per_hour", label: "Deduct actual time late (proportionate)" },
            ]}
          />
          {lateMode === "per_lates" && (
            <NumberField id="pol-late-n" label="Lates per 1-day deduction" value={latesPerDeduction} onChange={setLatesPerDeduction} min={1} max={30} />
          )}
        </div>
        {lateMode === "per_hour" && (
          <Hint>
            Example: 30 minutes late, on a ₱909/day (9-hour) schedule, costs ₱909 ÷ 8 ÷ 2 ≈{" "}
            <strong>₱56.82</strong> — the exact half-hour. Rounding is off by default.
          </Hint>
        )}
        <SaveButton
          pending={saving === "tardiness"}
          onClick={() => saveCard("tardiness", { late_deduction_mode: lateMode, lates_per_deduction: Number(latesPerDeduction) })}
        />
      </PolicySection>

      <Divider />

      {/* Undertime & missing clock-out. */}
      <PolicySection
        title="Undertime &amp; missing clock-out"
        description="Leaving before the scheduled end, and what to do when someone forgets to clock out."
      >
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-undertime-mode"
            label="Undertime deduction"
            value={undertimeMode}
            onChange={(v) => setUndertimeMode(v as UndertimeDeductionMode)}
            options={[
              { value: "none", label: "No deduction (leaving early is free)" },
              { value: "per_hour", label: "Deduct actual time short (proportionate)" },
            ]}
          />
          <FieldSelect
            id="pol-missing-clockout"
            label="Forgot to clock out"
            value={missingClockout}
            onChange={(v) => setMissingClockout(v as MissingClockoutMode)}
            options={[
              { value: "no_deduction_flag_for_review", label: "Flag for review, don't deduct" },
              { value: "treat_as_undertime_to_now", label: "Treat as undertime" },
              { value: "treat_as_absent", label: "Treat as absent" },
            ]}
          />
        </div>
        <Hint>A missing clock-out never counts as overtime.</Hint>
        <SaveButton
          pending={saving === "undertime"}
          onClick={() => saveCard("undertime", { undertime_deduction_mode: undertimeMode, missing_clockout_mode: missingClockout })}
        />
      </PolicySection>

      <Divider />

      {/* Early arrival & overtime. */}
      <PolicySection
        title="Early arrival &amp; overtime"
        description="Clocking in early, and how extra time past the schedule is treated."
      >
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-early"
            label="Early arrival"
            value={earlyArrival}
            onChange={(v) => setEarlyArrival(v as EarlyArrivalMode)}
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
            <NumberField id="pol-ot-threshold" label="Threshold (minutes)" value={overtimeThreshold} onChange={setOvertimeThreshold} min={0} max={1440} />
          )}
        </div>
        <Hint>Overtime takes effect when overtime requests ship. A long clock reading is not overtime.</Hint>
        <SaveButton
          pending={saving === "early_ot"}
          onClick={() => saveCard("early_ot", { early_arrival_mode: earlyArrival, overtime_mode: overtimeMode, overtime_threshold_minutes: Number(overtimeThreshold) })}
        />
      </PolicySection>

      <Divider />

      {/* Rounding, grace basis & cap. */}
      <PolicySection
        title="Deduction rounding &amp; cap"
        description="How time-based deductions are rounded, and a safety limit per day."
      >
        <div className="flex flex-wrap items-end gap-4">
          <FieldSelect
            id="pol-rounding"
            label="Rounding"
            value={rounding}
            onChange={(v) => setRounding(v as DeductionRounding)}
            options={[
              { value: "exact", label: "Exact (proportionate)" },
              { value: "round_up", label: "Round up" },
              { value: "round_nearest", label: "Round to nearest" },
              { value: "round_down", label: "Round down" },
            ]}
          />
          {rounding !== "exact" && (
            <NumberField id="pol-block" label="Block (minutes)" value={block} onChange={setBlock} min={1} max={480} />
          )}
          <FieldSelect
            id="pol-late-from"
            label="Count late from"
            value={lateFrom}
            onChange={(v) => setLateFrom(v as "start_time" | "after_grace")}
            options={[
              { value: "start_time", label: "Scheduled start" },
              { value: "after_grace", label: "End of grace period" },
            ]}
          />
          <NumberField id="pol-cap" label="Max days deducted / day" value={cap} onChange={setCap} min={0} max={1} step={0.25} />
        </div>
        {rounding !== "exact" && (
          <div className="rounded-lg border border-destructive bg-destructive/5 p-3">
            <p className="text-xs text-destructive">
              Deducting more than the exact time missed may be an unlawful penalty under Labor Code
              Art. 113. Enable only with legal review.
            </p>
          </div>
        )}
        <Hint>
          The cap guarantees no single day ever costs more than one day&apos;s pay. Arriving 08:20
          with 15-min grace counts as 20 min (from start) or 5 min (after grace).
        </Hint>
        <SaveButton
          pending={saving === "rounding"}
          onClick={() => saveCard("rounding", { deduction_rounding: rounding, deduction_block_minutes: Number(block), late_minutes_from: lateFrom, daily_deduction_cap: Number(cap) })}
        />
      </PolicySection>

      <Divider />

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

      {/* Break limit (flag only). */}
      <PolicySection
        title="Break limits"
        description="Over-long breaks are flagged for review — they are never deducted from pay."
      >
        <NumberField id="pol-max-break" label="Max break minutes / day" value={maxBreak} onChange={setMaxBreak} min={0} max={1440} />
        <Hint>A day beyond this is flagged as a suspected forgotten punch, not fined.</Hint>
        <SaveButton
          pending={saving === "break"}
          onClick={() => saveCard("break", { max_break_minutes_per_day: Number(maxBreak) })}
        />
      </PolicySection>

      <Divider />

      {/* Dry-run preview (step 6b): what the current pay-deduction settings WOULD cost. */}
      <PolicySection
        title="Preview impact"
        description="See what the pay-deduction settings above would have cost last period, before you rely on them. Nothing is saved."
      >
        {preview.data && (
          <div className="rounded-lg border border-border bg-muted/40 p-4">
            {preview.data.employees_considered === 0 ? (
              <p className="text-sm text-muted-foreground">No completed pay period to preview yet.</p>
            ) : (
              <p className="text-sm text-foreground">
                If these settings had been active for{" "}
                <strong>
                  {preview.data.period.start} – {preview.data.period.end}
                </strong>
                :{" "}
                <strong>{peso(preview.data.total_deduction)}</strong> total time-based deduction
                across <strong>{preview.data.employees_affected}</strong> of{" "}
                {preview.data.employees_considered} employees.
              </p>
            )}
          </div>
        )}
        <Button variant="outline" onClick={runPreview} disabled={preview.isPending}>
          {preview.isPending ? "Calculating…" : "Preview impact"}
        </Button>
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
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: { value: string; label: string }[];
}) {
  return (
    <div className="w-full max-w-[360px] space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <SimpleSelect id={id} className="h-10" value={value} onValueChange={onChange} options={options} />
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
