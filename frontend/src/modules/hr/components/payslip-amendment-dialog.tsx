"use client";

import { useMemo, useState } from "react";
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
import { formatDate, formatPeso } from "@/core/lib/format";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useAmendPayrollRun, usePayrollRun, usePayrollRuns } from "@/modules/hr/hooks/use-payroll";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import type { AmendmentKind, PayrollRun } from "@/modules/hr/types/payroll";

interface DraftLine {
  employee_id: number | "";
  amount: string;
  note: string;
}

/**
 * The three corrections, in the words HR uses for them — and what each does to the tax.
 *
 * ONE CHOICE FOR THE WHOLE BATCH, not per line. A batch already shares one reason and one issue
 * date: it is one correction applied to several people, and a batch that both paid and deducted
 * would need two reasons to explain itself.
 *
 * SPELLING OUT THE TAX IS THE POINT. "Minus ₱1,000" describes both of the last two, and they are
 * not the same money: reversing pay gives back the tax withheld on it, while a missed deduction
 * must not, or the correction quietly hands back tax the BIR is owed.
 */
const KINDS: Record<
  AmendmentKind,
  { label: string; hint: string; amountLabel: string; placeholder: string; verb: string }
> = {
  pay: {
    label: "Pay more — something was missed",
    hint: "Added to the payslip as taxable pay, with tax withheld on it at the year's rate.",
    amountLabel: "Amount to add",
    placeholder: "Amount",
    verb: "added",
  },
  pay_reversal: {
    label: "Take back pay — it should not have been paid",
    hint: "Removed from the payslip's pay, and the tax withheld on it is refunded with it.",
    amountLabel: "Amount to reverse",
    placeholder: "To reverse",
    verb: "reversed",
  },
  deduction: {
    label: "Add a deduction the payslip missed",
    hint: "Comes off net pay only — pay and tax are untouched, since what was earned was right. What the payslip cannot cover carries to the next period.",
    amountLabel: "Amount to deduct",
    placeholder: "To deduct",
    verb: "deducted",
  },
};

/**
 * How a period reads in the picker.
 *
 * A 13th month and a final pay are named by what they are, since neither describes a cutoff — but
 * both are real documents that can be wrong, so both are correctable. The retired adjustment runs
 * are not listed at all; see the filter in the form.
 */
function runLabel(run: PayrollRun): string {
  const year = run.cycle_end.slice(0, 4);
  if (run.type === "thirteenth_month") return `13th month · ${year}`;
  if (run.type === "final_pay") return `Final pay · ${formatDate(run.cycle_end)}`;
  return `${formatDate(run.cycle_start)} – ${formatDate(run.cycle_end)} · ${payFrequencyLabel(run.frequency)}`;
}

/**
 * Correct a period on its own payslips.
 *
 * WHAT CHANGED, AND WHY THE FORM LOOKS DIFFERENT. This replaces the off-cycle adjustment RUN, which
 * paid a correction by issuing a second payslip in a window HR typed by hand. The employee then held
 * two documents for one period and had to add them together, and the run they were paid under
 * matched no cutoff on the calendar. A correction now lands on the payslip it belongs to: the period
 * is CHOSEN from the runs that exist, so it cannot be pointed at dates nothing was ever paid for.
 *
 * WHAT IT STILL DOES NOT DO. It recomputes nothing — no attendance, no proration, no statutory
 * contributions, which are monthly and were already taken on the period being corrected. HR states
 * who and how much; the backend withholds tax on top of what the employee has been taxed this year.
 * Re-running the period later re-applies these corrections rather than erasing them.
 *
 * AMENDING AN ALREADY-PAID PAYSLIP IS ALLOWED, and is the main reason this exists. The payslip's net
 * rises while what actually left the bank stays where it is, so the difference shows as a balance
 * still to pay — the row and the document both say so, and the run cannot be locked until it is
 * settled. The form says which of the chosen employees are in that position BEFORE submitting,
 * because "this raises their net" and "this owes them money" are different sentences to sign off.
 *
 * A CORRECTION CAN ALSO TAKE MONEY, in one of two ways that look identical on a payslip and are not
 * the same thing — see KINDS. Amounts are always typed as positive figures and the kind carries the
 * direction, so there is no way to enter a deduction that accidentally pays.
 *
 * TAKING MONEY BACK FROM A PAID PAYSLIP IS STATED, NEVER TAKEN. It leaves the payslip's net below
 * what was transferred, and that difference is shown as an overpayment for a person to act on:
 * recovering wages already paid needs the employee's written consent under Art. 113, so no future
 * payslip collects it automatically.
 */
export function PayslipAmendmentDialog({
  open,
  onOpenChange,
  prefillRunId,
  prefillEmployeeId,
  prefillReason,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The period to open on — the one on screen, or the one an outstanding difference belongs to. */
  prefillRunId?: number | null;
  /** Set when opened from an "owed" correction, so HR does not re-find the employee. */
  prefillEmployeeId?: number | null;
  prefillReason?: string | null;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Correct a payslip</DialogTitle>
          <DialogDescription>
            Adds a dated correction to the period&rsquo;s existing payslips — no new payslip, no
            re-run. It can pay more, take back pay, or add a deduction the payslip missed, and it
            takes no statutory contributions, which were already taken on the period being corrected.
          </DialogDescription>
        </DialogHeader>

        {/*
          The form is its own component so the draft lives and dies with the open dialog.

          Radix unmounts the content on close, so every open starts from the prefills as state
          initialisers — no effect re-seeding fields, and no path where a previous correction's
          amounts survive into the next one.
        */}
        <AmendmentForm
          prefillRunId={prefillRunId}
          prefillEmployeeId={prefillEmployeeId}
          prefillReason={prefillReason}
          onClose={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  );
}

function AmendmentForm({
  prefillRunId,
  prefillEmployeeId,
  prefillReason,
  onClose,
}: {
  prefillRunId?: number | null;
  prefillEmployeeId?: number | null;
  prefillReason?: string | null;
  onClose: () => void;
}) {
  const today = new Date().toISOString().slice(0, 10);
  const { data: runs = [] } = usePayrollRuns();
  const amendMut = useAmendPayrollRun();

  const [pickedRunId, setPickedRunId] = useState<number | null>(prefillRunId ?? null);
  const [issuedOn, setIssuedOn] = useState(today);
  const [reason, setReason] = useState(prefillReason ?? "");
  const [kind, setKind] = useState<AmendmentKind>("pay");
  const [lines, setLines] = useState<DraftLine[]>([
    { employee_id: prefillEmployeeId ?? "", amount: "", note: "" },
  ]);
  /*
    EVERYONE AT ONE AMOUNT — the shape a company-wide correction actually has: a benefit share that
    started mid-period, an allowance the whole payroll was owed. It is a MODE rather than a button
    that fills the rows, because the figure is one decision, not N identical ones: typed once, it
    cannot drift between employees, and there is a single field to change when it turns out wrong.

    Opened per-employee when the dialog was given someone to correct — that prefill IS the answer to
    "who", and it comes from a difference owed to one person.
  */
  const [everyone, setEveryone] = useState(false);
  const [bulkAmount, setBulkAmount] = useState("");
  const [bulkNote, setBulkNote] = useState("");

  /*
    A locked run can never be recomputed, so a correction on it could never be re-applied — and an
    incomplete one has no payslips to correct. Both are refused by the backend; leaving them out of
    the picker is how HR finds that out before typing amounts.

    THE RETIRED ADJUSTMENT RUNS ARE OUT TOO. Each invented a one-day window for itself instead of
    covering a cutoff, so it appears in a list of periods as a date with no range — and correcting
    one would amend that stray payslip rather than the period whose figure was wrong. They are
    history to be read, not periods to be corrected.
  */
  const amendable = useMemo(
    () => runs.filter((r) => r.status === "completed" && !r.is_locked && r.type !== "adjustment"),
    [runs],
  );

  /*
    DERIVED, not stored. The picker opens on the period it was given, but that period may not be
    amendable — the run list arrives after this mounts, and a prefilled run can be locked. Falling
    back to the newest amendable run keeps the form usable either way, without an effect writing
    over a choice HR has already made.
  */
  const runId = amendable.some((r) => r.id === pickedRunId)
    ? pickedRunId
    : (amendable[0]?.id ?? null);

  const { data: detail } = usePayrollRun(runId ?? undefined);
  const selectedRun = amendable.find((r) => r.id === runId);
  const breakdown = detail?.breakdown ?? [];

  const takesMoney = kind !== "pay";
  const spec = KINDS[kind];

  /**
   * WHAT WILL BE POSTED, whichever way it was entered.
   *
   * Both modes reduce to the same list here, so the total, every warning and the submit read one
   * shape. The alternative — each of them asking "are we in bulk mode?" — is how a form ends up
   * warning about the rows on screen while sending something else.
   */
  const payloadLines = everyone
    ? Number(bulkAmount) > 0
      ? breakdown.map((row) => ({
          employee_id: row.employee_id,
          amount: Number(bulkAmount),
          note: bulkNote,
        }))
      : []
    : lines
        .filter((l) => l.employee_id !== "" && Number(l.amount) > 0)
        .map((l) => ({
          employee_id: Number(l.employee_id),
          amount: Number(l.amount),
          note: l.note,
        }));

  const total = payloadLines.reduce((s, l) => s + l.amount, 0);

  /**
   * The chosen employees whose payslip has already been paid out.
   *
   * Paying them leaves a balance owed; taking money off them leaves the company overpaid — two
   * different sentences, and the one that involves recovering wages is the one to be sure about.
   */
  const alreadyPaid = payloadLines
    .map((l) => breakdown.find((r) => r.employee_id === l.employee_id))
    .filter((row) => row?.disbursed != null);

  /**
   * Reversals that ask for more pay than the payslip holds — refused by the backend, so it is said
   * here instead of after a submit. A deduction is exempt: it is an obligation, and what pay cannot
   * cover carries to the next period rather than being rejected.
   */
  const overReversed =
    kind === "pay_reversal"
      ? payloadLines.filter((l) => {
          const row = breakdown.find((r) => r.employee_id === l.employee_id);
          return row != null && l.amount > row.net;
        })
      : [];

  /** Deductions bigger than the payslip they are entered against — the part that carries forward. */
  const shortfalls =
    kind === "deduction"
      ? payloadLines.filter((l) => {
          const row = breakdown.find((r) => r.employee_id === l.employee_id);
          return row != null && l.amount > row.net;
        })
      : [];

  const canSubmit =
    !!runId &&
    reason.trim().length >= 5 &&
    payloadLines.length > 0 &&
    overReversed.length === 0 &&
    !amendMut.isPending;

  function update(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  async function submit() {
    if (!runId) return;
    try {
      await amendMut.mutateAsync({
        runId,
        reason: reason.trim(),
        issued_on: issuedOn,
        kind,
        lines: payloadLines.map((l) => ({
          employee_id: l.employee_id,
          // A magnitude — the direction is the batch's kind. See KINDS.
          amount: Math.abs(l.amount),
          note: l.note.trim() || null,
        })),
      });
      toast.success(
        `${formatPeso(total)} ${spec.verb} on ${payloadLines.length} payslip(s)` +
          (alreadyPaid.length > 0
            ? takesMoney
              ? ` — ${alreadyPaid.length} already paid, so the difference reads as overpaid`
              : ` — ${alreadyPaid.length} now owe(s) a balance`
            : ""),
      );
      onClose();
    } catch (e) {
      const err = e as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const first = Object.values(err.response?.data?.errors ?? {})[0]?.[0];
      toast.error(first ?? err.response?.data?.message ?? "Could not apply the correction");
    }
  }

  if (amendable.length === 0) {
    return (
      <>
        <p className="py-8 text-center text-sm text-muted-foreground">
          No period can be corrected yet. A period has to be run first, and a locked run has to be
          unlocked — a correction on it could never be recomputed.
        </p>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </>
    );
  }

  return (
    <>
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-[1fr_10rem]">
          <div className="space-y-1.5">
            <label htmlFor="amend-run" className="text-caption font-medium">
              Period to correct
            </label>
            <SimpleSelect
              id="amend-run"
              placeholder="Select a period…"
              value={runId ? String(runId) : undefined}
              onValueChange={(v) => {
                setPickedRunId(Number(v));
                // The employees come from the period, so a name chosen under the old one may not
                // have a payslip in the new one.
                setLines([{ employee_id: "", amount: "", note: "" }]);
              }}
              options={amendable.map((r) => ({ value: String(r.id), label: runLabel(r) }))}
            />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="amend-issued" className="text-caption font-medium">
              Issued on
            </label>
            {/* When the correction is issued — NOT the period being fixed, which is the field
                beside it. It prints on the payslip beside the amount. */}
            <DatePicker id="amend-issued" value={issuedOn} onChange={setIssuedOn} />
          </div>
        </div>

        <div className="space-y-1.5">
          <label htmlFor="amend-kind" className="text-caption font-medium">
            What kind of correction
          </label>
          <SimpleSelect
            id="amend-kind"
            value={kind}
            onValueChange={(v) => setKind(v as AmendmentKind)}
            options={(Object.keys(KINDS) as AmendmentKind[]).map((k) => ({
              value: k,
              label: KINDS[k].label,
            }))}
          />
          {/* What it does to the tax, which is the whole difference between the last two. */}
          <p className="text-caption text-muted-foreground">{spec.hint}</p>
        </div>

        <div className="space-y-1.5">
          <label htmlFor="amend-reason" className="text-caption font-medium">
            Reason{" "}
            <span className="text-muted-foreground">(required — it prints on the payslip)</span>
          </label>
          <Input
            id="amend-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={
              kind === "deduction"
                ? "e.g. HMO share not deducted for this cutoff"
                : kind === "pay_reversal"
                  ? "e.g. Transport allowance paid twice this cutoff"
                  : "e.g. Back pay after a back-dated raise"
            }
          />
        </div>

        <div className="space-y-2">
          <p className="text-caption font-medium">
            {takesMoney ? "Who it comes off" : "Who is owed it"}
            {selectedRun && (
              <span className="font-normal text-muted-foreground">
                {" "}
                — the {breakdown.length || selectedRun.employee_count} employees this period paid
              </span>
            )}
          </p>

          {/* The whole period at one figure, for the corrections that are company-wide by nature.
              Beside the heading rather than at the foot of the rows: it decides what the rest of
              this section even looks like. */}
          <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border px-3 py-2.5">
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0 rounded border-input accent-brand"
              checked={everyone}
              disabled={!detail || breakdown.length === 0}
              onChange={(e) => setEveryone(e.target.checked)}
            />
            <span className="text-sm text-foreground">
              Apply the same amount to everyone in this period
              <span className="block text-caption text-muted-foreground">
                One figure for all {breakdown.length || selectedRun?.employee_count} employees — for
                a deduction or an amount the whole payroll shares. Untick to set different amounts
                per employee.
              </span>
            </span>
          </label>

          {everyone ? (
            <div className="flex flex-wrap items-start gap-2">
              <Input
                aria-label={spec.amountLabel}
                type="number"
                min="0.01"
                step="0.01"
                className="w-36"
                placeholder={spec.placeholder}
                value={bulkAmount}
                onChange={(e) => setBulkAmount(e.target.value)}
              />
              <Input
                aria-label="Note"
                className="min-w-0 flex-1"
                placeholder="Note on every payslip (optional)"
                value={bulkNote}
                onChange={(e) => setBulkNote(e.target.value)}
              />
            </div>
          ) : (
            lines.map((line, i) => {
              const row = breakdown.find((r) => r.employee_id === Number(line.employee_id));

              return (
                <div key={i} className="space-y-1">
                  <div className="flex items-start gap-2">
                    <SimpleSelect
                      aria-label="Employee"
                      className="min-w-0 flex-1"
                      placeholder={detail ? "Select an employee…" : "Loading this period…"}
                      disabled={!detail}
                      value={line.employee_id === "" ? undefined : String(line.employee_id)}
                      onValueChange={(v) => update(i, { employee_id: v ? Number(v) : "" })}
                      options={breakdown.map((r) => ({
                        value: String(r.employee_id),
                        label: r.employee ?? `Employee #${r.employee_id}`,
                        // One correction per employee per submission: two lines for the same person
                        // would be one payslip amended twice in a single click, which reads as a
                        // duplicate rather than as two reasons.
                        disabled: lines.some(
                          (other, idx) => idx !== i && other.employee_id === r.employee_id,
                        ),
                      }))}
                    />
                    <Input
                      aria-label={spec.amountLabel}
                      type="number"
                      min="0.01"
                      step="0.01"
                      className="w-32"
                      placeholder={spec.placeholder}
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
                  {/* What this payslip currently says, so the amount is entered against the figure
                      it is correcting rather than from memory. */}
                  {row && (
                    <p className="pl-1 text-caption text-muted-foreground">
                      Net {formatPeso(row.net)}
                      {row.disbursed != null && ` · ${formatPeso(row.disbursed)} already paid`}
                      {row.disbursed != null &&
                        row.outstanding > 0 &&
                        ` · ${formatPeso(row.outstanding)} outstanding`}
                      {/* What the payslip cannot cover, said against the line that causes it rather
                          than as a total afterwards. */}
                      {kind === "deduction" && Number(line.amount) > row.net && (
                        <span className="text-warning">
                          {" "}
                          · only {formatPeso(row.net)} can be taken —{" "}
                          {formatPeso(Number(line.amount) - row.net)} carries to the next period
                        </span>
                      )}
                    </p>
                  )}
                </div>
              );
            })
          )}

          {!everyone && (
            <Button
              variant="outline"
              size="sm"
              disabled={!detail || lines.length >= breakdown.length}
              onClick={() =>
                setLines((prev) => [...prev, { employee_id: "", amount: "", note: "" }])
              }
            >
              <Plus />
              Add another
            </Button>
          )}
        </div>

        {payloadLines.length > 0 && (
          <p className="text-body">
            <span className="text-muted-foreground">
              {takesMoney ? "Total to take off: " : "Total correction: "}
            </span>
            <span className="font-semibold">
              {takesMoney && "−"}
              {formatPeso(total)}
            </span>
            <span className="text-muted-foreground">
              {" "}
              across {payloadLines.length} payslip(s)
              {everyone && ` at ${formatPeso(Number(bulkAmount))} each`}
              {kind === "deduction" ? "" : ", before tax"}
            </span>
          </p>
        )}

        {/* In bulk mode there are no rows to say this beside, and it is the case bulk mode makes
            likely: one figure against a payroll where somebody had a thin period. */}
        {everyone && shortfalls.length > 0 && (
          <div className="rounded-card border border-warning/40 bg-warning/10 p-3">
            <p className="text-sm font-medium text-foreground">
              {shortfalls.length} payslip{shortfalls.length === 1 ? "" : "s"} cannot cover{" "}
              {formatPeso(Number(bulkAmount))}
            </p>
            <p className="text-caption text-muted-foreground">
              Those take what the payslip pays and carry the rest to the next period, where it is
              recovered automatically along with any other uncollected deduction.
            </p>
          </div>
        )}

        {/* Refused by the backend, so it is said before the submit rather than after it. */}
        {overReversed.length > 0 && (
          <div className="rounded-card border border-destructive/40 bg-destructive/10 p-3">
            <p className="text-sm font-medium text-foreground">
              {overReversed.length} line(s) reverse more pay than the payslip holds
            </p>
            <p className="text-caption text-muted-foreground">
              Pay that is not on this period cannot be reversed from it. Lower the amount, or correct
              the period the pay was actually on. To collect an obligation the payslip missed instead,
              use &ldquo;Add a deduction&rdquo; — that one carries forward what pay cannot cover.
            </p>
          </div>
        )}

        {/* The one consequence that is not obvious from the form: a period finance has already
            settled no longer matches what was transferred. */}
        {alreadyPaid.length > 0 && (
          <div className="rounded-card border border-warning/40 bg-warning/10 p-3">
            <p className="text-sm font-medium text-foreground">
              {alreadyPaid.length} of these payslip(s) have already been paid
            </p>
            <p className="text-caption text-muted-foreground">
              {takesMoney
                ? "What was transferred does not change, so the payslip will show that they were overpaid by the difference. Nothing collects it automatically — recovering wages already paid needs the employee's written consent, so this records it for someone to arrange."
                : "The correction does not change what was transferred — it will show as a balance still to pay on the payslip and in the run, and the run cannot be locked until it is settled."}
            </p>
          </div>
        )}
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>
          Cancel
        </Button>
        <Button disabled={!canSubmit} onClick={submit}>
          {amendMut.isPending ? "Applying…" : "Apply correction"}
        </Button>
      </DialogFooter>
    </>
  );
}
