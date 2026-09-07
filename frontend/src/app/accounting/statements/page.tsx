"use client";

import { useState } from "react";
import { Download, Printer } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatPeso, formatPesoAccounting } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import { PageHeader } from "@/core/components/page-header";
import { Eyebrow, SectionHeading } from "@/core/components/editorial";
import { useBalanceSheet, useProfitAndLoss } from "@/modules/accounting/hooks/useStatements";
import type { StatementLine } from "@/modules/accounting/types";
import { manilaToday, manilaMonth } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";
const iconBtn =
  "inline-flex size-9 items-center justify-center rounded-lg border border-border text-muted-foreground hover:bg-muted/40 hover:text-foreground";

const section = (label: string, lines: StatementLine[], total: number): (string | number)[][] => [
  [label.toUpperCase(), ""],
  ...lines.map((l) => [l.code ? `${l.code} ${l.name}` : l.name, l.amount] as (string | number)[]),
  [`Total ${label.toLowerCase()}`, total],
  ["", ""],
];

export default function StatementsPage() {
  const [period, setPeriod] = useState(() => manilaMonth());
  const [asOf, setAsOf] = useState(() => manilaToday());

  const pl = useProfitAndLoss(period);
  const bs = useBalanceSheet(asOf);

  const plRows = (): (string | number)[][] => {
    const d = pl.data!;
    return [
      ...section("Income", d.income, d.total_income),
      ...section("Expenses", d.expense, d.total_expense),
      ["NET INCOME", d.net_income],
    ];
  };
  const bsRows = (): (string | number)[][] => {
    const d = bs.data!;
    return [
      ...section("Assets", d.assets, d.total_assets),
      ...section("Liabilities", d.liabilities, d.total_liabilities),
      ...section("Equity", [...d.equity, { code: "", name: "Net income (to date)", amount: d.net_income }], d.total_equity),
    ];
  };

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="Financial Statements"
        subtitle="Profit & Loss and Balance Sheet, straight from your posted entries."
      />

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        {/* Profit & Loss */}
        <div className="rounded-card border border-border bg-card p-6">
          <SectionHeading
            eyebrow="Performance"
            title={<>Profit &amp; Loss</>}
            meta={
              <div className="flex items-center gap-2">
                {pl.data ? (
                  <>
                    <button type="button" title="Excel" onClick={() => downloadExcel(`profit-and-loss-${period}`, `Profit & Loss — ${period}`, ["Line", "Amount"], plRows())} className={iconBtn}>
                      <Download className="size-4" />
                    </button>
                    <button type="button" title="PDF" onClick={() => printTable(`Profit & Loss — ${period}`, null, ["Line", "Amount"], plRows())} className={iconBtn}>
                      <Printer className="size-4" />
                    </button>
                  </>
                ) : null}
                <input
                  type="month"
                  value={period}
                  onChange={(e) => setPeriod(e.target.value)}
                  className={inputCls}
                />
              </div>
            }
          />

          {pl.isLoading ? (
            <p className="mt-6 text-sm text-muted-foreground">Loading…</p>
          ) : pl.isError || !pl.data ? (
            <p className="mt-6 text-sm text-muted-foreground">Couldn’t load this statement.</p>
          ) : (
            <div className="mt-5 space-y-5">
              <Section title="Income" lines={pl.data.income} total={pl.data.total_income} />
              <Section title="Expenses" lines={pl.data.expense} total={pl.data.total_expense} />
              <TotalRow
                label="Net income"
                amount={pl.data.net_income}
                tone={pl.data.net_income < 0 ? "bad" : "good"}
              />
            </div>
          )}
        </div>

        {/* Balance Sheet */}
        <div className="rounded-card border border-border bg-card p-6">
          <SectionHeading
            eyebrow="Position"
            title="Balance Sheet"
            meta={
              <div className="flex items-center gap-2">
                {bs.data ? (
                  <>
                    <button type="button" title="Excel" onClick={() => downloadExcel(`balance-sheet-${asOf}`, `Balance Sheet — as of ${asOf}`, ["Line", "Amount"], bsRows())} className={iconBtn}>
                      <Download className="size-4" />
                    </button>
                    <button type="button" title="PDF" onClick={() => printTable(`Balance Sheet — as of ${asOf}`, null, ["Line", "Amount"], bsRows())} className={iconBtn}>
                      <Printer className="size-4" />
                    </button>
                  </>
                ) : null}
                <input
                  type="date"
                  value={asOf}
                  onChange={(e) => setAsOf(e.target.value)}
                  className={inputCls}
                />
              </div>
            }
          />

          {bs.isLoading ? (
            <p className="mt-6 text-sm text-muted-foreground">Loading…</p>
          ) : bs.isError || !bs.data ? (
            <p className="mt-6 text-sm text-muted-foreground">Couldn’t load this statement.</p>
          ) : (
            <div className="mt-5 space-y-5">
              <Section title="Assets" lines={bs.data.assets} total={bs.data.total_assets} />
              <Section
                title="Liabilities"
                lines={bs.data.liabilities}
                total={bs.data.total_liabilities}
              />
              <Section
                title="Equity"
                lines={[
                  ...bs.data.equity,
                  { code: "", name: "Net income (to date)", amount: bs.data.net_income },
                ]}
                total={bs.data.total_equity}
              />
              <div className="space-y-2 border-t-2 border-border pt-3">
                <div className="flex items-baseline justify-between gap-3 text-sm font-semibold">
                  <span>Total assets</span>
                  <span className="tabular-nums">{formatPeso(bs.data.total_assets)}</span>
                </div>
                <div className="flex items-baseline justify-between gap-3 text-sm font-semibold">
                  <span>Total liabilities + equity</span>
                  <span className="tabular-nums">
                    {formatPeso(bs.data.total_liabilities + bs.data.total_equity)}
                  </span>
                </div>
                <TotalRow
                  label={bs.data.balanced ? "Balanced ✓ (A = L + E)" : "Out of balance"}
                  amount={bs.data.total_assets}
                  tone={bs.data.balanced ? "good" : "bad"}
                />
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}

function Section({
  title,
  lines,
  total,
}: {
  title: string;
  lines: StatementLine[];
  total: number;
}) {
  return (
    <div>
      <Eyebrow>{title}</Eyebrow>
      <div className="mt-2 space-y-1.5">
        {lines.length === 0 ? (
          <p className="text-sm text-muted-foreground">None</p>
        ) : (
          lines.map((line, i) => (
            <div key={`${line.code}-${i}`} className="flex items-baseline justify-between gap-3 text-sm">
              <span className="text-foreground">
                {line.code ? (
                  <span className="font-mono text-muted-foreground">{line.code} </span>
                ) : null}
                {line.name}
              </span>
              <span className="tabular-nums text-foreground">{formatPeso(line.amount)}</span>
            </div>
          ))
        )}
        <div className="flex items-baseline justify-between gap-3 border-t border-border pt-1.5 text-sm font-semibold">
          <span>Total {title.toLowerCase()}</span>
          <span className="tabular-nums">{formatPeso(total)}</span>
        </div>
      </div>
    </div>
  );
}

function TotalRow({
  label,
  amount,
  tone,
}: {
  label: string;
  amount: number;
  tone: "good" | "bad";
}) {
  return (
    <div className="flex items-baseline justify-between gap-3 rounded-lg bg-muted/40 px-3 py-2.5 text-sm font-bold">
      <span className={cn(tone === "bad" ? "text-destructive" : "text-brand")}>{label}</span>
      <span className="tabular-nums text-foreground">{formatPesoAccounting(amount)}</span>
    </div>
  );
}
