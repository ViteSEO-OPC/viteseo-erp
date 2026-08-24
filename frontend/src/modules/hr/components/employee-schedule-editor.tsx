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
import { SimpleSelect } from "@/modules/hr/ui/select";
import { TimePicker } from "@/modules/hr/ui/time-picker";

/**
 * An employee's effective work schedule, with HR editing (assign a schedule + set
 * per-person overrides). `readOnly` renders just the resolved summary — used on
 * the employee's own profile. Shared by Company → Settings and the Work Details tab.
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
  const [unpaidBreak, setUnpaidBreak] = useState(
    initial.custom_unpaid_break_minutes != null ? String(initial.custom_unpaid_break_minutes) : "",
  );
  const [paidBreak, setPaidBreak] = useState(
    initial.custom_paid_break_minutes != null ? String(initial.custom_paid_break_minutes) : "",
  );
  const [amStart, setAmStart] = useState(initial.custom_am_start_time ?? "");
  const [amEnd, setAmEnd] = useState(initial.custom_am_end_time ?? "");
  const [pmStart, setPmStart] = useState(initial.custom_pm_start_time ?? "");
  const [pmEnd, setPmEnd] = useState(initial.custom_pm_end_time ?? "");

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
        custom_unpaid_break_minutes: unpaidBreak !== "" ? Number(unpaidBreak) : null,
        custom_paid_break_minutes: paidBreak !== "" ? Number(paidBreak) : null,
        custom_am_start_time: amStart || null,
        custom_am_end_time: amEnd || null,
        custom_pm_start_time: pmStart || null,
        custom_pm_end_time: pmEnd || null,
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
        <SimpleSelect
          id="assign-schedule"
          className="max-w-xs"
          value={scheduleId || "default"}
          onValueChange={(v) => setScheduleId(v === "default" ? "" : v)}
          options={[
            { value: "default", label: "Default schedule" },
            ...schedules.map((s) => ({ value: String(s.id), label: s.name })),
          ]}
        />
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
          <TimePicker id="ov-start" value={start} onChange={setStart} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ov-end">Override end</Label>
          <TimePicker id="ov-end" value={end} onChange={setEnd} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ov-grace">Override grace</Label>
          <Input id="ov-grace" type="number" min="0" max="240" value={grace} onChange={(e) => setGrace(e.target.value)} />
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="ov-unpaid-break">Override lunch / unpaid break (min)</Label>
          <Input id="ov-unpaid-break" type="number" min="0" max="480" value={unpaidBreak} onChange={(e) => setUnpaidBreak(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ov-paid-break">Override paid break (min)</Label>
          <Input id="ov-paid-break" type="number" min="0" max="480" value={paidBreak} onChange={(e) => setPaidBreak(e.target.value)} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">Leave break fields blank to use the assigned schedule&apos;s breaks.</p>

      {/*
       * Half-day windows. These are what an approved half day is JUDGED against — lateness,
       * undertime and the early-out fraction all measure from them — so an employee whose
       * afternoons really start at 13:30 needs it said here, or the template's 13:00 turns the
       * difference into billable lateness every time they take a morning off.
       */}
      <div className="space-y-1.5">
        <Label>Override half-day windows</Label>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="ov-am-start" className="text-xs font-normal text-muted-foreground">AM start</Label>
            <TimePicker id="ov-am-start" value={amStart} onChange={setAmStart} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ov-am-end" className="text-xs font-normal text-muted-foreground">AM end</Label>
            <TimePicker id="ov-am-end" value={amEnd} onChange={setAmEnd} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ov-pm-start" className="text-xs font-normal text-muted-foreground">PM start</Label>
            <TimePicker id="ov-pm-start" value={pmStart} onChange={setPmStart} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ov-pm-end" className="text-xs font-normal text-muted-foreground">PM end</Label>
            <TimePicker id="ov-pm-end" value={pmEnd} onChange={setPmEnd} />
          </div>
        </div>
        <p className="text-xs text-muted-foreground">
          Blank fields fall back to the assigned schedule, one field at a time — set only the PM
          start and the PM end stays the schedule&apos;s. Effective now:{" "}
          <span className="font-medium text-foreground">
            AM {initial.effective.am_start_time ?? "—"}–{initial.effective.am_end_time ?? "—"} · PM{" "}
            {initial.effective.pm_start_time ?? "—"}–{initial.effective.pm_end_time ?? "—"}
          </span>
        </p>
      </div>

      <Button type="button" size="sm" onClick={save} disabled={update.isPending}>
        {update.isPending ? "Saving…" : "Save schedule"}
      </Button>
    </div>
  );
}
