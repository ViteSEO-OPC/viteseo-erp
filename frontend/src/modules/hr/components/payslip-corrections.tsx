"use client";

import { useMemo, useState } from "react";
import { AxiosError } from "axios";
import { Plus, RotateCcw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { notify as toast } from "@/core/lib/notify";
import { formatPeso } from "@/core/lib/format";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { PayslipDocument } from "@/modules/hr/components/payslip-document";
import {
  useAmendPayrollRun,
  useCorrectPayslipLine,
  useRemovePayslipLineCorrection,
} from "@/modules/hr/hooks/use-payroll";
import type { AmendmentKind, Payslip } from "@/modules/hr/types/payroll";

/** CORRECT A PAYSLIP BY EDITING THE PAYSLIP. */
export function PayslipCorrections({
  payslip,
  editing,
}: {
  payslip: Payslip;
  /** Whether the document is a form. */
  editing: boolean;
}) {
  const [selected, setSelected] = useState<string | null>(null);
  const [adding, setAdding] = useState<"earning" | "deduction" | null>(null);

  const locked = payslip.run_is_locked;

  if (!editing) {
    return <PayslipDocument payslip={payslip} variant="hr" />;
  }

  return (
    <>
      {locked ? (
        <div className="border-b border-warning bg-warning-subtle px-6 py-3">
          <p className="text-sm font-semibold">This period is locked</p>
          <p className="mt-0.5 text-caption text-muted-foreground">
            Locking a run is the statement that the period is closed for good. Unlock it before
            correcting anything on it.
          </p>
        </div>
      ) : (
        <div className="border-b border-border px-6 py-3">
          <p className="text-sm font-semibold">Correcting {payslip.employee.name}&rsquo;s payslip</p>
          <p className="mt-0.5 text-caption text-muted-foreground">
            Click any line to change its amount or what it is called. The figure the payroll engine
            computed stays on the payslip, struck through beside yours.
          </p>
        </div>
      )}

      <PayslipDocument
        payslip={payslip}
        variant="hr"
        edit={
          locked
            ? undefined
            : {
                selected,
                onSelect: (key) => {
                  setSelected(key);
                  setAdding(null);
                },
                editor: selected ? (
                  <LineEditor
                    key={selected}
                    payslip={payslip}
                    lineKey={selected}
                    onDone={() => setSelected(null)}
                  />
                ) : null,
                addEarning: (
                  <AddLine
                    side="earning"
                    payslip={payslip}
                    open={adding === "earning"}
                    onOpen={() => {
                      setAdding(adding === "earning" ? null : "earning");
                      setSelected(null);
                    }}
                    onDone={() => setAdding(null)}
                  />
                ),
                addDeduction: (
                  <AddLine
                    side="deduction"
                    payslip={payslip}
                    open={adding === "deduction"}
                    onOpen={() => {
                      setAdding(adding === "deduction" ? null : "deduction");
                      setSelected(null);
                    }}
                    onDone={() => setAdding(null)}
                  />
                ),
              }
        }
      />
    </>
  );
}

/** The editor for one line, opened directly beneath it. */
function LineEditor({
  payslip,
  lineKey,
  onDone,
}: {
  payslip: Payslip;
  lineKey: string;
  onDone: () => void;
}) {
  const correction = useMemo(
    () => (payslip.amendments ?? []).find((a) => a.target_key === lineKey) ?? null,
    [payslip.amendments, lineKey],
  );

  const line = useMemo(() => findLine(payslip, lineKey), [payslip, lineKey]);

  const [amount, setAmount] = useState(() => (line ? String(line.amount) : ""));
  const [label, setLabel] = useState(() => correction?.label_override ?? "");
  const [reason, setReason] = useState("");

  const correctMut = useCorrectPayslipLine();
  const removeMut = useRemovePayslipLineCorrection();

  // NOT RE-SEEDED AFTER A SAVE. The editor closes on success and is remounted — keyed by the line
  // — the next time a line is opened, so the figures it starts from are always the ones currently
  // on the document rather than the ones that were there when it first rendered.
  if (!line) return null;

  const parsed = amount.trim() === "" ? null : Number(amount);
  const changed =
    (parsed !== null && Math.abs(parsed - line.amount) > 0.004) ||
    (label.trim() || null) !== (correction?.label_override ?? null);

  const save = async () => {
    if (parsed !== null && (Number.isNaN(parsed) || parsed < 0)) {
      toast.error("Enter the corrected amount as a positive figure.");
      return;
    }

    try {
      await correctMut.mutateAsync({
        payslipId: payslip.id,
        target_key: lineKey,
        amount: parsed,
        label: label.trim() || null,
        reason: reason.trim(),
      });
      toast.success(`${label.trim() || line.name} corrected.`);
      onDone();
    } catch (error) {
      toast.error(firstError(error) ?? "Could not save that correction.");
    }
  };

  const revert = async () => {
    if (!correction) return;

    try {
      await removeMut.mutateAsync({ payslipId: payslip.id, correctionId: correction.id });
      toast.success(`${line.name} is back to what payroll computed.`);
      onDone();
    } catch (error) {
      toast.error(firstError(error) ?? "Could not remove that correction.");
    }
  };

  const busy = correctMut.isPending || removeMut.isPending;

  return (
    <div className="border-b border-border bg-muted/40 px-4 py-4">
      <div className="flex flex-col gap-3">
        <div className="flex flex-col gap-3 sm:flex-row">
          <Field label="Amount" className="sm:w-40">
            <Input
              autoFocus
              type="number"
              step="0.01"
              min="0"
              inputMode="decimal"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="font-mono tabular-nums"
            />
          </Field>
          <Field label="Label" hint="Optional — what this line should be called">
            <Input
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              placeholder={line.name}
              maxLength={100}
            />
          </Field>
        </div>

        <Field label="Why" hint="Recorded on the payslip and in the audit trail">
          <textarea
            rows={2}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="What was wrong with this line?"
            className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
        </Field>

        {/*
         * WHAT THE ENGINE SAID, stated in the editor as well as on the line. HR is about to
         * overrule a computation, and the figure being overruled should be in front of them at
         * the moment they do it.
         */}
        {correction?.original_amount != null && (
          <p className="text-caption text-muted-foreground">
            Payroll computed{" "}
            <span className="font-mono font-semibold tabular-nums text-foreground">
              {formatPeso(correction.original_amount)}
            </span>
            {correction.issued_by ? ` · corrected by ${correction.issued_by}` : null}
          </p>
        )}

        <div className="flex flex-wrap items-center justify-end gap-2">
          {correction && (
            <Button variant="ghost" size="sm" onClick={revert} disabled={busy} className="mr-auto">
              <RotateCcw />
              {removeMut.isPending ? "Reverting…" : "Back to computed"}
            </Button>
          )}
          <Button variant="outline" size="sm" onClick={onDone} disabled={busy}>
            Cancel
          </Button>
          <Button
            size="sm"
            onClick={save}
            disabled={busy || !changed || reason.trim() === ""}
            title={reason.trim() === "" ? "Say what was wrong with this line first" : undefined}
          >
            {correctMut.isPending ? "Saving…" : "Save correction"}
          </Button>
        </div>
      </div>
    </div>
  );
}

/** ADD A LINE THE PAYSLIP NEVER HAD — back pay, a missed HMO share, an allowance nobody priced. */
function AddLine({
  side,
  payslip,
  open,
  onOpen,
  onDone,
}: {
  side: "earning" | "deduction";
  payslip: Payslip;
  open: boolean;
  onOpen: () => void;
  onDone: () => void;
}) {
  const [kind, setKind] = useState<AmendmentKind>(side === "earning" ? "pay" : "deduction");
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [reason, setReason] = useState("");

  const amendMut = useAmendPayrollRun();

  const options =
    side === "earning"
      ? [
          { value: "pay", label: "Pay more — taxable compensation" },
          { value: "pay_reversal", label: "Take back pay — it should not have been paid" },
        ]
      : [{ value: "deduction", label: "A deduction the payslip missed" }];

  const submit = async () => {
    const parsed = Number(amount);

    if (!amount.trim() || Number.isNaN(parsed) || parsed <= 0) {
      toast.error("Enter the amount as a positive figure.");
      return;
    }

    try {
      await amendMut.mutateAsync({
        runId: payslip.payroll_run_id,
        reason: reason.trim(),
        kind,
        lines: [
          {
            employee_id: payslip.employee.id as number,
            amount: parsed,
            note: note.trim() || null,
          },
        ],
      });
      toast.success("Line added to the payslip.");
      setAmount("");
      setNote("");
      setReason("");
      onDone();
    } catch (error) {
      toast.error(firstError(error) ?? "Could not add that line.");
    }
  };

  if (!open) {
    return (
      <button
        type="button"
        onClick={onOpen}
        className="flex w-full items-center gap-1.5 border-b border-border px-4 py-2.5 text-left text-caption font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <Plus className="size-3.5" />
        Add a line
      </button>
    );
  }

  return (
    <div className="border-b border-border bg-muted/40 px-4 py-4">
      <div className="flex flex-col gap-3">
        <Field label="What this is">
          <SimpleSelect
            value={kind}
            onValueChange={(v) => setKind(v as AmendmentKind)}
            options={options}
          />
        </Field>

        <div className="flex flex-col gap-3 sm:flex-row">
          <Field label="Amount" className="sm:w-40">
            <Input
              type="number"
              step="0.01"
              min="0.01"
              inputMode="decimal"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="font-mono tabular-nums"
            />
          </Field>
          <Field label="Label" hint="What the line is called on the payslip">
            <Input value={note} onChange={(e) => setNote(e.target.value)} maxLength={100} />
          </Field>
        </div>

        <Field label="Why" hint="Recorded on the payslip and in the audit trail">
          <textarea
            rows={2}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="What is this line for?"
            className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
        </Field>

        <div className="flex items-center justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onDone} disabled={amendMut.isPending}>
            Cancel
          </Button>
          <Button
            size="sm"
            onClick={submit}
            disabled={amendMut.isPending || reason.trim() === ""}
            title={reason.trim() === "" ? "Say what this line is for first" : undefined}
          >
            {amendMut.isPending ? "Adding…" : "Add line"}
          </Button>
        </div>
      </div>
    </div>
  );
}

function Field({
  label,
  hint,
  className,
  children,
}: {
  label: string;
  hint?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={`flex flex-1 flex-col gap-1.5 ${className ?? ""}`}>
      <label className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </label>
      {children}
      {hint && <p className="text-caption text-muted-foreground">{hint}</p>}
    </div>
  );
}

/** WHAT A LINE CURRENTLY SAYS, found the same way the API finds it. */
function findLine(payslip: Payslip, key: string): { name: string; amount: number } | null {
  const row = (payslip.deductions_breakdown ?? []).find((r) => r.key === key);
  if (row) return { name: row.name, amount: row.amount };

  const earning = (payslip.earnings_breakdown ?? []).find((l) => l.key === key);
  if (earning) return { name: earning.name, amount: earning.amount };

  const government: Record<string, { name: string; amount: number }> = {
    sss: { name: "SSS", amount: payslip.sss },
    philhealth: { name: "PhilHealth", amount: payslip.philhealth },
    pagibig: { name: "Pag-IBIG", amount: payslip.pagibig },
    withholding_tax: { name: "Withholding Tax", amount: payslip.withholding_tax },
  };

  return government[key] ?? null;
}

/** The API's own words when it refuses — never a generic failure message over a specific one. */
function firstError(error: unknown): string | null {
  const data = (error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>)
    ?.response?.data;

  return Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? null;
}
