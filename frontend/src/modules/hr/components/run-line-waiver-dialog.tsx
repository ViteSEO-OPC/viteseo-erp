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

/** THE CHARGES A WHOLE PERIOD CAN BE WRONG ABOUT. */
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

/** WAIVE ONE CHARGE FOR EVERYONE THE PERIOD PAYS. */
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
          <Button onClick={submit} disabled={waiveMut.isPending || reason.trim() === ""}>
            {waiveMut.isPending ? "Waiving…" : "Waive for everyone"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
