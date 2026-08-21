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
import {
  useAmendPayrollRun,
  useCorrectRunLine,
  usePayrollRun,
  usePayrollRuns,
} from "@/modules/hr/hooks/use-payroll";
import { ToastNoteList } from "@/modules/hr/components/toast-note-list";
import { payFrequencyLabel } from "@/modules/hr/types/employee";
import type { AmendmentKind, PayrollRun } from "@/modules/hr/types/payroll";

interface DraftLine {
  employee_id: number | "";
  amount: string;
  note: string;
}

/** The three corrections, in the words HR uses for them — and what each does to the tax. */
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
 * THE LINES A PERIOD-WIDE CORRECTION CAN NAME.
 *
 * Ordered by what this is actually for. The attendance charges lead because they are the case that
 * is genuinely one decision for a whole payroll — a system that charged everybody late through an
 * outage, a suspension day priced wrong for the office. The rest are offered because the API
 * accepts them and HR is entitled to them, not because a single figure across a payroll is usually
 * the right answer for them; VARIES_BY_EMPLOYEE says so on the ones where it is not.
 *
 * The `__` entries are group headings — disabled, so they read as labels rather than choices.
 */
const CORRECTABLE_LINES: { value: string; label: string; disabled?: boolean }[] = [
  { value: "__charges", label: "Attendance charges", disabled: true },
  { value: "late", label: "Late" },
  { value: "undertime", label: "Undertime" },
  { value: "absent", label: "Absences" },
  { value: "half_day", label: "Half-Days" },
  { value: "suspended_unpaid", label: "Work Suspension" },
  { value: "leave_unpaid", label: "Leave Without Pay" },
  { value: "holiday_forfeited", label: "Holiday Forfeited" },
  { value: "early_out", label: "Early Out" },
  { value: "late_penalty", label: "Tardiness Penalty" },
  { value: "not_employed", label: "Outside Employment" },
  { value: "__pay", label: "Compensation", disabled: true },
  { value: "basic", label: "Basic Pay" },
  { value: "overtime", label: "Overtime" },
  { value: "night_diff", label: "Night Differential" },
  { value: "rest_day", label: "Rest Day" },
  { value: "holiday", label: "Holiday" },
  { value: "thirteenth_month", label: "13th Month Pay" },
  { value: "reimbursement", label: "Reimbursement" },
  { value: "__government", label: "Government", disabled: true },
  { value: "sss", label: "SSS" },
  { value: "philhealth", label: "PhilHealth" },
  { value: "pagibig", label: "Pag-IBIG" },
  { value: "withholding_tax", label: "Withholding Tax" },
];

/**
 * Lines whose right figure normally DIFFERS from one employee to the next.
 *
 * Not blocked — a period-wide correction on one of these is occasionally exactly right, and the
 * reason field and the audit trail record the decision either way. Said out loud because setting
 * every payslip in a payroll to one salary figure is the kind of mistake that looks like a
 * successful action.
 */
const VARIES_BY_EMPLOYEE = [
  "basic", "overtime", "night_diff", "rest_day", "holiday", "thirteenth_month",
  "reimbursement", "sss", "philhealth", "pagibig", "withholding_tax",
];

const lineLabel = (key: string) => CORRECTABLE_LINES.find((l) => l.value === key)?.label ?? key;

/** How a period reads in the picker. */
function runLabel(run: PayrollRun): string {
  const year = run.cycle_end.slice(0, 4);
  if (run.type === "thirteenth_month") return `13th month · ${year}`;
  if (run.type === "final_pay") return `Final pay · ${formatDate(run.cycle_end)}`;
  return `${formatDate(run.cycle_start)} – ${formatDate(run.cycle_end)} · ${payFrequencyLabel(run.frequency)}`;
}

/** Correct a period on its own payslips. */
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
          <DialogTitle>Correct a period&rsquo;s payslips</DialogTitle>
          <DialogDescription>
            The two changes that reach a whole period at once, on payslips it has already produced —
            no new payslip, no re-run. Correcting <em>one</em> person&rsquo;s payslip is still done
            on that payslip, where the wrong figure is in front of you.
          </DialogDescription>
        </DialogHeader>

        {/* The form is its own component so the draft lives and dies with the open dialog. */}
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
  const correctMut = useCorrectRunLine();

  /*
   * THE TWO PERIOD-WIDE JOBS, on one screen.
   *
   * They share everything that makes them period-wide — which period, who in it, why, and the date
   * it was issued — and differ only in the middle: a line the payslips never had, or a line they
   * already have and got wrong. Splitting them across two dialogs put the second one out of reach:
   * the only run-wide correction that ever shipped was waiving a charge to zero.
   */
  const [mode, setMode] = useState<"add" | "correct">("add");

  const [pickedRunId, setPickedRunId] = useState<number | null>(prefillRunId ?? null);
  const [issuedOn, setIssuedOn] = useState(today);
  const [reason, setReason] = useState(prefillReason ?? "");
  const [kind, setKind] = useState<AmendmentKind>("pay");
  const [lines, setLines] = useState<DraftLine[]>([
    { employee_id: prefillEmployeeId ?? "", amount: "", note: "" },
  ]);
  /*
   * EVERYONE AT ONE AMOUNT — the shape a company-wide correction actually has: a benefit share that
   * started mid-period, an allowance the whole payroll was owed. It is a MODE rather than a button
   * that fills the rows, because the figure is one decision, not N identical ones: typed once, it
   * cannot drift between employees, and there is a single field to change when it turns out wrong.
   */
  const [everyone, setEveryone] = useState(false);
  const [bulkAmount, setBulkAmount] = useState("");
  const [bulkNote, setBulkNote] = useState("");

  // --- correcting a line the payslips already have ---------------------------------------------
  const [targetKey, setTargetKey] = useState("late");
  // Blank is not zero: the API takes a null amount as "leave the figure, change the wording".
  const [correctAmount, setCorrectAmount] = useState("");
  const [correctLabel, setCorrectLabel] = useState("");

  /*
   * A locked run can never be recomputed, so a correction on it could never be re-applied — and an
   * incomplete one has no payslips to correct. Both are refused by the backend; leaving them out of
   * the picker is how HR finds that out before typing amounts.
   */
  const amendable = useMemo(
    () => runs.filter((r) => r.status === "completed" && !r.is_locked && r.type !== "adjustment"),
    [runs],
  );

  /*
   * DERIVED, not stored. The picker opens on the period it was given, but that period may not be
   * amendable — the run list arrives after this mounts, and a prefilled run can be locked. Falling
   * back to the newest amendable run keeps the form usable either way, without an effect writing
   * over a choice HR has already made.
   */
  const runId = amendable.some((r) => r.id === pickedRunId)
    ? pickedRunId
    : (amendable[0]?.id ?? null);

  const { data: detail } = usePayrollRun(runId ?? undefined);
  const selectedRun = amendable.find((r) => r.id === runId);
  const breakdown = detail?.breakdown ?? [];

  const takesMoney = kind !== "pay";
  const spec = KINDS[kind];

  /** WHAT WILL BE POSTED, whichever way it was entered. */
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

  /** The employees NAMED for a correction — everyone this period paid, or the rows chosen. */
  const correctIds = everyone
    ? breakdown.map((row) => row.employee_id)
    : [...new Set(lines.filter((l) => l.employee_id !== "").map((l) => Number(l.employee_id)))];

  /** WHOSE PAYSLIP THIS TOUCHES, whichever mode entered it. */
  const affectedIds = mode === "add" ? payloadLines.map((l) => l.employee_id) : correctIds;

  const parsedCorrection = correctAmount.trim() === "" ? null : Number(correctAmount);

  /**
   * The chosen employees whose payslip has already been paid out.
   *
   * Paying them leaves a balance owed; taking money off them leaves the company overpaid — two
   * different sentences, and the one that involves recovering wages is the one to be sure about.
   */
  const alreadyPaid = affectedIds
    .map((id) => breakdown.find((r) => r.employee_id === id))
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

  const busy = amendMut.isPending || correctMut.isPending;

  const canSubmit =
    !!runId &&
    reason.trim() !== "" &&
    !busy &&
    (mode === "add"
      ? payloadLines.length > 0 && overReversed.length === 0
      : // A correction that changes neither the figure nor the wording is not a correction — the
        // API refuses it, so the button does too.
        (parsedCorrection !== null || correctLabel.trim() !== "") &&
        (parsedCorrection === null || (!Number.isNaN(parsedCorrection) && parsedCorrection >= 0)) &&
        (everyone || correctIds.length > 0));

  function update(index: number, patch: Partial<DraftLine>) {
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  async function submit() {
    if (!runId) return;

    if (mode === "correct") {
      await submitCorrection(runId);

      return;
    }

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

  /** ONE LINE, SET TO ONE FIGURE, ACROSS THE PERIOD. */
  async function submitCorrection(id: number) {
    try {
      const { applied, skipped } = await correctMut.mutateAsync({
        runId: id,
        target_key: targetKey,
        amount: parsedCorrection,
        label: correctLabel.trim() || null,
        reason: reason.trim(),
        issued_on: issuedOn,
        // Absent means everyone. An empty array does not — see the API's own note on it.
        employee_ids: everyone ? null : correctIds,
      });

      const what = `${lineLabel(targetKey)} corrected on ${applied} payslip(s)`;

      // NOT A SUCCESS WHEN IT MISSED SOMEBODY. "Corrected 12 payslips" reads as "corrected
      // everyone" when it was 12 of 15, and the three it could not touch are the ones that
      // need a person.
      if (skipped.length > 0) {
        toast.warning(
          `${what} — ${skipped.length} could not be changed`,
          <ToastNoteList notes={skipped} />,
        );
      } else {
        toast.success(what);
      }

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
        {/*
         * WHICH OF THE TWO JOBS. A segmented control rather than a dropdown: there are exactly two,
         * and which one you are doing changes what the rest of the form means — that is not a
         * setting to go hunting for in a list.
         */}
        <div className="grid grid-cols-2 gap-1 rounded-lg border border-border bg-muted/40 p-1">
          {(["add", "correct"] as const).map((m) => (
            <button
              key={m}
              type="button"
              onClick={() => setMode(m)}
              aria-pressed={mode === m}
              className={
                "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
                (mode === m
                  ? "bg-background text-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground")
              }
            >
              {m === "add" ? "Add a line" : "Correct a line"}
            </button>
          ))}
        </div>

        <p className="text-caption text-muted-foreground">
          {mode === "add"
            ? "For money the payroll engine never priced: back pay, an allowance it missed, a deduction that should have been taken. Each person can be given a different amount."
            : "For a figure the payroll engine got wrong on a line the payslips already have — a charge to waive after an outage, a benefit share priced wrong for everybody. One figure, applied to every payslip named."}
        </p>

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
            {/*
             * When the correction is issued — NOT the period being fixed, which is the field
             * beside it. It prints on the payslip beside the amount.
             */}
            <DatePicker id="amend-issued" value={issuedOn} onChange={setIssuedOn} />
          </div>
        </div>

        {mode === "add" ? (
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
        ) : (
          <div className="space-y-3">
            <div className="space-y-1.5">
              <label htmlFor="amend-line" className="text-caption font-medium">
                Which line to correct
              </label>
              <SimpleSelect
                id="amend-line"
                value={targetKey}
                onValueChange={setTargetKey}
                options={CORRECTABLE_LINES}
              />
              <p className="text-caption text-muted-foreground">
                Every payslip named below will read exactly this figure for{" "}
                {lineLabel(targetKey)}. The figure the payroll engine computed stays on each
                payslip, struck through beside yours.
              </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
              <div className="space-y-1.5">
                <label htmlFor="amend-figure" className="text-caption font-medium">
                  Corrected amount
                </label>
                <Input
                  id="amend-figure"
                  type="number"
                  min="0"
                  step="0.01"
                  inputMode="decimal"
                  placeholder="0.00"
                  className="font-mono tabular-nums"
                  value={correctAmount}
                  onChange={(e) => setCorrectAmount(e.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <label htmlFor="amend-label" className="text-caption font-medium">
                  Label{" "}
                  <span className="text-muted-foreground">
                    (optional — what the line should be called)
                  </span>
                </label>
                <Input
                  id="amend-label"
                  maxLength={100}
                  placeholder={lineLabel(targetKey)}
                  value={correctLabel}
                  onChange={(e) => setCorrectLabel(e.target.value)}
                />
              </div>
            </div>

            {/*
             * ZERO IS THE COMMON ANSWER and blank is not zero — one waives the charge, the other
             * leaves the figure alone and changes only the wording. Two very different outcomes
             * from an empty box, so the box says which is which.
             */}
            {parsedCorrection === null && correctLabel.trim() !== "" && (
              <p className="text-caption text-muted-foreground">
                No amount entered, so this renames the line and leaves every figure as it is. Enter
                0 to waive the charge instead.
              </p>
            )}

            {/* Offered, not blocked — see VARIES_BY_EMPLOYEE. */}
            {parsedCorrection !== null && VARIES_BY_EMPLOYEE.includes(targetKey) && (
              <div className="rounded-card border border-warning/40 bg-warning/10 p-3">
                <p className="text-sm font-medium text-foreground">
                  {lineLabel(targetKey)} normally differs from one employee to the next
                </p>
                <p className="text-caption text-muted-foreground">
                  This sets every payslip named below to the same {formatPeso(parsedCorrection)}.
                  That is occasionally right — but if only some people are wrong, name them below
                  rather than applying it to everyone.
                </p>
              </div>
            )}
          </div>
        )}

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
            {mode === "correct"
              ? "Whose payslip to correct"
              : takesMoney
                ? "Who it comes off"
                : "Who is owed it"}
            {selectedRun && (
              <span className="font-normal text-muted-foreground">
                {" "}
                — the {breakdown.length || selectedRun.employee_count} employees this period paid
              </span>
            )}
          </p>

          {/*
           * The whole period at one figure, for the corrections that are company-wide by nature.
           * Beside the heading rather than at the foot of the rows: it decides what the rest of
           * this section even looks like.
           */}
          <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border px-3 py-2.5">
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0 rounded border-input accent-brand"
              checked={everyone}
              disabled={!detail || breakdown.length === 0}
              onChange={(e) => setEveryone(e.target.checked)}
            />
            <span className="text-sm text-foreground">
              {mode === "correct"
                ? "Correct this line on everyone in this period"
                : "Apply the same amount to everyone in this period"}
              <span className="block text-caption text-muted-foreground">
                {mode === "correct"
                  ? `All ${breakdown.length || selectedRun?.employee_count} employees — for a charge to waive or a figure priced wrong across the payroll. Untick to name only the people whose payslip is wrong. Anyone whose payslip has no such line is reported, not silently skipped.`
                  : `One figure for all ${breakdown.length || selectedRun?.employee_count} employees — for a deduction or an amount the whole payroll shares. Untick to set different amounts per employee.`}
              </span>
            </span>
          </label>

          {everyone && mode === "correct" ? null : everyone ? (
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
                    {/*
                     * ONLY WHEN ADDING. A correction is one figure across everyone named — the
                     * amount is above, entered once, so these rows are a list of people and
                     * nothing else.
                     */}
                    {mode === "add" && (
                      <>
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
                      </>
                    )}
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
                  {/*
                   * What this payslip currently says, so the amount is entered against the figure
                   * it is correcting rather than from memory.
                   */}
                  {row && (
                    <p className="pl-1 text-caption text-muted-foreground">
                      Net {formatPeso(row.net)}
                      {row.disbursed != null && ` · ${formatPeso(row.disbursed)} already paid`}
                      {row.disbursed != null &&
                        row.outstanding > 0 &&
                        ` · ${formatPeso(row.outstanding)} outstanding`}
                      {/*
                       * What the payslip cannot cover, said against the line that causes it rather
                       * than as a total afterwards.
                       */}
                      {mode === "add" && kind === "deduction" && Number(line.amount) > row.net && (
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

        {mode === "correct" && affectedIds.length > 0 && (
          <p className="text-body">
            <span className="text-muted-foreground">Correcting </span>
            <span className="font-semibold">{lineLabel(targetKey)}</span>
            <span className="text-muted-foreground">
              {parsedCorrection === null
                ? " — wording only, no figure changes"
                : ` to ${formatPeso(parsedCorrection)}`}{" "}
              on {everyone ? breakdown.length || 0 : affectedIds.length} payslip(s)
            </span>
          </p>
        )}

        {mode === "add" && payloadLines.length > 0 && (
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

        {/*
         * In bulk mode there are no rows to say this beside, and it is the case bulk mode makes
         * likely: one figure against a payroll where somebody had a thin period.
         */}
        {mode === "add" && everyone && shortfalls.length > 0 && (
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

        {/*
         * The one consequence that is not obvious from the form: a period finance has already
         * settled no longer matches what was transferred.
         */}
        {alreadyPaid.length > 0 && (
          <div className="rounded-card border border-warning/40 bg-warning/10 p-3">
            <p className="text-sm font-medium text-foreground">
              {alreadyPaid.length} of these payslip(s) have already been paid
            </p>
            <p className="text-caption text-muted-foreground">
              {mode === "correct"
                ? "What was transferred does not change. Each payslip will show the difference as a balance still to pay, or as an overpayment where the correction lowers it — and the run cannot be locked until what it still owes is settled."
                : takesMoney
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
          {busy ? "Applying…" : mode === "add" ? "Add the line" : "Apply correction"}
        </Button>
      </DialogFooter>
    </>
  );
}
