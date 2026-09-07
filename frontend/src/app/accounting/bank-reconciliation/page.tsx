"use client";

import { useMemo, useState } from "react";
import { CheckCircle2 } from "lucide-react";

import { formatDate, formatPeso, parseAmount } from "@/core/lib/format";
import { notify } from "@/core/lib/notify";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import {
  useReconWorksheet,
  useReconHistory,
  useCompleteReconciliation,
} from "@/modules/accounting/hooks/useBankReconciliation";
import { manilaToday } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function BankReconciliationPage() {
  const { data: accounts } = useAccounts();
  const bankAccounts = useMemo(
    // Not every asset: Accounts Receivable, Input VAT and Inventory are assets
    // and none of them has a bank statement. The client marks its own banks on
    // the account itself (Chart of Accounts), because a business banks in more
    // than one place and this cannot guess their names.
    () => (accounts ?? []).filter((a) => a.is_bank && a.is_active),
    [accounts],
  );

  const [accountId, setAccountId] = useState<number | null>(null);
  const [asOf, setAsOf] = useState(() => manilaToday());
  const [statementBalance, setStatementBalance] = useState("");
  const [ticked, setTicked] = useState<Set<number>>(new Set());

  const ws = useReconWorksheet(accountId, asOf);
  const history = useReconHistory(accountId);
  const complete = useCompleteReconciliation();

  const toggle = (id: number) =>
    setTicked((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  // Live math: cleared balance = beginning + sum(ticked debit − credit).
  const beginning = ws.data?.beginning_balance ?? 0;
  const clearedDelta = useMemo(() => {
    if (!ws.data) return 0;
    return ws.data.lines
      .filter((l) => ticked.has(l.id))
      .reduce((sum, l) => sum + l.debit - l.credit, 0);
  }, [ws.data, ticked]);
  const clearedBalance = Math.round((beginning + clearedDelta) * 100) / 100;
  const stmt = parseAmount(statementBalance);
  const hasStmt = statementBalance.trim() !== "" && !Number.isNaN(stmt);
  const difference = hasStmt ? Math.round((stmt - clearedBalance) * 100) / 100 : null;
  const balanced = difference !== null && Math.abs(difference) < 0.005;

  const finish = () => {
    if (!accountId || !balanced) return;
    complete.mutate(
      {
        account_id: accountId,
        statement_date: asOf,
        statement_balance: stmt,
        cleared_line_ids: [...ticked],
      },
      {
        onSuccess: () => {
          setTicked(new Set());
          setStatementBalance("");
        },
        onError: () => notify.error("Couldn’t finish — the cleared balance must match the statement."),
      },
    );
  };

  return (
    <>
      <PageHeader
        eyebrow="Banking"
        title="Bank Reconciliation"
        subtitle="Tick the transactions that appear on your bank statement until it balances."
      />

      {/* Controls */}
      <div className="mt-6 flex flex-wrap items-end gap-4">
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">Bank / cash account</span>
          <select
            value={accountId ?? ""}
            onChange={(e) => {
              setAccountId(e.target.value ? Number(e.target.value) : null);
              setTicked(new Set());
            }}
            className={`${inputCls} min-w-56`}
          >
            <option value="">Select…</option>
            {bankAccounts.map((a) => (
              <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
            ))}
          </select>
        </label>
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">Statement date</span>
          <input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className={inputCls} />
        </label>
        <label className="grid gap-1.5">
          <span className="text-sm font-medium">Statement ending balance</span>
          <input
            type="text" inputMode="decimal"
            value={statementBalance}
            onChange={(e) => setStatementBalance(e.target.value)}
            placeholder="0.00"
            className={`${inputCls} w-48`}
          />
        </label>
      </div>

      {accountId == null ? (
        <p className="mt-6 rounded-card border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
          Pick a bank or cash account to start reconciling.
        </p>
      ) : ws.isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading…</p>
      ) : !ws.data ? (
        <p className="mt-6 text-sm text-muted-foreground">Couldn’t load the worksheet.</p>
      ) : (
        <>
          {/* Summary */}
          <div className="mt-6 grid gap-3 sm:grid-cols-4">
            <Stat label="Beginning balance" value={formatPeso(beginning)} />
            <Stat label="Cleared balance" value={formatPeso(clearedBalance)} />
            <Stat label="Statement balance" value={hasStmt ? formatPeso(stmt) : "—"} />
            <Stat
              label="Difference"
              value={difference === null ? "—" : formatPeso(difference)}
              tone={difference === null ? undefined : balanced ? "good" : "bad"}
            />
          </div>

          {/* Outstanding lines */}
          <div className="mt-4 overflow-x-auto rounded-card border border-border bg-card">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
                  <th className="px-4 py-2.5 text-left font-bold">Cleared</th>
                  <th className="px-4 py-2.5 text-left font-bold">Date</th>
                  <th className="px-4 py-2.5 text-left font-bold">Reference</th>
                  <th className="px-4 py-2.5 text-left font-bold">Memo</th>
                  <th className="px-4 py-2.5 text-right font-bold">Deposit</th>
                  <th className="px-4 py-2.5 text-right font-bold">Payment</th>
                </tr>
              </thead>
              <tbody>
                {ws.data.lines.length === 0 ? (
                  <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">Nothing outstanding — this account is fully reconciled up to this date.</td></tr>
                ) : (
                  ws.data.lines.map((l) => (
                    <tr
                      key={l.id}
                      onClick={() => toggle(l.id)}
                      className={`cursor-pointer border-b border-border last:border-0 ${ticked.has(l.id) ? "bg-brand/5" : "hover:bg-muted/30"}`}
                    >
                      <td className="px-4 py-2.5">
                        <input type="checkbox" checked={ticked.has(l.id)} readOnly className="size-4 accent-brand" />
                      </td>
                      <td className="px-4 py-2.5 text-muted-foreground">{formatDate(l.entry_date)}</td>
                      <td className="px-4 py-2.5 font-mono text-muted-foreground">{l.reference ?? "—"}</td>
                      <td className="px-4 py-2.5 text-foreground">{l.memo ?? "—"}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{l.debit > 0 ? formatPeso(l.debit) : ""}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{l.credit > 0 ? formatPeso(l.credit) : ""}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between gap-4">
            <p className="text-sm text-muted-foreground">
              {balanced ? (
                <span className="inline-flex items-center gap-1.5 font-semibold text-brand">
                  <CheckCircle2 className="size-4" /> Balanced — ready to finish.
                </span>
              ) : difference !== null ? (
                `Off by ${formatPeso(Math.abs(difference))} — keep ticking until the difference is ₱0.00.`
              ) : (
                "Enter the statement balance and tick cleared transactions."
              )}
            </p>
            <button
              type="button"
              disabled={!balanced || complete.isPending}
              onClick={finish}
              className="inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {complete.isPending ? "Finishing…" : "Finish reconciliation"}
            </button>
          </div>

          {/* History */}
          {(history.data ?? []).length > 0 ? (
            <div className="mt-10">
              <SectionHeading
                eyebrow="Closed"
                title="History"
                meta={`${(history.data ?? []).length} reconciliation${(history.data ?? []).length === 1 ? "" : "s"}`}
              />
              <div className="mt-3 overflow-x-auto rounded-card border border-border bg-card">
                <table className="w-full min-w-[420px] text-sm">
                  <thead>
                    <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2.5 text-left font-bold">Statement date</th>
                      <th className="px-4 py-2.5 text-right font-bold">Statement balance</th>
                      <th className="px-4 py-2.5 text-right font-bold">Cleared balance</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(history.data ?? []).map((h) => (
                      <tr key={h.id} className="border-b border-border last:border-0">
                        <td className="px-4 py-2.5 text-foreground">{formatDate(h.statement_date)}</td>
                        <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{formatPeso(h.statement_balance)}</td>
                        <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{formatPeso(h.cleared_balance)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: "good" | "bad" }) {
  return (
    <div className="rounded-card border border-border bg-card p-4">
      <p className="text-caption uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-1 text-h3 font-bold tabular-nums ${tone === "good" ? "text-brand" : tone === "bad" ? "text-destructive" : "text-foreground"}`}>
        {value}
      </p>
    </div>
  );
}
