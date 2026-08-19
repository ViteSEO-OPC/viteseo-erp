"use client";

import { useState } from "react";
import { AxiosError } from "axios";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { notify as toast } from "@/core/lib/notify";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useCorrectRunLine } from "@/modules/hr/hooks/use-payroll";

/**
 * THE CHARGES A WHOLE PERIOD CAN BE WRONG ABOUT.
 *
 * Mirrors the attendance classes in Support\PayslipLines, and stops there deliberately. Waiving an
 * absence charge across a payroll is a real instruction — the badge readers were down, the office
 * was shut, a suspension was recorded twice. Waiving SSS for everybody is not a decision HR is
 * allowed to make, and putting it in this list would suggest otherwise.
 */
const WAIVABLE = [
  { value: "late", label: "Late" },
  { value: "undertime", label: "Undertime" },
  { value: "absent", label: "Absences" },
  { value: "half_day", label: "Half-Days" },
  { value: "suspended_unpaid", label: "Work Suspension" },
  { value: "leave_unpaid", label: "Leave Without Pay" },
  { value: "holiday_forfeited", label: "Holiday Forfeited" },
  { value: "early_out", label: "Early Out" },
  { value: "late_penalty", label: "Tardiness Penalty" },
];

/**
 * WAIVE ONE CHARGE FOR EVERYONE THE PERIOD PAYS.
 *
 * WHY THIS IS SEPARATE FROM CORRECTING A PAYSLIP. A correction to one payslip is a judgement about
 * one person's month; this is a judgement about the payroll — a system outage, a closure, a rule
 * that should not have run this cutoff. Doing it employee by employee is the same decision retyped
 * fifteen times, and the fifteenth is where the typo lives.
 *
 * IT ONLY WAIVES. "Set everyone's late charge to ₱300" is nonsense — each person's is a different
 * number — so the amount is not a field. The API takes one, because the rule is the caller's to
 * state, but the only form of it that means anything across a payroll is zero.
 *
 * PAYSLIPS WITHOUT THE CHARGE ARE LEFT ALONE, and the count says how many were actually touched:
 * "corrected 12 payslips" would read as "corrected everyone" when it was 12 of 15.
 */
export function RunLineWaiverDialog({
  runId,
  runLabel,
  open,
  onOpenChange,
}: {
  runId: number | null;
  runLabel: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const [key, setKey] = useState("late");
  const [reason, setReason] = useState("");
  const waiveMut = useCorrectRunLine();

  const submit = async () => {
    if (runId === null) return;

    try {
      const { applied, skipped } = await waiveMut.mutateAsync({
        runId,
        target_key: key,
        amount: 0,
        reason: reason.trim(),
      });

      toast.success(
        `${WAIVABLE.find((w) => w.value === key)?.label} waived on ${applied} payslip(s).` +
          (skipped.length > 0 ? ` ${skipped.length} could not be changed.` : ""),
      );
      setReason("");
      onOpenChange(false);
    } catch (error) {
      const data = (error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>)
        ?.response?.data;
      toast.error(
        Object.values(data?.errors ?? {}).flat()[0] ??
          data?.message ??
          "Could not waive that charge.",
      );
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[calc(100%-3rem)] sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Waive a charge for the whole period</DialogTitle>
          <DialogDescription>
            Sets the chosen charge to zero on every payslip in {runLabel} that carries one. Each
            payslip keeps the figure payroll computed, struck through beside the waived one, and
            anybody who was never charged is left untouched.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Charge to waive</label>
            <SimpleSelect value={key} onValueChange={setKey} options={WAIVABLE} />
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">
              Why <span className="text-destructive">*</span>
            </label>
            <textarea
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Badge readers were offline all cutoff — nobody was actually late."
              className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
            <p className="text-caption text-muted-foreground">
              Printed on every payslip this touches, and recorded against the run.
            </p>
          </div>
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button onClick={submit} disabled={waiveMut.isPending || reason.trim().length < 5}>
            {waiveMut.isPending ? "Waiving…" : "Waive for everyone"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
