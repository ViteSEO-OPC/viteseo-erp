"use client";

import { useState } from "react";
import { AxiosError } from "axios";

import { notify as toast } from "@/core/lib/notify";
import { formatPeso } from "@/core/lib/format";
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
import {
  useCreateBenefitProgram,
  useDeleteBenefitProgram,
  usePatchBenefitProgram,
} from "@/modules/hr/hooks/use-benefit-programs";
import { useEmployees } from "@/modules/hr/hooks/use-employees";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";
import { EligibilityPicker } from "@/modules/hr/components/eligibility-picker";
import {
  ALLOWANCE_FREQUENCIES,
  TAX_TREATMENTS,
  type BenefitProgram,
  type EligibilityMode,
} from "@/modules/hr/types/benefits";

/**
 * Add or edit an allowance — one form for both (Slice B6/B8 follow-up).
 *
 * WHY ONE FORM. Adding used to happen inside "Add a Plan", a two-tab modal that also assigned HMO
 * tiers and could create a plan inline; editing happened somewhere else with different fields. The
 * two disagreed about what an allowance even is. An allowance is an amount, a tax treatment, and
 * a rule saying who gets it — so there is one form that says exactly that, opened either empty or
 * on an existing row.
 */
export function AllowanceFormDialog({
  open,
  program,
  onOpenChange,
}: {
  open: boolean;
  /** null ⇒ creating a new allowance. */
  program: BenefitProgram | null;
  onOpenChange: (open: boolean) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        {open && (
          <AllowanceForm
            key={program?.id ?? "new"}
            program={program}
            onDone={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function AllowanceForm({
  program,
  onDone,
}: {
  program: BenefitProgram | null;
  onDone: () => void;
}) {
  const isNew = program === null;
  const create = useCreateBenefitProgram();
  const patch = usePatchBenefitProgram();
  const remove = useDeleteBenefitProgram();
  const { data: employees } = useEmployees();
  const saving = create.isPending || patch.isPending || remove.isPending;

  const [confirming, setConfirming] = useState(false);
  const [name, setName] = useState(program?.name ?? "");
  const [amount, setAmount] = useState(program?.amount ?? "");
  const [frequency, setFrequency] = useState(program?.frequency ?? "monthly");
  const [tax, setTax] = useState(program?.tax_treatment ?? "de_minimis");
  // Slice B16 — this allowance's OWN de-minimis ceiling. Blank means none recorded, which keeps
  // the whole amount tax-free; ceilings are per item and are never pooled with another benefit's.
  const [ceiling, setCeiling] = useState(program?.deminimis_ceiling ?? "");
  const [ceilingPeriod, setCeilingPeriod] = useState(program?.ceiling_period ?? "monthly");
  const [mode, setMode] = useState<EligibilityMode>(
    (program?.eligibility as EligibilityMode) ?? "all",
  );
  const [statuses, setStatuses] = useState<string[]>(program?.eligibility_statuses ?? []);
  const [employeeIds, setEmployeeIds] = useState<number[]>(program?.employee_ids ?? []);

  // The two rules that can cover nobody. Blocked here as well as on the server, because "saved,
  // and pays no one" is not something a toast afterwards can undo.
  const ruleEmpty =
    (mode === "status" && statuses.length === 0) ||
    (mode === "custom" && employeeIds.length === 0);
  const valid = name.trim() !== "" && amount !== "" && Number(amount) >= 0 && !ruleEmpty;

  async function save() {
    const body = {
      name: name.trim(),
      kind: "allowance",
      category: "allowance",
      amount: Number(amount),
      frequency,
      tax_treatment: tax,
      deminimis_ceiling: tax === "taxable" || ceiling === "" ? null : Number(ceiling),
      ceiling_period: tax === "taxable" || ceiling === "" ? null : ceilingPeriod,
      eligibility: mode,
      // Sent only for the mode that uses them, so switching mode cannot leave a stale rule behind.
      eligibility_statuses: mode === "status" ? statuses : [],
      employee_ids: mode === "custom" ? employeeIds : [],
    };

    try {
      if (isNew) {
        await create.mutateAsync(body as never);
        toast.success(`${body.name} added`);
      } else {
        await patch.mutateAsync({ id: program.id, patch: body });
        toast.success("Allowance updated");
      }
      onDone();
    } catch (err) {
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
      toast.error(errors ? String(Object.values(errors)[0]) : "Could not save the allowance.");
    }
  }

  async function performDelete() {
    if (isNew) return;
    try {
      await remove.mutateAsync(program.id);
      toast.success(`${program.name} deleted`);
      onDone();
    } catch {
      toast.error("Could not delete the allowance.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isNew ? "Add an allowance" : `Edit ${program.name}`}</DialogTitle>
        <DialogDescription>
          A fixed amount added to pay each period, on top of salary.
        </DialogDescription>
      </DialogHeader>

      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="a-name">Name</Label>
          <Input
            id="a-name"
            value={name}
            placeholder="e.g. Meal Allowance"
            onChange={(e) => setName(e.target.value)}
          />
        </div>

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

        {/* Slice B16 — a de-minimis benefit is exempt only UP TO its own statutory ceiling, and
            the ceilings cannot be pooled. Anything above joins the ₱90,000 13th-month-and-other-
            benefits exemption rather than being taxed outright. */}
        {tax !== "taxable" && (
          <div className="rounded-lg border border-border bg-card p-3">
            <div className="flex flex-wrap items-end gap-3">
              <div className="min-w-40 flex-1 space-y-1.5">
                <Label htmlFor="a-ceiling">Tax-free ceiling for this allowance</Label>
                <Input
                  id="a-ceiling"
                  type="number"
                  min="0"
                  step="0.01"
                  placeholder="None — fully tax-free"
                  value={ceiling}
                  onChange={(e) => setCeiling(e.target.value)}
                />
              </div>
              {ceiling !== "" && (
                <div className="min-w-36 space-y-1.5">
                  <Label htmlFor="a-ceiling-period">Per</Label>
                  <SimpleSelect
                    id="a-ceiling-period"
                    value={ceilingPeriod}
                    onValueChange={setCeilingPeriod}
                    options={[
                      { value: "monthly", label: "Month" },
                      { value: "semester", label: "Semester" },
                      { value: "annual", label: "Year" },
                    ]}
                  />
                </div>
              )}
            </div>
            <p className="mt-2 text-caption text-muted-foreground">
              BIR sets a limit per benefit — rice ₱2,500/month, uniform ₱8,000/year, medical cash
              allowance ₱2,000/semester. Anything above the limit is not taxed straight away: it
              counts toward the ₱90,000 covering 13th month pay and other benefits. Leave blank if
              this allowance has no statutory limit.
            </p>
          </div>
        )}

        <EligibilityPicker
          mode={mode}
          statuses={statuses}
          employeeIds={employeeIds}
          employees={employees ?? []}
          onModeChange={setMode}
          onStatusesChange={setStatuses}
          onEmployeeIdsChange={setEmployeeIds}
        />

        {/* Slice B14 — say who this edit actually REACHES. The old copy promised it applied to
            everyone, which stopped being true the moment one person could be paid their own
            amount; B6's version of this dialog claimed "14 enrolled employees will be affected"
            when the true answer was nought, and that is the mistake worth not repeating. */}
        <div className="rounded-lg border border-warning/40 bg-warning-subtle px-3 py-2.5 text-xs text-foreground">
          {isNew ? (
            "Everyone this covers is paid it from the next payroll cutoff."
          ) : program.reach.overridden > 0 ? (
            <>
              <strong>
                {program.reach.following} employee{program.reach.following === 1 ? "" : "s"}
              </strong>{" "}
              follow this amount and move with it from the next payroll cutoff.{" "}
              <strong>
                {program.reach.overridden} {program.reach.overridden === 1 ? "has" : "have"} their
                own amount
              </strong>{" "}
              and will not change — set on their profile, under Compensation.
            </>
          ) : (
            "Everyone this covers is paid this amount from the next payroll cutoff. Nobody has been given their own amount, so it reaches all of them."
          )}
        </div>

        {!isNew && (
          <div className="flex flex-col gap-3 rounded-lg border border-danger/40 bg-danger-subtle px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-sm font-semibold text-danger">Delete allowance</p>
              <p className="text-xs text-muted-foreground">
                {program.covered_count} employee
                {program.covered_count === 1 ? "" : "s"} stop being paid it from the next cutoff.
                Past payslips keep it.
              </p>
            </div>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirming(true)}
              disabled={saving}
            >
              Delete
            </Button>
          </div>
        )}
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button onClick={save} disabled={saving || !valid}>
          {saving ? "Saving…" : isNew ? "Add allowance" : "Save changes"}
        </Button>
      </DialogFooter>

      {!isNew && (
        /* The confirmation names the pay consequence rather than asking "are you sure": the
           number of people who stop being paid is the thing worth pausing over. */
        <DeleteConfirmDialog
          open={confirming}
          onOpenChange={setConfirming}
          title="Delete allowance"
          question={`Delete “${program.name}”?`}
          description={
            `${program.covered_count} employee${program.covered_count === 1 ? "" : "s"} ` +
            `stop being paid ${formatPeso(program.amount ?? 0)} from the next payroll cutoff. ` +
            "Payslips that already paid it are unchanged — they keep their own record of it."
          }
          confirmLabel="Delete"
          isPending={saving}
          onConfirm={performDelete}
        />
      )}
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
