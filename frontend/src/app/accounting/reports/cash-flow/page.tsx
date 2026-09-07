"use client";

import { useState } from "react";
import { Download, Printer } from "lucide-react";

import { PageHeader } from "@/core/components/page-header";
import { formatPeso, formatPesoAccounting, formatDate } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import { useCashFlow } from "@/modules/accounting/hooks/useReports";
import { manilaMonth } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";
const btnCls =
  "inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40";

export default function CashFlowPage() {
  const [period, setPeriod] = useState(() => manilaMonth());
  const { data, isLoading, isError } = useCashFlow(period);
  const net = data?.net ?? 0;

  const exportRows = (): (string | number)[][] => {
    if (!data) return [];
    const rows: (string | number)[][] = [["", "", "Opening balance", "", "", data.opening_balance]];
    for (const l of data.lines) {
      rows.push([
        formatDate(l.date),
        l.reference ?? "",
        l.description ?? "",
        l.inflow || "",
        l.outflow || "",
        l.balance,
      ]);
    }
    rows.push(["", "", "Closing balance", data.inflow, data.outflow, data.closing_balance]);
    return rows;
  };
  const headers = ["Date", "Reference", "Description", "Money in", "Money out", "Balance"];
  const title = `Cash Flow — ${period}`;

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="Cash Flow"
        subtitle="Money in vs money out of Cash for the month, with every transaction."
        actions={
          <div className="flex items-center gap-2">
            <input type="month" value={period} onChange={(e) => setPeriod(e.target.value)} className={inputCls} />
            {data ? (
              <>
                <button type="button" onClick={() => downloadExcel(`cash-flow-${period}`, title, headers, exportRows())} className={btnCls}>
                  <Download className="size-4" /> Excel
                </button>
                <button type="button" onClick={() => printTable(title, "Cash account movements", headers, exportRows())} className={btnCls}>
                  <Printer className="size-4" /> PDF
                </button>
              </>
            ) : null}
          </div>
        }
      />

      <div className="mt-6 grid gap-4 sm:grid-cols-4">
        <Card label="Opening balance" value={data?.opening_balance ?? 0} loading={isLoading} />
        <Card label="Money in" value={data?.inflow ?? 0} loading={isLoading} tone="good" />
        <Card label="Money out" value={data?.outflow ?? 0} loading={isLoading} />
        <Card label="Closing balance" value={data?.closing_balance ?? 0} loading={isLoading} tone={net < 0 ? "bad" : "good"} accounting />
      </div>

      {/* Transaction detail — the data the summary was missing */}
      <div className="mt-6 overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full min-w-[760px] text-sm">
          <thead>
            <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-2.5 text-left font-bold">Date</th>
              <th className="px-4 py-2.5 text-left font-bold">Reference</th>
              <th className="px-4 py-2.5 text-left font-bold">Description</th>
              <th className="px-4 py-2.5 text-right font-bold">Money in</th>
              <th className="px-4 py-2.5 text-right font-bold">Money out</th>
              <th className="px-4 py-2.5 text-right font-bold">Balance</th>
            </tr>
          </thead>
          <tbody>
            <tr className="border-b border-border bg-muted/30">
              <td className="px-4 py-2 text-muted-foreground" colSpan={5}>Opening balance</td>
              <td className="px-4 py-2 text-right font-medium tabular-nums">{formatPeso(data?.opening_balance ?? 0)}</td>
            </tr>
            {isLoading ? (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">Loading…</td></tr>
            ) : (data?.lines ?? []).length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">{isError ? "Couldn’t load the cash flow. Please try again." : "No cash movements this month."}</td></tr>
            ) : (
              (data?.lines ?? []).map((l, i) => (
                <tr key={i} className="border-b border-border last:border-0">
                  <td className="px-4 py-2 text-muted-foreground">{formatDate(l.date)}</td>
                  <td className="px-4 py-2 font-mono text-muted-foreground">{l.reference ?? "—"}</td>
                  <td className="px-4 py-2 text-foreground">{l.description ?? "—"}</td>
                  <td className="px-4 py-2 text-right tabular-nums text-brand">{l.inflow ? formatPeso(l.inflow) : ""}</td>
                  <td className="px-4 py-2 text-right tabular-nums text-destructive">{l.outflow ? formatPeso(l.outflow) : ""}</td>
                  <td className="px-4 py-2 text-right tabular-nums text-foreground">{formatPeso(l.balance)}</td>
                </tr>
              ))
            )}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-border font-semibold">
              <td className="px-4 py-2.5" colSpan={3}>Closing balance</td>
              <td className="px-4 py-2.5 text-right tabular-nums text-brand">{formatPeso(data?.inflow ?? 0)}</td>
              <td className="px-4 py-2.5 text-right tabular-nums text-destructive">{formatPeso(data?.outflow ?? 0)}</td>
              <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(data?.closing_balance ?? 0)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <p className="mt-3 text-caption text-muted-foreground">
        From posted entries touching the Cash account this month. Net cash flow: {formatPesoAccounting(net)}.
      </p>
    </>
  );
}

function Card({
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
      <p className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <p
        className={
          "mt-1 text-h3 font-bold tabular-nums " +
          (tone === "bad" ? "text-destructive" : tone === "good" ? "text-brand" : "text-foreground")
        }
      >
        {loading ? "…" : accounting ? formatPesoAccounting(value) : formatPeso(value)}
      </p>
    </div>
  );
}
