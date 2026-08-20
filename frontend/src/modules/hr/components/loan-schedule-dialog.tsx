"use client";

import { AlertTriangle } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiClient } from "@/core/api-client";

const peso = new Intl.NumberFormat("en-PH", {
  style: "currency",
  currency: "PHP",
  minimumFractionDigits: 2,
});

interface ScheduleRow {
  period: number;
  opening_balance: number;
  interest: number;
  fee: number;
  principal: number;
  total: number;
  closing_balance: number;
}

interface Schedule {
  data: {
    rows: ScheduleRow[];
    total_interest: number;
    periods: number;
    truncated: boolean;
  };
  meta: {
    periods_per_year: number;
    pay_frequency: string;
    interest_rate: number;
    interest_period: string;
    period_rate: number;
  };
}

/** A loan's projected repayment schedule. */
export function LoanScheduleDialog({
  loanId,
  open,
  onOpenChange,
}: {
  loanId: number | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ["hr", "loan-schedule", loanId] as const,
    queryFn: async (): Promise<Schedule> => {
      const res = await apiClient.get(`/hr/loans/${loanId}/schedule`);
      return res.data;
    },
    enabled: open && loanId !== null,
  });

  const rows = data?.data.rows ?? [];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-[720px]">
        <DialogHeader>
          <DialogTitle>Repayment schedule</DialogTitle>
          <DialogDescription>
            What this loan costs, period by period. A projection — it assumes every instalment is
            collected in full.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <p className="text-sm text-muted-foreground">Working out the schedule…</p>
        ) : isError || !data ? (
          <p className="text-sm text-destructive">Could not load the schedule.</p>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            This loan has no per-period amount set, so there is no schedule to project.
          </p>
        ) : (
          <div className="space-y-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="rounded-lg border border-border p-3">
                <p className="text-caption text-muted-foreground">Interest rate</p>
                <p className="text-base font-bold text-foreground">
                  {data.meta.interest_rate}% per {data.meta.interest_period === "monthly" ? "month" : "year"}
                </p>
                <p className="text-xs text-muted-foreground">
                  {data.meta.period_rate}% per payday ({data.meta.periods_per_year} a year)
                </p>
              </div>
              <div className="rounded-lg border border-border p-3">
                <p className="text-caption text-muted-foreground">Total interest</p>
                <p className="text-base font-bold text-foreground">
                  {peso.format(data.data.total_interest)}
                </p>
                <p className="text-xs text-muted-foreground">over the life of the loan</p>
              </div>
              <div className="rounded-lg border border-border p-3">
                <p className="text-caption text-muted-foreground">Paydays</p>
                <p className="text-base font-bold text-foreground">{data.data.periods}</p>
                <p className="text-xs text-muted-foreground">to repay in full</p>
              </div>
            </div>

            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full min-w-[620px] text-sm">
                <thead>
                  <tr className="border-b border-border bg-muted/60 text-label font-bold uppercase tracking-wide text-muted-foreground">
                    <th className="px-3 py-2 text-left">#</th>
                    <th className="px-3 py-2 text-right">Balance</th>
                    <th className="px-3 py-2 text-right">Interest</th>
                    <th className="px-3 py-2 text-right">Fee</th>
                    <th className="px-3 py-2 text-right">Principal</th>
                    <th className="px-3 py-2 text-right">Deduction</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.period} className="border-b border-border last:border-0">
                      <td className="px-3 py-1.5 text-muted-foreground">{r.period}</td>
                      <td className="px-3 py-1.5 text-right text-muted-foreground">
                        {peso.format(r.opening_balance)}
                      </td>
                      <td className="px-3 py-1.5 text-right text-foreground">
                        {r.interest > 0 ? peso.format(r.interest) : "—"}
                      </td>
                      <td className="px-3 py-1.5 text-right text-foreground">
                        {r.fee > 0 ? peso.format(r.fee) : "—"}
                      </td>
                      <td className="px-3 py-1.5 text-right text-foreground">
                        {peso.format(r.principal)}
                      </td>
                      <td className="px-3 py-1.5 text-right font-medium text-foreground">
                        {peso.format(r.total)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {data.data.truncated && (
              <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
                <p className="text-xs text-foreground">
                  This schedule is longer than {data.data.periods} paydays and has been cut short. At
                  this instalment the loan takes an unusually long time to repay — worth checking the
                  per-period amount.
                </p>
              </div>
            )}

            {data.meta.interest_rate > 0 && (
              <p className="text-caption text-muted-foreground">
                Notice the interest column falling while the principal stays flat: interest is charged
                on what is <strong>still owed</strong>, so it costs less the further in you get. Paying
                ahead of schedule reduces it further. A short payday collects the interest first and
                less principal, which makes the loan run slightly longer than shown here.
              </p>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
