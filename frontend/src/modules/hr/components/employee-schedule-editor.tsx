"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import {
  useWorkSchedules,
  useEmployeeSchedule,
  useUpdateEmployeeSchedule,
} from "@/modules/hr/hooks/use-rules";
import {
  WEEKDAYS,
  formatWorkdays,
  SCHEDULE_SOURCE_LABEL,
  type WorkSchedule,
  type EmployeeSchedule,
} from "@/modules/hr/types/rules";

/**
 * An employee's effective work schedule, with HR editing (assign a schedule + set
 * per-person overrides). `readOnly` renders just the resolved summary — used on
 * the employee's own profile. Shared by Company → Rules and the Work Details tab.
 */
export function EmployeeScheduleEditor({
  employeeId,
  readOnly = false,
}: {
  employeeId: number;
  readOnly?: boolean;
}) {
  const { data: schedule, isLoading } = useEmployeeSchedule(employeeId);
  const { data: schedules } = useWorkSchedules();

  if (isLoading || !schedule) {
    return <p className="text-sm text-muted-foreground">Loading schedule…</p>;
  }

  return (
    <Editor
      key={employeeId}
      employeeId={employeeId}
      initial={schedule}
      schedules={schedules ?? []}
      readOnly={readOnly}
    />
  );
}

function EffectiveSummary({ eff }: { eff: EmployeeSchedule["effective"] }) {
  return (
    <div className="flex flex-wrap items-center gap-2 text-sm">
      <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground">
        {SCHEDULE_SOURCE_LABEL[eff.source] ?? eff.source}
      </span>
      <span className="text-foreground">
        {eff.schedule_name ?? "—"} · {eff.start_time ?? "?"}–{eff.end_time ?? "?"} ·{" "}
        {formatWorkdays(eff.workdays)} · {eff.grace_minutes}m grace
      </span>
    </div>
  );
}

function Editor({
  employeeId,
  initial,
  schedules,
  readOnly,
}: {
  employeeId: number;
  initial: EmployeeSchedule;
  schedules: WorkSchedule[];
  readOnly: boolean;
}) {
  const update = useUpdateEmployeeSchedule(employeeId);

  const [scheduleId, setScheduleId] = useState<string>(
    initial.work_schedule_id != null ? String(initial.work_schedule_id) : "",
  );
  const [workdays, setWorkdays] = useState<number[]>(initial.custom_workdays ?? []);
  const [start, setStart] = useState(initial.custom_start_time ?? "");
  const [end, setEnd] = useState(initial.custom_end_time ?? "");
  const [grace, setGrace] = useState(
    initial.custom_grace_minutes != null ? String(initial.custom_grace_minutes) : "",
  );

  if (readOnly) {
    return (
      <div className="rounded-card border border-border bg-card p-4">
        <EffectiveSummary eff={initial.effective} />
      </div>
    );
  }

  function toggleDay(day: number) {
    setWorkdays((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day],
    );
  }

  async function save() {
    try {
      await update.mutateAsync({
        work_schedule_id: scheduleId ? Number(scheduleId) : null,
        custom_workdays: workdays.length ? workdays : null,
        custom_start_time: start || null,
        custom_end_time: end || null,
        custom_grace_minutes: grace !== "" ? Number(grace) : null,
      });
      toast.success("Schedule updated");
    } catch {
      toast.error("Could not update the schedule.");
    }
  }

  return (
    <div className="space-y-4 rounded-card border border-border bg-card p-4">
      <EffectiveSummary eff={initial.effective} />

      <div className="space-y-1.5">
        <Label htmlFor="assign-schedule">Assigned schedule</Label>
        <select
          id="assign-schedule"
          className="h-9 w-full max-w-xs rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring"
          value={scheduleId}
          onChange={(e) => setScheduleId(e.target.value)}
        >
          <option value="">Default schedule</option>
          {schedules.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
      </div>

      <div className="space-y-1.5">
        <Label>Override workdays (optional)</Label>
        <div className="flex flex-wrap gap-1.5">
          {WEEKDAYS.map((d) => {
            const on = workdays.includes(d.value);
            return (
              <button
                key={d.value}
                type="button"
                onClick={() => toggleDay(d.value)}
                className={cn(
                  "rounded-lg border px-2.5 py-1 text-sm font-medium transition-colors",
                  on
                    ? "border-brand bg-brand text-brand-foreground"
                    : "border-input text-muted-foreground hover:bg-muted",
                )}
              >
                {d.label}
              </button>
            );
          })}
        </div>
        <p className="text-xs text-muted-foreground">Leave all off to use the assigned schedule&apos;s days.</p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="ov-start">Override start</Label>
          <Input id="ov-start" type="time" value={start} onChange={(e) => setStart(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ov-end">Override end</Label>
          <Input id="ov-end" type="time" value={end} onChange={(e) => setEnd(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ov-grace">Override grace</Label>
          <Input id="ov-grace" type="number" min="0" max="240" value={grace} onChange={(e) => setGrace(e.target.value)} />
        </div>
      </div>

      <Button size="sm" onClick={save} disabled={update.isPending}>
        {update.isPending ? "Saving…" : "Save schedule"}
      </Button>
    </div>
  );
}
