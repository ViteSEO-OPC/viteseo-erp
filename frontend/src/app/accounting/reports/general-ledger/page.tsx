"use client";

import { useState } from "react";

import { Download, Printer } from "lucide-react";

import { PageHeader } from "@/core/components/page-header";
import { formatPeso, formatDate } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import { useGeneralLedger } from "@/modules/accounting/hooks/useReports";

const btnCls =
  "inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function GeneralLedgerPage() {
  const { data: accounts } = useAccounts();
  const [accountId, setAccountId] = useState<number | null>(null);
  const { data, isLoading, isError } = useGeneralLedger(accountId);

  const acct = (accounts ?? []).find((a) => a.id === accountId);
  const title = acct ? `General Ledger — ${acct.code} ${acct.name}` : "General Ledger";
  const headers = ["Date", "Reference", "Details", "Debit", "Credit", "Balance"];
  const exportRows = (): (string | number)[][] =>
    (data?.lines ?? []).map((l) => [formatDate(l.date), l.reference ?? "", l.memo ?? "", l.debit || "", l.credit || "", l.balance]);

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="General Ledger"
        subtitle="Every posted transaction for an account, with a running balance."
        actions={
          <div className="flex items-center gap-2">
            <select
              value={accountId ?? ""}
              onChange={(e) => setAccountId(e.target.value ? Number(e.target.value) : null)}
              className={inputCls}
            >
              <option value="">Select an account…</option>
              {(accounts ?? []).map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} · {a.name}
                </option>
              ))}
            </select>
            {data && data.lines.length > 0 ? (
              <>
                <button type="button" onClick={() => downloadExcel(`general-ledger-${acct?.code ?? ""}`, title, headers, exportRows())} className={btnCls}>
                  <Download className="size-4" /> Excel
                </button>
                <button type="button" onClick={() => printTable(title, "Posted transactions", headers, exportRows())} className={btnCls}>
                  <Printer className="size-4" /> PDF
                </button>
              </>
            ) : null}
          </div>
        }
      />

      <div className="mt-6">
        {accountId == null ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            Pick an account above to see its ledger.
          </p>
        ) : (
          <div className="overflow-hidden rounded-card border border-border bg-card">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[640px] text-sm">
                <thead>
                  <tr className="border-b border-border">
                    <th className="px-4 py-3 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Date</th>
                    <th className="px-4 py-3 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Details</th>
                    <th className="px-4 py-3 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">Debit</th>
                    <th className="px-4 py-3 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">Credit</th>
                    <th className="px-4 py-3 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">Loading…</td></tr>
                  ) : (data?.lines.length ?? 0) === 0 ? (
                    <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{isError ? "Couldn’t load the ledger. Please try again." : "No transactions for this account."}</td></tr>
                  ) : (
                    data?.lines.map((l, i) => (
                      <tr key={i} className="border-b border-border last:border-0">
                        <td className="px-4 py-2.5 text-muted-foreground">{formatDate(l.date)}</td>
                        <td className="px-4 py-2.5 text-foreground">
                          {l.reference ? <span className="font-mono text-muted-foreground">{l.reference} </span> : null}
                          {l.memo}
                        </td>
                        <td className="px-4 py-2.5 text-right tabular-nums">{l.debit ? formatPeso(l.debit) : "—"}</td>
                        <td className="px-4 py-2.5 text-right tabular-nums">{l.credit ? formatPeso(l.credit) : "—"}</td>
                        <td className="px-4 py-2.5 text-right font-medium tabular-nums">{formatPeso(l.balance)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
                {data && data.lines.length > 0 ? (
                  <tfoot>
                    <tr className="border-t border-border font-semibold">
                      <td className="px-4 py-3" colSpan={4}>Closing balance</td>
                      <td className="px-4 py-3 text-right tabular-nums">{formatPeso(data.closing_balance)}</td>
                    </tr>
                  </tfoot>
                ) : null}
              </table>
            </div>
          </div>
        )}
      </div>
    </>
  );
}
