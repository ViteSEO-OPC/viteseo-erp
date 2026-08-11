"use client";

import { useEffect, useState } from "react";
import { AxiosError } from "axios";
import { Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { notify as toast } from "@/core/lib/notify";
import { formatPeso } from "@/core/lib/format";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { useRunAdjustment } from "@/modules/hr/hooks/use-payroll";

interface DraftLine {
  employee_id: number | "";
  amount: string;
  note: string;
}

/**
 * Pay an off-cycle correction (FIN-010).
 *
 * WHAT THIS IS FOR. Until now the only ways money could move were an aligned regular period and the
 * December 13th-month run. Every correction the audit found — a back-dated raise, a fixed
 * attendance record, a late-approved overtime request or reimbursement — produced a difference with
 * nowhere to be paid: the only route was unlocking and re-running a closed period, which rewrites
 * history and is refused once payslips are disbursed.
 *
 * WHAT IT DELIBERATELY DOES NOT DO, and why the form looks like this. It does not recompute
 * anything — no attendance, no proration, no statutory contributions (those are monthly and were
 * already taken on the period being corrected). HR states who and how much; the backend withholds
 * tax on top of what the employee has already been taxed this year and issues a payslip. That is
 * why this is a plain list of amounts rather than a period picker: the engine is not being asked
 * for an opinion.
 *
 * THE DATE WINDOW IS WHEN THE CORRECTION IS ISSUED, not the period being fixed — that goes in the
 * reason. Defaulting both ends to today is almost always right.
 *
 * NO NEGATIVE AMOUNTS. Recovering an overpayment is a wage deduction needing the employee's written
 * consent (Art. 113), so it is a separate process and the backend rejects it.
 */
export function AdjustmentRunDialog({
  open,
  onOpenChange,
  prefillEmployeeId,
  prefillReason,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Set when opened from an "owed" correction, so HR does not re-find the employee. */
  prefillEmployeeId?: number | null;
  prefillReason?: string | null;
}) {
  const today = new Date().toISOString().slice(0, 10);
  const { data: employees = [] } = useEmployees();
  const runMut = useRunAdjustment();

  const [cycleStart, setCycleStart] = useState(today);
  const [cycleEnd, setCycleEnd] = useState(today);
  const [reason, setReason] = useState("");
  const [lines, setLines] = useState<DraftLine[]>([{ employee_id: "", amount: "", note: "" }]);

  // Re-seed whenever the dialog opens, so a prefill from the corrections queue lands and a
  // previous draft never leaks into the next correction.
  useEffect(() => {
    if (!open) return;
    setCycleStart(today);
    setCycleEnd(today);
    setReason(prefillReason ?? "");
    setLines([{ employee_id: prefillEmployeeId ?? "", amount: "", note: "" }]);
  }, [open, prefillEmployeeId, prefillReason, today]);

  const validLines = lines.filter((l) => l.employee_id !== "" && Number(l.amount) > 0);
  const total = validLines.reduce((s, l) => s + Number(l.amount), 0);
  const canSubmit = reason.trim().length >= 5 && validLines.length > 0 && !runMut.isPending;

  function update(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  async function submit() {
    try {
      const run = await runMut.mutateAsync({
        cycle_start: cycleStart,
        cycle_end: cycleEnd,
        reason: reason.trim(),
        lines: validLines.map((l) => ({
          employee_id: Number(l.employee_id),
          amount: Number(l.amount),
          note: l.note.trim() || null,
        })),
      });
      toast.success(`Adjustment paid to ${run.employee_count} employee(s)`);
      onOpenChange(false);
    } catch (e) {
      const err = e as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const first = Object.values(err.response?.data?.errors ?? {})[0]?.[0];
      toast.error(first ?? err.response?.data?.message ?? "Could not run the adjustment");
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Pay an adjustment</DialogTitle>
          <DialogDescription>
            An off-cycle correction. It pays the amounts you enter and withholds tax — it does not
            recompute attendance or take statutory contributions, which were already taken on the
            period being corrected.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[60vh] space-y-4 overflow-y-auto">
          <div className="space-y-1.5">
            <label htmlFor="adj-reason" className="text-caption font-medium">
              Reason <span className="text-muted-foreground">(required — say which period this corrects)</span>
            </label>
            <Input
              id="adj-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Back pay for 1–15 March after a back-dated raise"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label htmlFor="adj-start" className="text-caption font-medium">Issued from</label>
              <DatePicker id="adj-start" value={cycleStart} onChange={setCycleStart} />
            </div>
            <div className="space-y-1.5">
              <label htmlFor="adj-end" className="text-caption font-medium">Issued to</label>
              <DatePicker id="adj-end" value={cycleEnd} onChange={setCycleEnd} />
            </div>
          </div>
          <p className="-mt-2 text-caption text-muted-foreground">
            These dates are when the correction is issued, not the period being fixed.
          </p>

          <div className="space-y-2">
            <p className="text-caption font-medium">Who is being paid</p>
            {lines.map((line, i) => (
              <div key={i} className="flex items-start gap-2">
                <SimpleSelect
                  aria-label="Employee"
                  className="min-w-0 flex-1"
                  placeholder="Select an employee…"
                  value={line.employee_id === "" ? undefined : String(line.employee_id)}
                  onValueChange={(v) => update(i, { employee_id: v ? Number(v) : "" })}
                  options={employees.map((emp) => ({
                    value: String(emp.id),
                    label: emp.name ?? emp.employee_no,
                  }))}
                />
                <Input
                  aria-label="Amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  className="w-32"
                  placeholder="Amount"
                  value={line.amount}
                  onChange={(e) => update(i, { amount: e.target.value })}
                />
                <Input
                  aria-label="Note"
                  className="w-40"
                  placeholder="Note (optional)"
                  value={line.note}
                  onChange={(e) => update(i, { note: e.target.value })}
                />
                <Button
                  variant="ghost"
                  size="icon-sm"
                  disabled={lines.length === 1}
                  onClick={() => setLines((prev) => prev.filter((_, idx) => idx !== i))}
                  title="Remove this line"
                >
                  <Trash2 />
                  <span className="sr-only">Remove</span>
                </Button>
              </div>
            ))}

            <Button
              variant="outline"
              size="sm"
              onClick={() => setLines((prev) => [...prev, { employee_id: "", amount: "", note: "" }])}
            >
              <Plus />
              Add another
            </Button>
          </div>

          {validLines.length > 0 && (
            <p className="text-body">
              <span className="text-muted-foreground">Total to pay: </span>
              <span className="font-semibold">{formatPeso(total)}</span>
              <span className="text-muted-foreground"> across {validLines.length} employee(s), before tax</span>
            </p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button disabled={!canSubmit} onClick={submit}>
            {runMut.isPending ? "Paying…" : "Pay adjustment"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
