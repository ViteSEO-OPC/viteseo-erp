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
import {
  useEmployeeLoans,
  useCreateEmployeeLoan,
  useCancelEmployeeLoan,
} from "@/modules/hr/hooks/use-employees";
import type { EmployeeLoan } from "@/modules/hr/api/employees";
import { SimpleSelect } from "@/modules/hr/ui/select";

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

  const [adding, setAdding] = useState(false);
  const [cancelling, setCancelling] = useState<EmployeeLoan | null>(null);
  const [form, setForm] = useState({
    type: "loan",
    reference: "",
    principal: "",
    per_period_amount: "",
    start_date: new Date().toISOString().slice(0, 10),
  });

  async function submit() {
    try {
      await createMut.mutateAsync({
        type: form.type as EmployeeLoan["type"],
        reference: form.reference.trim() || null,
        principal: Number(form.principal),
        per_period_amount: Number(form.per_period_amount),
        start_date: form.start_date,
      });
      toast.success("Loan recorded");
      setAdding(false);
      setForm({ ...form, reference: "", principal: "", per_period_amount: "" });
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
                  <td className="px-4 py-2.5 text-right text-success">{formatPeso(l.paid)}</td>
                  <td className="px-4 py-2.5 text-right font-semibold">{formatPeso(l.outstanding)}</td>
                  <td className="px-4 py-2.5">
                    <StatusBadge status={l.status} />
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    {l.status === "active" && (
                      <Button size="xs" variant="ghost" onClick={() => setCancelling(l)}>
                        <Ban className="size-3.5" />
                        <span className="sr-only">Cancel loan</span>
                      </Button>
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
    </div>
  );
}
