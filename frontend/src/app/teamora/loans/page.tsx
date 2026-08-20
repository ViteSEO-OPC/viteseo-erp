"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { HandCoins } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { StatusBadge } from "@/core/components/status-badge";
import { LoanScheduleDialog } from "@/modules/hr/components/loan-schedule-dialog";
import { formatPeso, formatDate } from "@/core/lib/format";
import { notify as toast } from "@/core/lib/notify";
import { PageHeader } from "@/modules/hr/components/page-header";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useMyLoans, useRequestLoan, useAuthorizeLoan } from "@/modules/hr/hooks/use-employees";
import { useMyBenefits } from "@/modules/hr/hooks/use-benefit-programs";
import type { EmployeeLoan } from "@/modules/hr/api/employees";

/** The employee's own loans. */
export default function MyLoansPage() {
  const { data: loans, isLoading } = useMyLoans();
  const { data: benefits } = useMyBenefits();
  const authorizeMut = useAuthorizeLoan();
  const [scheduleId, setScheduleId] = useState<number | null>(null);

  const [asking, setAsking] = useState(false);

  // Only the policies this employee is actually covered by — "My Benefits" already answers that
  // question per person, so there is no second eligibility list to keep in step.
  const policies = (benefits ?? []).filter((b) => b.kind === "loan_policy");

  const list = loans ?? [];
  const awaiting = list.filter((l) => l.needs_authorization);
  const owing = list.filter((l) => l.is_collectible && l.outstanding > 0);

  async function authorize(loan: EmployeeLoan) {
    try {
      await authorizeMut.mutateAsync(loan.id);
      toast.success("Authorized — repayments start on your next payslip");
    } catch {
      toast.error("Could not authorize this loan.");
    }
  }

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="My Loans"
        subtitle="What you owe, what comes off your pay, and what you have agreed to."
        actions={
          policies.length > 0 && (
            <Button onClick={() => setAsking((a) => !a)}>
              <HandCoins />
              {asking ? "Cancel" : "Request a loan"}
            </Button>
          )
        }
      />

      {asking && policies.length > 0 && (
        <RequestForm policies={policies} onDone={() => setAsking(false)} />
      )}

      {/* The consent gate, first and unmissable — a loan sitting here is deducting nothing. */}
      {awaiting.length > 0 && (
        <div className="mt-6 space-y-3">
          {awaiting.map((loan) => (
            <div
              key={loan.id}
              className="rounded-card border border-warning/40 bg-warning-subtle p-5"
            >
              <p className="text-sm font-bold text-foreground">
                {formatPeso(loan.principal)} is ready — your approval is needed
              </p>
              {/*
               * This is the Art. 113 consent, so it must state the TERMS, not just the
               * instalment. With interest the deduction is larger than `per_period_amount` and the
               * total repaid exceeds the principal; saying "X per payslip until the principal is
               * repaid" would be a material misstatement on the screen where consent is given.
               */}
              <p className="mt-1 text-sm text-foreground">
                By authorizing, you agree that{" "}
                <strong>{formatPeso(loan.per_period_amount)} of the loan</strong> will be deducted from
                each payslip until the {formatPeso(loan.principal)} is repaid, starting{" "}
                {formatDate(loan.start_date)}.
              </p>
              {loan.interest_rate > 0 && (
                <p className="mt-1 text-sm text-foreground">
                  <strong>
                    Plus interest at {loan.interest_rate}% per{" "}
                    {loan.interest_period === "monthly" ? "month" : "year"}
                  </strong>
                  , charged each payslip on what you still owe — so your actual deduction is a little
                  more than {formatPeso(loan.per_period_amount)}, and it gets smaller as the balance
                  falls.{" "}
                  <button
                    type="button"
                    onClick={() => setScheduleId(loan.id)}
                    className="font-medium text-brand underline"
                  >
                    See exactly what it costs
                  </button>
                  .
                </p>
              )}
              {loan.fees > 0 && (
                <p className="mt-1 text-sm text-foreground">
                  A one-off fee of <strong>{formatPeso(loan.fees)}</strong> is collected with your
                  first payslip.
                </p>
              )}
              <p className="mt-1 text-caption text-muted-foreground">
                Nothing is taken from your pay until you do. You can talk to HR first.
              </p>
              <Button
                className="mt-3"
                disabled={authorizeMut.isPending}
                onClick={() => authorize(loan)}
              >
                I authorize this deduction
              </Button>
            </div>
          ))}
        </div>
      )}

      {owing.length > 0 && (
        <div className="mt-6 rounded-card border border-border bg-card p-5">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
            Currently owing
          </p>
          <p className="mt-1 text-3xl font-bold text-foreground">
            {formatPeso(owing.reduce((s, l) => s + l.outstanding, 0))}
          </p>
          <p className="mt-1 text-caption text-muted-foreground">
            {formatPeso(owing.reduce((s, l) => s + l.per_period_amount, 0))} comes off your next
            payslip.
          </p>
        </div>
      )}

      <div className="mt-6">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : list.length === 0 ? (
          <div className="rounded-card border border-dashed border-border p-10 text-center">
            <p className="text-sm font-medium text-foreground">You have no loans.</p>
            <p className="mt-1 text-caption text-muted-foreground">
              {policies.length > 0
                ? "Use “Request a loan” to ask HR."
                : "There is no loan you are eligible for right now."}
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {list.map((loan) => (
              <LoanRow key={loan.id} loan={loan} />
            ))}
          </div>
        )}
      </div>

      {/* The schedule the Art. 113 consent above links to. */}
      <LoanScheduleDialog
        loanId={scheduleId}
        open={scheduleId !== null}
        onOpenChange={(o) => !o && setScheduleId(null)}
      />
    </div>
  );
}

function LoanRow({ loan }: { loan: EmployeeLoan }) {
  const pct = loan.principal > 0 ? Math.min(100, (loan.paid / loan.principal) * 100) : 0;

  return (
    <div className="rounded-card border border-border bg-card p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-bold text-foreground">
            {loan.policy_name ?? "Loan"}
            {loan.reference ? ` · ${loan.reference}` : ""}
          </p>
          <p className="text-caption text-muted-foreground">
            {formatPeso(loan.principal)} · {formatPeso(loan.per_period_amount)} per payslip · from{" "}
            {formatDate(loan.start_date)}
          </p>
        </div>
        <StatusBadge status={loan.status} />
      </div>

      {loan.is_collectible && (
        <div className="mt-4">
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div className="h-full rounded-full bg-brand" style={{ width: `${pct}%` }} />
          </div>
          <p className="mt-1.5 text-caption text-muted-foreground">
            {formatPeso(loan.paid)} repaid · {formatPeso(loan.outstanding)} left
          </p>
        </div>
      )}

      {loan.status === "requested" && (
        <p className="mt-3 text-caption text-muted-foreground">
          Waiting for HR to decide. Nothing is deducted.
        </p>
      )}
      {loan.status === "rejected" && loan.decision_note && (
        <p className="mt-3 text-caption text-muted-foreground">HR&apos;s note: {loan.decision_note}</p>
      )}
      {loan.authorized_at && (
        <p className="mt-3 text-caption text-muted-foreground">
          You authorized this deduction on {formatDate(loan.authorized_at)}.
        </p>
      )}
    </div>
  );
}

function RequestForm({
  policies,
  onDone,
}: {
  policies: { id: number; name: string }[];
  onDone: () => void;
}) {
  const requestMut = useRequestLoan();
  const [policyId, setPolicyId] = useState(String(policies[0]?.id ?? ""));
  const [principal, setPrincipal] = useState("");
  const [perPeriod, setPerPeriod] = useState("");
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState("");

  async function submit() {
    try {
      await requestMut.mutateAsync({
        benefit_program_id: Number(policyId),
        principal: Number(principal),
        per_period_amount: Number(perPeriod),
        start_date: startDate,
        notes: notes || null,
      });
      toast.success("Requested — HR will review it");
      onDone();
    } catch (err) {
      // The server checks the policy's maximum, its term and who it covers; those messages are
      // more useful than anything this form could guess at.
      const errors = err instanceof AxiosError ? err.response?.data?.errors : null;
      toast.error(errors ? String(Object.values(errors)[0]) : "Could not send the request.");
    }
  }

  return (
    <div className="mt-6 grid grid-cols-1 gap-3 rounded-card border border-border bg-muted/30 p-4 sm:grid-cols-4">
      <div className="space-y-1.5 sm:col-span-2">
        <Label htmlFor="lp">Loan</Label>
        <SimpleSelect
          id="lp"
          value={policyId}
          onValueChange={setPolicyId}
          options={policies.map((p) => ({ value: String(p.id), label: p.name }))}
        />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="lp-amt">How much</Label>
        <Input
          id="lp-amt"
          type="number"
          min={0}
          step="0.01"
          value={principal}
          onChange={(e) => setPrincipal(e.target.value)}
        />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="lp-per">Per payslip</Label>
        <Input
          id="lp-per"
          type="number"
          min={0}
          step="0.01"
          value={perPeriod}
          onChange={(e) => setPerPeriod(e.target.value)}
        />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="lp-start">Start</Label>
        <Input
          id="lp-start"
          type="date"
          value={startDate}
          onChange={(e) => setStartDate(e.target.value)}
        />
      </div>
      <div className="space-y-1.5 sm:col-span-3">
        <Label htmlFor="lp-note">Reason (optional)</Label>
        <Input id="lp-note" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>
      <div className="sm:col-span-4">
        <Button
          size="sm"
          disabled={requestMut.isPending || !principal || !perPeriod}
          onClick={submit}
        >
          {requestMut.isPending ? "Sending…" : "Send request"}
        </Button>
      </div>
    </div>
  );
}
