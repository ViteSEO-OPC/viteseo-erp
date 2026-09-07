"use client";

import { useState } from "react";
import { Printer, Download } from "lucide-react";

import { formatPeso, formatDate } from "@/core/lib/format";
import { downloadExcel } from "@/core/lib/export";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { useContacts } from "@/modules/accounting/hooks/useContacts";
import { useStatementOfAccount } from "@/modules/accounting/hooks/useReports";
import { manilaToday } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const AGING_LABELS: { key: keyof AgingBuckets; label: string }[] = [
  { key: "current", label: "Current" },
  { key: "d1_30", label: "1–30 days" },
  { key: "d31_60", label: "31–60 days" },
  { key: "d61_90", label: "61–90 days" },
  { key: "over_90", label: "90+ days" },
];

interface AgingBuckets {
  current: number;
  d1_30: number;
  d31_60: number;
  d61_90: number;
  over_90: number;
}

export default function StatementOfAccountPage() {
  const [asOf, setAsOf] = useState(() => manilaToday());
  const [contactId, setContactId] = useState<number | null>(null);

  const { data: customers } = useContacts("customer");
  const soa = useStatementOfAccount(contactId, asOf);

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="Statement of Account"
        subtitle="A customer’s invoices, payments, and outstanding balance — ready to send."
      />

      {/* Controls */}
      <div className="mt-6 flex flex-wrap items-end gap-4 print:hidden">
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">Customer</span>
          <select
            value={contactId ?? ""}
            onChange={(e) => setContactId(e.target.value ? Number(e.target.value) : null)}
            className={`${inputCls} min-w-56`}
          >
            <option value="">Select a customer…</option>
            {(customers ?? []).map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        </label>
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">As of</span>
          <input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className={inputCls} />
        </label>
        {soa.data ? (
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() =>
                downloadExcel(
                  `statement-${soa.data!.contact.name.toLowerCase().replace(/\s+/g, "-")}`,
                  `Statement of Account — ${soa.data!.contact.name} (as of ${soa.data!.as_of})`,
                  ["Invoice", "Issue date", "Due date", "Description", "Amount", "Paid", "Balance"],
                  soa.data!.lines.map((l) => [l.invoice_no, l.issue_date ?? "", l.due_date ?? "", l.description ?? "", l.amount, l.amount_paid, l.balance_due]),
                )
              }
              className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40"
            >
              <Download className="size-4" /> Excel
            </button>
            <button
              type="button"
              onClick={() => window.print()}
              className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40"
            >
              <Printer className="size-4" /> PDF
            </button>
          </div>
        ) : null}
      </div>

      {/* Statement */}
      {contactId == null ? (
        <p className="mt-6 rounded-card border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
          Pick a customer to view their statement.
        </p>
      ) : soa.isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading…</p>
      ) : soa.isError || !soa.data ? (
        <p className="mt-6 text-sm text-muted-foreground">Couldn’t load this statement.</p>
      ) : (
        <div className="mt-6 rounded-card border border-border bg-card p-6">
          {/* Header */}
          <div className="border-b border-rule pb-4">
            <SectionHeading
              eyebrow="Customer"
              title={soa.data.contact.name}
              meta={
                <div className="text-right">
                  <p className="text-muted-foreground">Statement as of</p>
                  <p className="font-semibold text-foreground">{formatDate(soa.data.as_of)}</p>
                </div>
              }
            />
            {soa.data.contact.email ? (
              <p className="mt-2 text-sm text-muted-foreground">{soa.data.contact.email}</p>
            ) : null}
          </div>

          {/* Lines */}
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
                  <th className="py-2 pr-3 text-left font-bold">Date</th>
                  <th className="py-2 pr-3 text-left font-bold">Reference</th>
                  <th className="py-2 pr-3 text-left font-bold">Description</th>
                  <th className="py-2 pr-3 text-right font-bold">Amount</th>
                  <th className="py-2 pr-3 text-right font-bold">Paid</th>
                  <th className="py-2 text-right font-bold">Balance</th>
                </tr>
              </thead>
              <tbody>
                {soa.data.lines.length === 0 ? (
                  <tr><td colSpan={6} className="py-6 text-center text-muted-foreground">No invoices for this customer.</td></tr>
                ) : (
                  soa.data.lines.map((l) => (
                    <tr key={l.invoice_no} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-muted-foreground">{formatDate(l.issue_date)}</td>
                      <td className="py-2 pr-3 font-mono text-foreground">{l.invoice_no}</td>
                      <td className="py-2 pr-3 text-foreground">
                        {l.description ?? "—"}
                        {l.days_overdue > 0 ? (
                          <span className="ml-2 rounded-full bg-destructive/10 px-1.5 py-0.5 text-caption font-semibold text-destructive">
                            {l.days_overdue}d overdue
                          </span>
                        ) : null}
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums text-foreground">{formatPeso(l.amount)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">{formatPeso(l.amount_paid)}</td>
                      <td className="py-2 text-right tabular-nums font-medium text-foreground">{formatPeso(l.balance_due)}</td>
                    </tr>
                  ))
                )}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-border font-semibold">
                  <td colSpan={3} className="py-2.5 text-right">Totals</td>
                  <td className="py-2.5 pr-3 text-right tabular-nums">{formatPeso(soa.data.totals.billed)}</td>
                  <td className="py-2.5 pr-3 text-right tabular-nums">{formatPeso(soa.data.totals.paid)}</td>
                  <td className="py-2.5 text-right tabular-nums">{formatPeso(soa.data.totals.outstanding)}</td>
                </tr>
              </tfoot>
            </table>
          </div>

          {/* Amount due + aging */}
          <div className="mt-6 flex flex-wrap items-center justify-between gap-4">
            <div className="grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-5">
              {AGING_LABELS.map((b) => (
                <div key={b.key}>
                  <p className="text-caption uppercase tracking-wide text-muted-foreground">{b.label}</p>
                  <p className="tabular-nums text-foreground">{formatPeso(soa.data!.aging[b.key])}</p>
                </div>
              ))}
            </div>
            <div className="rounded-lg bg-brand/10 px-4 py-3 text-right">
              <p className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">Amount due</p>
              <p className="text-h3 font-bold tabular-nums text-brand">{formatPeso(soa.data.totals.outstanding)}</p>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
