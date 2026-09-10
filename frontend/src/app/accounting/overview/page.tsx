"use client";

import { useState } from "react";

import { PageHeader } from "@/core/components/page-header";
import { RowList, Row, SectionHeading } from "@/core/components/editorial";
import { formatPeso, formatPesoAccounting } from "@/core/lib/format";
import { useProfitAndLoss } from "@/modules/accounting/hooks/useStatements";
import { manilaMonth } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function OverviewPage() {
  const [period, setPeriod] = useState(() => manilaMonth());
  const { data, isLoading, isError } = useProfitAndLoss(period);

  const income = data?.total_income ?? 0;
  const expense = data?.total_expense ?? 0;
  const net = data?.net_income ?? 0;

  // Employer contributions — flagged by the backend (no code string-matching).
  const employer = data?.employer_contributions ?? 0;

  const topExpenses = [...(data?.expense ?? [])].sort((a, b) => b.amount - a.amount);
  const maxExpense = topExpenses[0]?.amount ?? 0;

  return (
    <>
      <PageHeader
        eyebrow="Accounting"
        title="Overview"
        subtitle="Your money at a glance — income, spending, and where it goes."
        actions={
          <input
            type="month"
            value={period}
            onChange={(e) => setPeriod(e.target.value)}
            className={inputCls}
          />
        }
      />

      {isError ? (
        <p className="mt-6 rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
          Couldn’t load the overview. Please try again.
        </p>
      ) : (
        <>
          <div className="mt-6 grid gap-4 sm:grid-cols-3">
            <StatCard label="Income" value={income} loading={isLoading} />
            <StatCard label="Expenses (total cost)" value={expense} loading={isLoading} />
            <StatCard label="Net income" value={net} loading={isLoading} tone={net < 0 ? "bad" : "good"} accounting />
          </div>

          {employer > 0 ? (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-card border border-brand-subtle bg-brand-subtle/40 px-5 py-4">
              <span className="text-sm font-medium text-foreground">
                Of which, employer contributions (SSS / PhilHealth / Pag-IBIG)
              </span>
              <span className="text-h3 font-bold tabular-nums text-dark-teal">
                {formatPeso(employer)}
              </span>
            </div>
          ) : null}

          <section className="mt-8">
            <SectionHeading
              eyebrow="Expenses"
              title="Where the money goes"
              meta={
                topExpenses.length > 0
                  ? `${topExpenses.length} account${topExpenses.length === 1 ? "" : "s"} · ${formatPeso(expense)}`
                  : undefined
              }
            />
            <div className="mt-4">
              {isLoading ? (
                <p className="text-sm text-muted-foreground">Loading…</p>
              ) : topExpenses.length === 0 ? (
                <p className="text-sm text-muted-foreground">No spending recorded this month.</p>
              ) : (
                <RowList>
                  {topExpenses.map((e, i) => (
                    <Row key={e.code} index={i}>
                      <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="text-foreground">{e.name}</span>
                        <span className="tabular-nums text-muted-foreground">
                          {formatPeso(e.amount)}
                        </span>
                      </div>
                      <div className="mt-1.5 h-1.5 overflow-hidden bg-muted">
                        <div
                          className="h-full bg-brand"
                          style={{ width: `${maxExpense > 0 ? (e.amount / maxExpense) * 100 : 0}%` }}
                        />
                      </div>
                    </Row>
                  ))}
                </RowList>
              )}
            </div>
          </section>
        </>
      )}
    </>
  );
}

function StatCard({
  label,
  value,
  loading,
  tone,
  accounting,
}: {
  label: string;
  value: number;
  loading: boolean;
  tone?: "good" | "bad";
  accounting?: boolean;
}) {
  return (
    <div className="rounded-card border border-border bg-card p-5">
      <p className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p
        className={
          "mt-1 text-h2 font-bold tabular-nums " +
          (tone === "bad" ? "text-destructive" : tone === "good" ? "text-brand" : "text-foreground")
        }
      >
        {loading ? "…" : accounting ? formatPesoAccounting(value) : formatPeso(value)}
      </p>
    </div>
  );
}
