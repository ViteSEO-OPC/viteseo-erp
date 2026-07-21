"use client";

import { useState } from "react";
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
import { formatWorkdays, type WorkSchedule } from "@/modules/hr/types/rules";
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
  schedule: "w-[30%] min-w-[150px]",
  workdays: "flex-1 min-w-[160px]",
  hours: "w-[130px]",
  grace: "w-[90px]",
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
        <div className="min-w-[720px]">
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

function AttendancePolicyEditor() {
  const { data: policy } = useAttendancePolicy();
  const update = useUpdateAttendancePolicy();

  const [lates, setLates] = useState(String(policy?.consecutive_lates_before_absence ?? 3));
  const [monthlyReset, setMonthlyReset] = useState(policy?.monthly_late_reset ?? true);
  const [awol, setAwol] = useState(String(policy?.awol_days_threshold ?? 3));
  const [lateMode, setLateMode] = useState<"none" | "per_lates">(policy?.late_deduction_mode ?? "none");
  const [latesPerDeduction, setLatesPerDeduction] = useState(String(policy?.lates_per_deduction ?? 3));
  const [english, setEnglish] = useState(policy?.task_format?.require_english ?? true);
  const [uppercase, setUppercase] = useState(policy?.task_format?.uppercase ?? true);

  async function save() {
    try {
      await update.mutateAsync({
        consecutive_lates_before_absence: Number(lates),
        monthly_late_reset: monthlyReset,
        awol_days_threshold: Number(awol),
        late_deduction_mode: lateMode,
        lates_per_deduction: Number(latesPerDeduction),
        task_format: { require_english: english, uppercase },
      });
      toast.success("Policy saved");
    } catch {
      toast.error("Could not save the policy.");
    }
  }

  return (
    <div className="w-full overflow-hidden rounded-card border border-border bg-card">
      {/* Row: two thresholds split by a vertical rule. */}
      <div className="flex flex-col md:flex-row">
        <div className="flex-1 space-y-1.5 p-6">
          <Label htmlFor="pol-lates">Consecutive lates before an absence</Label>
          <Input id="pol-lates" type="number" min="1" max="30" value={lates} onChange={(e) => setLates(e.target.value)} />
          <p className="text-xs text-muted-foreground">
            After this many consecutive lates, the next one counts as an absence.
          </p>
        </div>
        <div className="hidden w-px self-stretch bg-border md:block" />
        <div className="flex-1 space-y-1.5 p-6">
          <Label htmlFor="pol-awol">AWOL threshold (days)</Label>
          <Input id="pol-awol" type="number" min="1" max="30" value={awol} onChange={(e) => setAwol(e.target.value)} />
          <p className="text-xs text-muted-foreground">
            Consecutive days without notice before it&apos;s flagged AWOL (red).
          </p>
        </div>
      </div>

      {/* Reset-monthly toggle. */}
      <div className="px-6 pb-5">
        <CheckBox
          checked={monthlyReset}
          onChange={setMonthlyReset}
          label="Reset the late tally each calendar month"
        />
      </div>

      <div className="h-px w-full bg-border" />

      {/* Tardiness pay deduction. */}
      <div className="space-y-4 p-6">
        <div className="space-y-1">
          <h3 className="text-base font-bold text-foreground">Tardiness pay deduction</h3>
          <p className="text-[13px] text-muted-foreground">
            How accumulated lates reduce pay when payroll runs.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-4">
          <div className="w-full max-w-[360px] space-y-1.5">
            <Label htmlFor="pol-late-mode">Mode</Label>
            <SimpleSelect
              id="pol-late-mode"
              className="h-10"
              value={lateMode}
              onValueChange={(v) => setLateMode(v as "none" | "per_lates")}
              options={[
                { value: "none", label: "No deduction" },
                { value: "per_lates", label: "Deduct a day every N lates" },
              ]}
            />
          </div>
          {lateMode === "per_lates" && (
            <div className="w-40 space-y-1.5">
              <Label htmlFor="pol-late-n">Lates per 1-day deduction</Label>
              <Input
                id="pol-late-n"
                type="number"
                min="1"
                max="30"
                value={latesPerDeduction}
                onChange={(e) => setLatesPerDeduction(e.target.value)}
              />
            </div>
          )}
        </div>
      </div>

      <div className="h-px w-full bg-border" />

      {/* Task checklist format + save. */}
      <div className="space-y-4 p-6">
        <h3 className="text-base font-bold text-foreground">Task checklist format</h3>
        <div className="flex flex-wrap gap-8">
          <CheckBox checked={english} onChange={setEnglish} label="Require English" />
          <CheckBox checked={uppercase} onChange={setUppercase} label="Uppercase" />
        </div>
        <Button onClick={save} disabled={update.isPending}>
          {update.isPending ? "Saving…" : "Save Policy"}
        </Button>
      </div>
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
