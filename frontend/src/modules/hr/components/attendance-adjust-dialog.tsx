"use client";

import { useState } from "react";
import { Clock, ChevronLeft, ChevronRight, CalendarPlus } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

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
import { cn } from "@/lib/utils";
import { toManilaTimeInput, formatDate } from "@/core/lib/format";
import { useAdjustAttendance, useViolations, useResolveViolation } from "@/modules/hr/hooks/use-attendance";
import type { HrAttendanceRow, Severity } from "@/modules/hr/types/attendance";

const selectClass =
  "h-8 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";

const DOT: Record<Severity, string> = {
  green: "bg-success",
  yellow: "bg-warning",
  orange: "bg-severity-orange",
  red: "bg-danger",
};

// The alert-level ladder shown in the legend (and used for the current level).
const ALERT_LEVELS: { sev: Severity; label: string; desc: string }[] = [
  { sev: "green", label: "Low / Informational", desc: "1st lateness; 1st–2nd Tardiness / month" },
  { sev: "yellow", label: "Caution", desc: "2nd lateness; 3rd Tardiness / month; 1-Day UA (1st)" },
  { sev: "orange", label: "Warning", desc: "3 Consecutive Lates; 4th Tardiness / month; 2-Day UA (1st)" },
  { sev: "red", label: "Critical", desc: "2-Day UA (2nd); 3+ Days Without Notice (AWOL)" },
];

const STATUS_PILL: Record<string, { cls: string; label: string }> = {
  present: { cls: "bg-success-subtle text-success", label: "Checked in" },
  late: { cls: "bg-danger-subtle text-danger", label: "Late" },
  absent: { cls: "bg-danger-subtle text-danger", label: "Absent" },
};

const VIOLATIONS_PER_PAGE = 5;

function titleCase(s: string): string {
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString("en-PH", { month: "short", day: "numeric" });
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
      <DialogContent className="max-w-2xl">
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
  const { data: violations } = useViolations(row.employee_id);

  const [status, setStatus] = useState(row.status === "absent" ? "absent" : row.status || "present");
  const [timeIn, setTimeIn] = useState(toManilaTimeInput(row.time_in));
  const [timeOut, setTimeOut] = useState(toManilaTimeInput(row.time_out));
  const [note, setNote] = useState("");
  const [vpage, setVpage] = useState(1);

  const isAbsent = status === "absent";

  async function onSave() {
    try {
      await adjustMut.mutateAsync({
        employee_id: row.employee_id,
        work_date: row.work_date,
        status,
        time_in: isAbsent || !timeIn ? null : timeIn,
        time_out: isAbsent || !timeOut ? null : timeOut,
        note: note || null,
      });
      toast.success("Attendance updated");
      onDone();
    } catch {
      toast.error("Could not update attendance. Check the times.");
    }
  }

  const level = ALERT_LEVELS.find((l) => l.sev === (row.flag?.severity ?? "green")) ?? ALERT_LEVELS[0];
  const pill = STATUS_PILL[status] ?? { cls: "bg-muted text-muted-foreground", label: titleCase(status) };

  const allViolations = violations ?? [];
  const totalPages = Math.max(1, Math.ceil(allViolations.length / VIOLATIONS_PER_PAGE));
  const pageViolations = allViolations.slice(
    (vpage - 1) * VIOLATIONS_PER_PAGE,
    (vpage - 1) * VIOLATIONS_PER_PAGE + VIOLATIONS_PER_PAGE,
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

      <div className="max-h-[65vh] space-y-6 overflow-y-auto px-0.5">
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
        </Section>

        {/* Today's record — editable */}
        <Section title="Today's Record">
          <div className="rounded-lg border border-border p-4">
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <Info label="Date" value={formatDate(row.work_date)} />
              <div className="space-y-1.5">
                <Label>Status</Label>
                <select className={selectClass} value={status} onChange={(e) => setStatus(e.target.value)}>
                  <option value="present">Present</option>
                  <option value="late">Late</option>
                  <option value="absent">Absent</option>
                </select>
              </div>
              <div className="space-y-1.5">
                <Label>Time In</Label>
                <Input type="time" value={timeIn} disabled={isAbsent} onChange={(e) => setTimeIn(e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <Label>Time Out</Label>
                <Input type="time" value={timeOut} disabled={isAbsent} onChange={(e) => setTimeOut(e.target.value)} />
              </div>
            </div>
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

        {/* Alert color legend */}
        <Section title="Alert Color Legend">
          <div className="space-y-2">
            {ALERT_LEVELS.map((l) => (
              <div key={l.sev} className="flex items-start gap-3 text-sm">
                <span className={cn("mt-1.5 size-2.5 shrink-0 rounded-full", DOT[l.sev])} />
                <span className="w-40 shrink-0 font-medium text-foreground">{l.label}</span>
                <span className="text-muted-foreground">{l.desc}</span>
              </div>
            ))}
          </div>
        </Section>

        {/* Violation history — paginated (5 per page) */}
        <Section title="Violation History">
          {allViolations.length === 0 ? (
            <p className="text-sm text-muted-foreground">No violations on record.</p>
          ) : (
            <div className="overflow-hidden rounded-lg border border-border">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
                    <th className="px-3 py-2">Date</th>
                    <th className="px-3 py-2">Type</th>
                    <th className="px-3 py-2">Instance</th>
                    <th className="px-3 py-2 text-center">Alert</th>
                    <th className="px-3 py-2 text-right"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {pageViolations.map((v) => (
                    <tr key={v.id}>
                      <td className="px-3 py-2 text-muted-foreground">{shortDate(v.work_date)}</td>
                      <td className="px-3 py-2 font-medium text-foreground">{titleCase(v.type)}</td>
                      <td className="px-3 py-2 text-muted-foreground">{v.details ?? "—"}</td>
                      <td className="px-3 py-2 text-center">
                        <span className={cn("inline-block size-2.5 rounded-full", DOT[v.severity])} />
                      </td>
                      <td className="px-3 py-2 text-right">
                        {v.resolved ? (
                          <span className="text-caption text-muted-foreground">Resolved</span>
                        ) : (
                          <Button size="xs" variant="outline" disabled={resolve.isPending} onClick={() => resolve.mutate(v.id)}>
                            Resolve
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {totalPages > 1 && (
                <div className="flex items-center justify-end gap-1 border-t border-border px-3 py-2">
                  <button
                    type="button"
                    disabled={vpage <= 1}
                    onClick={() => setVpage((p) => Math.max(1, p - 1))}
                    className="inline-flex size-6 items-center justify-center rounded-md border border-input transition-colors hover:bg-muted disabled:opacity-40"
                    aria-label="Previous page"
                  >
                    <ChevronLeft className="size-3.5" />
                  </button>
                  <span className="px-2 text-caption text-muted-foreground">Page {vpage} of {totalPages}</span>
                  <button
                    type="button"
                    disabled={vpage >= totalPages}
                    onClick={() => setVpage((p) => Math.min(totalPages, p + 1))}
                    className="inline-flex size-6 items-center justify-center rounded-md border border-input transition-colors hover:bg-muted disabled:opacity-40"
                    aria-label="Next page"
                  >
                    <ChevronRight className="size-3.5" />
                  </button>
                </div>
              )}
            </div>
          )}
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
