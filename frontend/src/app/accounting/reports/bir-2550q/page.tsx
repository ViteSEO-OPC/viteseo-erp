"use client";

import { useState } from "react";
import { Printer, Download } from "lucide-react";

import { formatPeso } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { useBir2550Q } from "@/modules/accounting/hooks/useReports";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const now = new Date();

export default function Bir2550QPage() {
  const [year, setYear] = useState(now.getFullYear());
  const [quarter, setQuarter] = useState(Math.floor(now.getMonth() / 3) + 1);

  const q = useBir2550Q(year, quarter);
  const years = Array.from({ length: 6 }, (_, i) => now.getFullYear() - i);

  const exportData = () => {
    const d = q.data!;
    const rows: (string | number)[][] = [
      ["Vatable sales (net)", d.sales.vatable],
      ["Zero-rated sales", d.sales.zero_rated],
      ["Exempt sales", d.sales.exempt],
      ["Output VAT", d.sales.output_vat],
      ["Vatable purchases (net)", d.purchases.vatable],
      ["Exempt purchases", d.purchases.exempt],
      ["Input VAT", d.purchases.input_vat],
      [d.is_payable ? "Net VAT payable" : "Excess input VAT (carry over)", d.net_vat_payable],
      ...d.months.map((m) => [`${m.label} — Output VAT`, m.output_vat] as [string, number]),
      ...d.months.map((m) => [`${m.label} — Input VAT`, m.input_vat] as [string, number]),
    ];
    return { title: `BIR 2550Q — ${d.period_label}`, headers: ["Line", "Amount"], rows };
  };
  const exportExcel = () => { if (!q.data) return; const e = exportData(); downloadExcel(`bir-2550q-${q.data.year}-Q${q.data.quarter}`, e.title, e.headers, e.rows); };
  const exportPdf = () => { if (!q.data) return; const e = exportData(); printTable(e.title, "Figures to transcribe into eBIRForms", e.headers, e.rows); };

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="BIR 2550Q — Quarterly VAT"
        subtitle="The figures to transcribe into eBIRForms. Not an official BIR filing."
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
        {q.data ? (
          <>
            <button type="button" onClick={exportExcel} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40">
              <Download className="size-4" /> Excel
            </button>
            <button type="button" onClick={exportPdf} className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40">
              <Printer className="size-4" /> PDF
            </button>
          </>
        ) : null}
      </div>

      {q.isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading…</p>
      ) : q.isError || !q.data ? (
        <p className="mt-6 text-sm text-muted-foreground">Couldn’t load this report.</p>
      ) : (
        <div className="mt-6 grid gap-6 lg:grid-cols-2">
          {/* Sales / Output */}
          <div className="rounded-card border border-border bg-card p-6">
            <SectionHeading
              eyebrow="Output"
              title={<>Sales &amp; Output VAT</>}
              meta={q.data.period_label}
            />
            <dl className="mt-4 space-y-2 text-sm">
              <Line label="Vatable sales (net)" value={q.data.sales.vatable} />
              <Line label="Zero-rated sales" value={q.data.sales.zero_rated} />
              <Line label="Exempt sales" value={q.data.sales.exempt} />
              <Line label="Output VAT (12%)" value={q.data.sales.output_vat} strong />
            </dl>
          </div>

          {/* Purchases / Input */}
          <div className="rounded-card border border-border bg-card p-6">
            <SectionHeading
              eyebrow="Input"
              title={<>Purchases &amp; Input VAT</>}
              meta={q.data.period_label}
            />
            <dl className="mt-4 space-y-2 text-sm">
              <Line label="Vatable purchases (net)" value={q.data.purchases.vatable} />
              <Line label="Exempt purchases" value={q.data.purchases.exempt} />
              <Line label="Input VAT (12%)" value={q.data.purchases.input_vat} strong />
            </dl>
          </div>

          {/* Net VAT */}
          <div className="rounded-card border border-border bg-card p-6 lg:col-span-2">
            <div className="flex items-center justify-between gap-4">
              <div>
                <SectionHeading
                  eyebrow="Net"
                  title={q.data.is_payable ? "VAT payable" : "Excess input VAT (carry over)"}
                />
                <p className="mt-2 text-caption text-muted-foreground">Output VAT − Input VAT</p>
              </div>
              <p className={`text-h1 font-bold tabular-nums ${q.data.is_payable ? "text-destructive" : "text-brand"}`}>
                {formatPeso(Math.abs(q.data.net_vat_payable))}
              </p>
            </div>

            {/* Monthly breakdown */}
            <div className="mt-5 overflow-x-auto">
              <table className="w-full min-w-[420px] text-sm">
                <thead>
                  <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
                    <th className="py-2 pr-3 text-left font-bold">Month</th>
                    <th className="py-2 pr-3 text-right font-bold">Output VAT</th>
                    <th className="py-2 text-right font-bold">Input VAT</th>
                  </tr>
                </thead>
                <tbody>
                  {q.data.months.map((m) => (
                    <tr key={m.month} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-foreground">{m.label}</td>
                      <td className="py-2 pr-3 text-right tabular-nums text-foreground">{formatPeso(m.output_vat)}</td>
                      <td className="py-2 text-right tabular-nums text-foreground">{formatPeso(m.input_vat)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function Line({ label, value, strong }: { label: string; value: number; strong?: boolean }) {
  return (
    <div className={`flex items-baseline justify-between gap-3 ${strong ? "border-t border-border pt-2 font-semibold" : ""}`}>
      <dt className={strong ? "text-foreground" : "text-muted-foreground"}>{label}</dt>
      <dd className="tabular-nums text-foreground">{formatPeso(value)}</dd>
    </div>
  );
}
