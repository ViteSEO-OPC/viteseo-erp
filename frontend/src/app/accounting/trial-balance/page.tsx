"use client";

import { Download, Printer } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatPeso } from "@/core/lib/format";
import { downloadExcel, printTable } from "@/core/lib/export";
import { PageHeader } from "@/core/components/page-header";
import { useTrialBalance } from "@/modules/accounting/hooks/useTrialBalance";
import type { TrialBalanceRow } from "@/modules/accounting/types";

const btnCls =
  "inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40";

export default function TrialBalancePage() {
  const { data, isLoading, isError } = useTrialBalance();
  const rows = data?.rows ?? [];

  const headers = ["Code", "Account", "Debit", "Credit"];
  const exportRows = () => rows.map((r) => [r.code, r.name, r.debit, r.credit] as (string | number)[]);

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="Trial Balance"
        subtitle="Debit & credit totals per account (posted entries)."
        actions={
          rows.length > 0 ? (
            <div className="flex items-center gap-2">
              <button type="button" onClick={() => downloadExcel("trial-balance", "Trial Balance", headers, exportRows())} className={btnCls}>
                <Download className="size-4" /> Excel
              </button>
              <button type="button" onClick={() => printTable("Trial Balance", "Posted entries", headers, exportRows())} className={btnCls}>
                <Printer className="size-4" /> PDF
              </button>
            </div>
          ) : undefined
        }
      />

      <div className="mt-6">
        {isError ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            Couldn’t load the trial balance. Please try again.
          </p>
        ) : (
          <div className="overflow-x-auto rounded-card border border-border bg-card">
            <table className="w-full min-w-[520px] text-sm">
              <thead>
                <tr className="border-b border-border">
                  <th className="w-28 px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">
                    Code
                  </th>
                  <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">
                    Account
                  </th>
                  <th className="px-4 py-2.5 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                    Debit
                  </th>
                  <th className="px-4 py-2.5 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                    Credit
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-center text-muted-foreground">
                      {isLoading ? "Loading…" : "No posted entries yet."}
                    </td>
                  </tr>
                ) : (
                  rows.map((r: TrialBalanceRow) => (
                    <tr key={r.account_id} className="border-b border-border last:border-0">
                      <td className="w-28 px-4 py-2 font-mono text-muted-foreground">{r.code}</td>
                      <td className="px-4 py-2 font-medium text-foreground">{r.name}</td>
                      <td className="px-4 py-2 text-right tabular-nums">
                        {r.debit ? formatPeso(r.debit) : "—"}
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums">
                        {r.credit ? formatPeso(r.credit) : "—"}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
              {data ? (
                <tfoot>
                  <tr className="border-t-2 border-border font-semibold">
                    <td className="px-4 py-2.5" colSpan={2}>
                      <span className={cn(data.balanced ? "text-brand" : "text-destructive")}>
                        {data.balanced ? "In balance ✓" : "Out of balance"}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums">
                      {formatPeso(data.total_debit)}
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums">
                      {formatPeso(data.total_credit)}
                    </td>
                  </tr>
                </tfoot>
              ) : null}
            </table>
          </div>
        )}
      </div>
    </>
  );
}
