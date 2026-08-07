"use client";

import { useState } from "react";
import { AxiosError } from "axios";

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
import { notify as toast } from "@/core/lib/notify";
import { formatPeso } from "@/core/lib/format";
import {
  useCreateBenefitProgram,
  usePatchBenefitProgram,
  useDeleteBenefitProgram,
} from "@/modules/hr/hooks/use-benefit-programs";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { EligibilityPicker } from "@/modules/hr/components/eligibility-picker";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import type { BenefitProgram, EligibilityMode } from "@/modules/hr/types/benefits";

/**
 * A loan policy — Slice B15. The PRODUCT, not a loan.
 *
 * HR defines what may be borrowed, by whom and over how long; issuing one to a person creates an
 * entry in that employee's loan ledger, which is where the debt lives. A loan cannot be a
 * programme itself: its balance decrements to zero, so modelling it as one would mean a new
 * programme per borrower.
 *
 * "Who may borrow" reuses the SAME eligibility rule an allowance uses — it is the same question,
 * and a second rule engine would be a second thing to get wrong.
 */
export function LoanPolicyDialog({
  open,
  policy,
  onOpenChange,
}: {
  open: boolean;
  /** null ⇒ creating a new policy. */
  policy: BenefitProgram | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        {open && (
          <PolicyForm
            key={policy?.id ?? "new"}
            policy={policy}
            onDone={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function PolicyForm({ policy, onDone }: { policy: BenefitProgram | null; onDone: () => void }) {
  const isNew = policy === null;
  const create = useCreateBenefitProgram();
  const patch = usePatchBenefitProgram();
  const remove = useDeleteBenefitProgram();
  const { data: employees } = useEmployees();
  const saving = create.isPending || patch.isPending || remove.isPending;

  const [confirming, setConfirming] = useState(false);
  const [name, setName] = useState(policy?.name ?? "");
  const [maxPrincipal, setMaxPrincipal] = useState(policy?.loan_max_principal ?? "");
  const [termPeriods, setTermPeriods] = useState(
    policy?.loan_term_periods != null ? String(policy.loan_term_periods) : "",
  );
  const [mode, setMode] = useState<EligibilityMode>(
    (policy?.eligibility as EligibilityMode) ?? "all",
  );
  const [statuses, setStatuses] = useState<string[]>(policy?.eligibility_statuses ?? []);
  const [employeeIds, setEmployeeIds] = useState<number[]>(policy?.employee_ids ?? []);

  const ruleEmpty =
    (mode === "status" && statuses.length === 0) || (mode === "custom" && employeeIds.length === 0);
  const valid = name.trim() !== "" && !ruleEmpty;

  async function save() {
    const body = {
      name: name.trim(),
      kind: "loan_policy",
      category: "general",
      loan_max_principal: maxPrincipal === "" ? null : Number(maxPrincipal),
      loan_term_periods: termPeriods === "" ? null : Number(termPeriods),
      eligibility: mode,
      eligibility_statuses: mode === "status" ? statuses : [],
      employee_ids: mode === "custom" ? employeeIds : [],
    };

    try {
      if (isNew) {
        await create.mutateAsync(body as never);
        toast.success(`${body.name} added`);
      } else {
        await patch.mutateAsync({ id: policy.id, patch: body });
        toast.success("Loan policy updated");
      }
      onDone();
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
      toast.error(errors ? String(Object.values(errors)[0]) : "Could not save the policy.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isNew ? "Add a loan" : `Edit ${policy.name}`}</DialogTitle>
        <DialogDescription>
          What may be borrowed, by whom, and over how long. Issuing a loan to someone happens on
          their profile.
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="lpol-name">Name</Label>
          <Input
            id="lpol-name"
            value={name}
            placeholder="e.g. Company Emergency Loan"
            onChange={(e) => setName(e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="space-y-1.5">
            <Label htmlFor="lpol-max">Most that can be borrowed</Label>
            <Input
              id="lpol-max"
              type="number"
              min="0"
              step="0.01"
              placeholder="No limit"
              value={maxPrincipal}
              onChange={(e) => setMaxPrincipal(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lpol-term">Repaid within (payslips)</Label>
            <Input
              id="lpol-term"
              type="number"
              min="1"
              placeholder="No limit"
              value={termPeriods}
              onChange={(e) => setTermPeriods(e.target.value)}
            />
          </div>
        </div>

        <EligibilityPicker
          mode={mode}
          statuses={statuses}
          employeeIds={employeeIds}
          employees={employees ?? []}
          onModeChange={setMode}
          onStatusesChange={setStatuses}
          onEmployeeIdsChange={setEmployeeIds}
        />

        <div className="rounded-lg border border-border bg-card px-3 py-2.5 text-xs text-foreground">
          <strong>Nothing is deducted without the employee&apos;s go-ahead.</strong> A loan issued
          under this policy waits until they authorize the deduction from their own pay — that
          consent is what makes it lawful, and it is theirs to give, not HR&apos;s to record for
          them.
        </div>

        {!isNew && (
          <div className="flex flex-col gap-3 rounded-lg border border-danger/40 bg-danger-subtle px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-sm font-semibold text-danger">Delete this loan</p>
              <p className="text-xs text-muted-foreground">
                Nobody can borrow under it again. Loans already issued keep repaying.
              </p>
            </div>
            <Button variant="outline" size="sm" onClick={() => setConfirming(true)}>
              Delete
            </Button>
          </div>
        )}
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button disabled={!valid || saving} onClick={save}>
          {saving ? "Saving…" : isNew ? "Add loan" : "Save changes"}
        </Button>
      </DialogFooter>

      {!isNew && (
        <DeleteConfirmDialog
          open={confirming}
          onOpenChange={setConfirming}
          title="Delete loan policy"
          question={`Delete “${policy.name}”?`}
          description={
            `Nobody can borrow under it from now on. Loans already issued are unaffected — ` +
            `they keep being repaid on the terms they were agreed on` +
            (policy.loan_max_principal
              ? `, up to ${formatPeso(policy.loan_max_principal)} each.`
              : ".")
          }
          confirmLabel="Delete"
          isPending={saving}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(policy.id);
              toast.success(`${policy.name} deleted`);
              onDone();
            } catch {
              toast.error("Could not delete the policy.");
            }
          }}
        />
      )}
    </>
  );
}
