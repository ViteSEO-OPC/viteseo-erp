"use client";

import { useState } from "react";
import { Printer, Download } from "lucide-react";

import { formatPeso } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { useSlspSales, useSlspPurchases, type Slsp } from "@/modules/accounting/hooks/useReports";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const now = new Date();

export default function SlspPage() {
  const [year, setYear] = useState(now.getFullYear());
  const [quarter, setQuarter] = useState(Math.floor(now.getMonth() / 3) + 1);

  const sales = useSlspSales(year, quarter);
  const purchases = useSlspPurchases(year, quarter);
  const years = Array.from({ length: 6 }, (_, i) => now.getFullYear() - i);

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="BIR SLSP — Summary Lists"
        subtitle="Per-customer sales & per-supplier purchases with TIN, for the quarter. The alphalist data for eBIRForms."
      />

      <div className="mt-6 flex flex-wrap items-end gap-4 print:hidden">
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">Year</span>
          <select value={year} onChange={(e) => setYear(Number(e.target.value))} className={inputCls}>
            {years.map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </label>
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">Quarter</span>
          <select value={quarter} onChange={(e) => setQuarter(Number(e.target.value))} className={inputCls}>
            {[1, 2, 3, 4].map((n) => <option key={n} value={n}>Q{n}</option>)}
          </select>
        </label>
        <button type="button" onClick={() => window.print()} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40">
          <Printer className="size-4" /> Print
        </button>
      </div>

      <SlspTable title="Summary List of Sales (customers)" query={sales} csvName={`slsp-sales-${year}-Q${quarter}.csv`} />
      <SlspTable title="Summary List of Purchases (suppliers)" query={purchases} csvName={`slsp-purchases-${year}-Q${quarter}.csv`} />
    </>
  );
}

function SlspTable({
  title,
  query,
  csvName,
}: {
  title: string;
  query: { data?: Slsp; isLoading: boolean; isError: boolean };
  csvName: string;
}) {
  const headers = ["Name", "TIN", "Vatable", "Zero-rated", "Exempt", "VAT", "Gross"];
  const rows = (): (string | number)[][] =>
    (query.data?.rows ?? []).map((r) => [r.name, r.tin ?? "", r.vatable, r.zero_rated, r.exempt, r.vat, r.gross]);
  const base = csvName.replace(/\.csv$/, "");

  return (
    <div className="mt-8">
      <SectionHeading
        eyebrow="Alphalist"
        title={title}
        meta={
          query.data && query.data.rows.length > 0 ? (
            <div className="flex items-center gap-2 print:hidden">
              <button type="button" onClick={() => downloadExcel(base, title, headers, rows())} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-2.5 text-caption font-semibold text-foreground hover:bg-muted/40">
                <Download className="size-3.5" /> Excel
              </button>
              <button type="button" onClick={() => printTable(title, null, headers, rows())} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-2.5 text-caption font-semibold text-foreground hover:bg-muted/40">
                <Printer className="size-3.5" /> PDF
              </button>
            </div>
          ) : null
        }
      />
      {query.isLoading ? (
        <p className="mt-3 text-sm text-muted-foreground">Loading…</p>
      ) : query.isError || !query.data ? (
        <p className="mt-3 text-sm text-muted-foreground">Couldn’t load this list.</p>
      ) : query.data.rows.length === 0 ? (
        <p className="mt-3 rounded-card border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
          No entries for this quarter.
        </p>
      ) : (
        <div className="mt-3 overflow-x-auto rounded-card border border-border bg-card">
          <table className="w-full min-w-[860px] text-sm">
            <thead>
              <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
                <th className="px-4 py-2.5 text-left font-bold">Name</th>
                <th className="px-4 py-2.5 text-left font-bold">TIN</th>
                <th className="px-4 py-2.5 text-right font-bold">Vatable</th>
                <th className="px-4 py-2.5 text-right font-bold">Zero-rated</th>
                <th className="px-4 py-2.5 text-right font-bold">Exempt</th>
                <th className="px-4 py-2.5 text-right font-bold">VAT</th>
                <th className="px-4 py-2.5 text-right font-bold">Gross</th>
              </tr>
            </thead>
            <tbody>
              {query.data.rows.map((r) => (
                <tr key={r.contact_id} className="border-b border-border last:border-0">
                  <td className="px-4 py-2.5 font-medium text-foreground">
                    {r.name}
                    {!r.tin ? (
                      <span className="ml-2 rounded-full bg-warning/15 px-1.5 py-0.5 text-caption font-semibold text-warning">No TIN</span>
                    ) : null}
                  </td>
                  <td className="px-4 py-2.5 font-mono text-muted-foreground">{r.tin ?? "—"}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{formatPeso(r.vatable)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{formatPeso(r.zero_rated)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{formatPeso(r.exempt)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{formatPeso(r.vat)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums font-medium text-foreground">{formatPeso(r.gross)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-border font-semibold">
                <td colSpan={2} className="px-4 py-2.5 text-right">Totals</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(query.data.totals.vatable)}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(query.data.totals.zero_rated)}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(query.data.totals.exempt)}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(query.data.totals.vat)}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{formatPeso(query.data.totals.gross)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </div>
  );
}
