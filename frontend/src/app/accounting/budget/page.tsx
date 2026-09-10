"use client";

import { useState } from "react";

import { cn } from "@/lib/utils";
import { formatPeso, formatPesoAccounting, parseAmount } from "@/core/lib/format";
import { PageHeader } from "@/core/components/page-header";
import { useBudget, useSetBudget } from "@/modules/accounting/hooks/useBudget";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import type { BudgetRow } from "@/modules/accounting/types";
import { manilaMonth } from "@/modules/accounting/lib/dates";

export default function BudgetPage() {
  // Default to the current month; the picker lets you review any month.
  const [period, setPeriod] = useState(() => manilaMonth());
  const { data, isLoading, isError } = useBudget(period);
  const rows = data?.rows ?? [];

  return (
    <>
      <PageHeader
        eyebrow="Planning"
        title="Budget vs Actual"
        subtitle="Your real monthly spending — including employer contributions — against budget."
      />

      <div className="mt-6 flex items-center gap-3">
        <label htmlFor="period" className="text-sm text-muted-foreground">
          Month
        </label>
        <input
          id="period"
          type="month"
          value={period}
          onChange={(e) => setPeriod(e.target.value)}
          className="h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      <div className="mt-6 overflow-hidden rounded-card border border-border bg-card">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[520px] text-sm">
            <thead>
              <tr className="border-b border-border">
                <th className="px-4 py-3 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">
                  Account
                </th>
                <th className="px-4 py-3 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                  Actual
                </th>
                <th className="px-4 py-3 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                  Budget
                </th>
                <th className="px-4 py-3 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                  Variance
                </th>
              </tr>
            </thead>
            <tbody>
              {isError ? (
                <tr>
                  <td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">
                    Couldn’t load the budget. Please try again.
                  </td>
                </tr>
              ) : isLoading ? (
                <tr>
                  <td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">
                    Loading…
                  </td>
                </tr>
              ) : rows.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">
                    No expense accounts.
                  </td>
                </tr>
              ) : (
                rows.map((row) => (
                  <BudgetRowItem key={`${period}-${row.account_id}`} row={row} period={period} />
                ))
              )}
            </tbody>
            {data && rows.length > 0 ? (
              <tfoot>
                <tr className="border-t border-border font-semibold">
                  <td className="px-4 py-3 text-foreground">Total (true monthly cost)</td>
                  <td className="px-4 py-3 text-right tabular-nums text-foreground">
                    {formatPeso(data.total_actual)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-foreground">
                    {formatPeso(data.total_budget)}
                  </td>
                  <td
                    className={cn(
                      "px-4 py-3 text-right tabular-nums",
                      data.variance < 0 ? "text-destructive" : "text-brand",
                    )}
                  >
                    {formatPesoAccounting(data.variance)}
                  </td>
                </tr>
              </tfoot>
            ) : null}
          </table>
        </div>
      </div>

      <p className="mt-3 text-caption text-muted-foreground">
        Actual = posted journal entries dated in this month. Set a budget to see the variance.
      </p>
    </>
  );
}

function BudgetRowItem({ row, period }: { row: BudgetRow; period: string }) {
  const setBudget = useSetBudget();
  const { canManageBudget } = useAccountingPerms();
  const [value, setValue] = useState(row.budget ? String(row.budget) : "");

  const commit = () => {
    // A budget is not negative, and the API refuses one with a 422 this screen
    // does not surface -- so do not send it. (The `min="0"` that used to sit on
    // the input never guarded anything either; it only marked the field invalid.)
    const amount = parseAmount(value || "0");
    if (!Number.isNaN(amount) && amount >= 0 && amount !== row.budget) {
      setBudget.mutate({ period, account_id: row.account_id, amount });
    }
  };

  return (
    <tr className="border-b border-border last:border-0">
      <td className="px-4 py-2.5 text-foreground">
        <span className="font-mono text-muted-foreground">{row.code}</span> {row.name}
      </td>
      <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(row.actual)}</td>
      <td className="px-4 py-2.5 text-right">
        <input
          type="text"
          inputMode="decimal"
          value={value}
          disabled={!canManageBudget}
          onChange={(e) => setValue(e.target.value)}
          onBlur={commit}
          placeholder="0.00"
          className="h-8 w-32 rounded-lg border border-input bg-transparent px-2 text-right text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:opacity-60"
        />
      </td>
      <td
        className={cn(
          "px-4 py-2.5 text-right tabular-nums",
          row.variance < 0 ? "text-destructive" : "text-muted-foreground",
        )}
      >
        {formatPesoAccounting(row.variance)}
      </td>
    </tr>
  );
}
