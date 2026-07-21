"use client";

import { useState } from "react";
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
import { SimpleSelect } from "@/modules/hr/ui/select";
import { usePatchBenefitProgram } from "@/modules/hr/hooks/use-benefit-programs";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import {
  ALLOWANCE_FREQUENCIES,
  TAX_TREATMENTS,
  ELIGIBILITY_OPTIONS,
  type BenefitProgram,
} from "@/modules/hr/types/benefits";

export function EditAllowanceDialog({
  program,
  onOpenChange,
}: {
  program: BenefitProgram | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={!!program} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        {program && <AllowanceForm key={program.id} program={program} onDone={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function AllowanceForm({ program, onDone }: { program: BenefitProgram; onDone: () => void }) {
  const patch = usePatchBenefitProgram();
  const [confirming, setConfirming] = useState(false);
  const [amount, setAmount] = useState(program.amount ?? "");
  const [frequency, setFrequency] = useState(program.frequency);
  const [tax, setTax] = useState(program.tax_treatment);
  const [elig, setElig] = useState(program.eligibility);

  const enrolled = program.employee_ids.length;

  async function save() {
    try {
      await patch.mutateAsync({
        id: program.id,
        patch: {
          amount: Number(amount),
          frequency,
          tax_treatment: tax,
          eligibility: elig,
        },
      });
      toast.success("Allowance updated");
      onDone();
    } catch {
      toast.error("Could not save the allowance.");
    }
  }

  async function performDeactivate() {
    try {
      await patch.mutateAsync({ id: program.id, patch: { is_active: false } });
      toast.success("Allowance deactivated");
      onDone();
    } catch {
      toast.error("Could not deactivate the allowance.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Edit {program.name}</DialogTitle>
        <DialogDescription>Allowance · added to payroll</DialogDescription>
      </DialogHeader>

      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="a-amount">Amount per period</Label>
            <Input
              id="a-amount"
              type="number"
              min="0"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="a-freq">Frequency</Label>
            <SimpleSelect
              id="a-freq"
              value={frequency}
              onValueChange={setFrequency}
              options={ALLOWANCE_FREQUENCIES.map((f) => ({ value: f.value, label: f.label }))}
            />
          </div>
        </div>

        <Segmented label="Tax treatment" options={TAX_TREATMENTS} value={tax} onChange={setTax} />
        <Segmented label="Eligibility" options={ELIGIBILITY_OPTIONS} value={elig} onChange={setElig} />

        <div className="rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2.5 text-xs text-foreground">
          {enrolled} enrolled employee{enrolled === 1 ? "" : "s"} will be affected. Changes apply
          from the next payroll cutoff.
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-danger/40 bg-danger-subtle px-3 py-2.5">
          <div>
            <p className="text-sm font-semibold text-danger">Deactivate allowance</p>
            <p className="text-xs text-muted-foreground">Stops future payouts. History is kept.</p>
          </div>
          <Button variant="outline" size="sm" onClick={() => setConfirming(true)} disabled={patch.isPending}>
            Deactivate
          </Button>
        </div>
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button onClick={save} disabled={patch.isPending || amount === ""}>
          {patch.isPending ? "Saving…" : "Save changes"}
        </Button>
      </DialogFooter>

      {/* Deactivation isn't a delete, so the copy and the button label say so —
          the red treatment is still right for a destructive-ish action. */}
      <DeleteConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title="Deactivate allowance"
        question={`Are you sure you want to deactivate “${program.name}”?`}
        description="Future payouts stop. Past history is kept."
        confirmLabel="Deactivate"
        isPending={patch.isPending}
        onConfirm={performDeactivate}
      />
    </>
  );
}

// A pill segmented control (single select).
function Segmented({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: readonly { value: string; label: string }[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className="space-y-1.5">
      <Label>{label}</Label>
      <div className="flex flex-wrap gap-2">
        {options.map((o) => (
          <button
            key={o.value}
            type="button"
            onClick={() => onChange(o.value)}
            className={cn(
              "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
              value === o.value
                ? "border-brand bg-brand-subtle text-dark-teal"
                : "border-input text-muted-foreground hover:bg-muted",
            )}
          >
            {o.label}
          </button>
        ))}
      </div>
    </div>
  );
}
