"use client";

import { useState } from "react";
import { Plus, Ban } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { AxiosError } from "axios";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { StatusBadge } from "@/core/components/status-badge";
import { formatPeso, formatDate } from "@/core/lib/format";
import { CancelLeaveDialog } from "@/modules/hr/components/cancel-leave-dialog";
import { ReopenAction } from "@/modules/hr/components/reopen-action";
import {
  useEmployeeLoans,
  useCreateEmployeeLoan,
  useCancelEmployeeLoan,
  useReopenEmployeeLoan,
  useDecideLoan,
} from "@/modules/hr/hooks/use-employees";
import type { EmployeeLoan } from "@/modules/hr/api/employees";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { LoanScheduleDialog } from "@/modules/hr/components/loan-schedule-dialog";

const TYPES = [
  { value: "loan", label: "Loan" },
  { value: "cash_advance", label: "Cash advance" },
  { value: "other", label: "Other" },
];

const TYPE_LABEL: Record<string, string> = {
  loan: "Loan",
  cash_advance: "Cash advance",
  other: "Other",
};

/**
 * Loans & cash advances repaid through payroll (Slice 11b).
 *
 * `outstanding` is DERIVED on the server from the payment ledger, never a stored figure — so
 * what's shown here always matches what payroll has actually collected, however many times a
 * cycle has been re-run.
 */
export function EmployeeLoans({ employeeId }: { employeeId: number }) {
  const { data: loans, isLoading } = useEmployeeLoans(employeeId);
  const createMut = useCreateEmployeeLoan(employeeId);
  const cancelMut = useCancelEmployeeLoan(employeeId);
  const reopenMut = useReopenEmployeeLoan(employeeId);
  // Slice B15 — HR decides on a request. Neither outcome starts a deduction.
  const decideMut = useDecideLoan(employeeId);

  const [adding, setAdding] = useState(false);
  const [scheduleId, setScheduleId] = useState<number | null>(null);
  const [cancelling, setCancelling] = useState<EmployeeLoan | null>(null);
  const [form, setForm] = useState({
    type: "loan",
    reference: "",
    principal: "",
    per_period_amount: "",
    start_date: new Date().toISOString().slice(0, 10),
    // FIN-032 — the terms. Blank/0 = interest-free, which is a real product, not a gap.
    interest_rate: "",
    interest_period: "annual",
    fees: "",
    priority: "",
  });

  async function submit() {
    try {
      await createMut.mutateAsync({
        type: form.type as EmployeeLoan["type"],
        reference: form.reference.trim() || null,
        principal: Number(form.principal),
        per_period_amount: Number(form.per_period_amount),
        start_date: form.start_date,
        // Omitted rather than sent as 0 when blank, so the server default applies.
        ...(form.interest_rate ? { interest_rate: Number(form.interest_rate) } : {}),
        ...(form.interest_rate
          ? { interest_period: form.interest_period as "annual" | "monthly" }
          : {}),
        ...(form.fees ? { fees: Number(form.fees) } : {}),
        ...(form.priority ? { priority: Number(form.priority) } : {}),
      });
      toast.success("Loan recorded");
      setAdding(false);
      setForm({
        ...form,
        reference: "",
        principal: "",
        per_period_amount: "",
        interest_rate: "",
        fees: "",
        priority: "",
      });
    } catch (err) {
      const msg =
        err instanceof AxiosError
          ? (err.response?.data?.errors?.per_period_amount?.[0] ??
            err.response?.data?.message ??
            "Could not record the loan.")
          : "Could not record the loan.";
      toast.error(msg);
    }
  }

  async function onCancel(reason: string) {
    if (!cancelling) return;
    try {
      await cancelMut.mutateAsync({ loanId: cancelling.id, reason });
      toast.success("Loan cancelled");
    } catch {
      toast.error("Could not cancel the loan.");
      throw new Error("cancel failed");
    }
  }

  const active = (loans ?? []).filter((l) => l.status === "active");
  const totalOutstanding = active.reduce((sum, l) => sum + l.outstanding, 0);

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p className="text-caption text-muted-foreground">
          Deducted automatically each pay period until the balance reaches zero. If a payslip
          can&apos;t cover the full instalment, only what it can afford is taken and the rest
          carries to the next period.
          {totalOutstanding > 0 && (
            <>
              {" "}
              <span className="font-semibold text-foreground">
                {formatPeso(totalOutstanding)} outstanding
              </span>{" "}
              across {active.length} active {active.length === 1 ? "loan" : "loans"}.
            </>
          )}
        </p>
        <Button size="sm" variant="outline" onClick={() => setAdding((v) => !v)}>
          <Plus />
          Record a loan
        </Button>
      </div>

      {adding && (
        <div className="mb-4 grid grid-cols-1 gap-3 rounded-card border border-border bg-muted/30 p-4 sm:grid-cols-5">
          <div className="space-y-1.5">
            <Label htmlFor="loan-type">Type</Label>
            <SimpleSelect
              value={form.type}
              onValueChange={(v) => setForm({ ...form, type: v })}
              options={TYPES}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-ref">Reference</Label>
            <Input
              id="loan-ref"
              placeholder="COOP-2026-01"
              value={form.reference}
              onChange={(e) => setForm({ ...form, reference: e.target.value })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-principal">Total amount</Label>
            <Input
              id="loan-principal"
              type="number"
              min={0}
              step="0.01"
              value={form.principal}
              onChange={(e) => setForm({ ...form, principal: e.target.value })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-per-period">Per period</Label>
            <Input
              id="loan-per-period"
              type="number"
              min={0}
              step="0.01"
              value={form.per_period_amount}
              onChange={(e) => setForm({ ...form, per_period_amount: e.target.value })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-start">Starts</Label>
            <Input
              id="loan-start"
              type="date"
              value={form.start_date}
              onChange={(e) => setForm({ ...form, start_date: e.target.value })}
            />
          </div>

          {/* FIN-032 — the terms. Diminishing balance: interest is charged each period on what is
              STILL OWED, so the rate typed here is the rate the employee actually pays. Blank = 0%,
              which is an interest-free advance. */}
          <div className="space-y-1.5">
            <Label htmlFor="loan-rate">Interest rate (%)</Label>
            <Input
              id="loan-rate"
              type="number"
              min={0}
              max={100}
              step="0.01"
              placeholder="0 = none"
              value={form.interest_rate}
              onChange={(e) => setForm({ ...form, interest_rate: e.target.value })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-rate-period">Rate is per</Label>
            <SimpleSelect
              value={form.interest_period}
              onValueChange={(v) => setForm({ ...form, interest_period: v })}
              options={[
                { value: "annual", label: "Year" },
                { value: "monthly", label: "Month" },
              ]}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-fees">One-off fee</Label>
            <Input
              id="loan-fees"
              type="number"
              min={0}
              step="0.01"
              placeholder="0"
              value={form.fees}
              onChange={(e) => setForm({ ...form, fees: e.target.value })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="loan-priority">Collect first (1 = highest)</Label>
            <Input
              id="loan-priority"
              type="number"
              min={1}
              max={9999}
              step="1"
              placeholder="100"
              value={form.priority}
              onChange={(e) => setForm({ ...form, priority: e.target.value })}
            />
          </div>

          {Number(form.interest_rate) > 0 && (
            <div className="rounded-lg border border-border bg-muted/30 p-3 sm:col-span-5">
              <p className="text-xs text-foreground">
                <strong>Interest is charged on the balance still owed</strong> and falls as the loan is
                repaid — the same way SSS and Pag-IBIG salary loans work. The &ldquo;per period&rdquo;
                figure above is the <strong>principal</strong> instalment; interest and any fee are
                added on top, so the employee&apos;s actual deduction is higher. The full schedule is
                shown on the loan once it is saved.
              </p>
            </div>
          )}

          <div className="sm:col-span-5">
            <Button
              size="sm"
              disabled={createMut.isPending || !form.principal || !form.per_period_amount}
              onClick={submit}
            >
              {createMut.isPending ? "Saving…" : "Save loan"}
            </Button>
          </div>
        </div>
      )}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading loans…</p>
      ) : (loans ?? []).length === 0 ? (
        <p className="rounded-card border border-dashed border-border py-8 text-center text-sm text-muted-foreground">
          No loans or cash advances on record.
        </p>
      ) : (
        <div className="overflow-x-auto rounded-card border border-border">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-muted/60 text-label font-bold uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="px-4 py-2.5 text-left">Type</th>
                <th className="px-4 py-2.5 text-left">Started</th>
                <th className="px-4 py-2.5 text-right">Total</th>
                <th className="px-4 py-2.5 text-right">Per period</th>
                {/* FIN-032 — the terms belong on the row: an instalment without its rate reads as
                    the whole cost of the loan, which is what made the old field misleading. */}
                <th className="px-4 py-2.5 text-right">Rate</th>
                <th className="px-4 py-2.5 text-right">Repaid</th>
                <th className="px-4 py-2.5 text-right">Outstanding</th>
                <th className="px-4 py-2.5 text-left">Status</th>
                <th className="px-4 py-2.5" />
              </tr>
            </thead>
            <tbody>
              {(loans ?? []).map((l) => (
                <tr key={l.id} className="border-t border-border">
                  <td className="px-4 py-2.5">
                    <span className="font-medium text-foreground">{TYPE_LABEL[l.type] ?? l.type}</span>
                    {l.reference && (
                      <span className="block text-xs text-muted-foreground">{l.reference}</span>
                    )}
                  </td>
                  <td className="px-4 py-2.5">{formatDate(l.start_date)}</td>
                  <td className="px-4 py-2.5 text-right">{formatPeso(l.principal)}</td>
                  <td className="px-4 py-2.5 text-right">{formatPeso(l.per_period_amount)}</td>
                  <td className="px-4 py-2.5 text-right">
                    {l.interest_rate > 0 ? (
                      <>
                        <span className="text-foreground">
                          {l.interest_rate}%/{l.interest_period === "monthly" ? "mo" : "yr"}
                        </span>
                        <button
                          type="button"
                          onClick={() => setScheduleId(l.id)}
                          className="block text-xs font-medium text-brand underline"
                        >
                          Schedule
                        </button>
                      </>
                    ) : (
                      <span className="text-muted-foreground">0%</span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-right text-success">
                    {formatPeso(l.paid)}
                    {l.paid_interest > 0 && (
                      <span className="block text-xs text-muted-foreground">
                        incl. {formatPeso(l.paid_interest)} interest
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-right font-semibold">{formatPeso(l.outstanding)}</td>
                  <td className="px-4 py-2.5">
                    <StatusBadge status={l.status} />
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    {/* Slice B15 — approving is the COMPANY's decision. The deduction still waits
                        on the employee's own authorization (Labor Code Art. 113), which is why
                        there is no "activate" here for HR to press. */}
                    {l.status === "requested" && (
                      <span className="inline-flex gap-1">
                        <Button
                          size="xs"
                          disabled={decideMut.isPending}
                          onClick={async () => {
                            try {
                              await decideMut.mutateAsync({ loanId: l.id, approve: true });
                              toast.success("Approved — waiting on the employee to authorize it");
                            } catch {
                              toast.error("Could not approve this request.");
                            }
                          }}
                        >
                          Approve
                        </Button>
                        <Button
                          size="xs"
                          variant="outline"
                          disabled={decideMut.isPending}
                          onClick={async () => {
                            const reason = window.prompt("Why is this being rejected?")?.trim();
                            if (!reason) return;
                            try {
                              await decideMut.mutateAsync({ loanId: l.id, approve: false, reason });
                              toast.success("Rejected");
                            } catch {
                              toast.error("Could not reject this request.");
                            }
                          }}
                        >
                          Reject
                        </Button>
                      </span>
                    )}
                    {l.status === "pending_authorization" && (
                      <span className="text-caption text-muted-foreground">
                        Waiting on {"the employee"}&apos;s authorization
                      </span>
                    )}
                    {l.status === "active" && (
                      <Button size="xs" variant="ghost" onClick={() => setCancelling(l)}>
                        <Ban className="size-3.5" />
                        <span className="sr-only">Cancel loan</span>
                      </Button>
                    )}
                    {l.status === "cancelled" && (
                      <ReopenAction
                        size="xs"
                        label="Undo cancel"
                        title="Resume collecting this loan?"
                        confirmLabel="Reactivate"
                        successMessage="Loan reactivated"
                        subject={`This ${TYPE_LABEL[l.type] ?? l.type}`}
                        description={`This ${(TYPE_LABEL[l.type] ?? l.type).toLowerCase()} becomes active again.`}
                        consequence={`Payroll deducts ${formatPeso(l.per_period_amount)} per period until the ${formatPeso(l.outstanding)} balance is settled.`}
                        onReopen={(reason) => reopenMut.mutateAsync({ loanId: l.id, reason })}
                      />
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <CancelLeaveDialog
        open={!!cancelling}
        onOpenChange={(o) => !o && setCancelling(null)}
        title="Cancel Loan"
        subject={
          cancelling
            ? `${TYPE_LABEL[cancelling.type]} · ${formatPeso(cancelling.outstanding)} outstanding`
            : ""
        }
        onConfirm={onCancel}
      />

      {/* FIN-032 — what the employee is consenting to under Art. 113: the schedule, not just the rate. */}
      <LoanScheduleDialog
        loanId={scheduleId}
        open={scheduleId !== null}
        onOpenChange={(o) => !o && setScheduleId(null)}
      />
    </div>
  );
}
