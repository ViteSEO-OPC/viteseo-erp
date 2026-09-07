"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import { cn } from "@/lib/utils";
import { parseAmount } from "@/core/lib/format";
import { PageHeader } from "@/core/components/page-header";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import { useCreateJournalEntry } from "@/modules/accounting/hooks/useJournalEntries";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import { manilaToday } from "@/modules/accounting/lib/dates";

type Mode = "expense" | "income";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function NewEntryPage() {
  const router = useRouter();
  const { data: accounts } = useAccounts();
  const create = useCreateJournalEntry();
  const { canManage } = useAccountingPerms();

  const [mode, setMode] = useState<Mode>("expense");
  const [date, setDate] = useState(() => manilaToday());
  const [accountId, setAccountId] = useState("");
  const [amount, setAmount] = useState("");
  const [memo, setMemo] = useState("");
  const [paidBy, setPaidBy] = useState<"cash" | "credit">("cash");

  const byCode = useMemo(() => {
    const map: Record<string, number> = {};
    (accounts ?? []).forEach((a) => {
      map[a.code] = a.id;
    });
    return map;
  }, [accounts]);

  const options = useMemo(
    () => (accounts ?? []).filter((a) => a.type === mode),
    [accounts, mode],
  );

  const amt = parseAmount(amount || "0");
  const canSubmit = Boolean(accountId) && amt > 0 && Boolean(date) && !create.isPending;

  const submit = () => {
    if (!canSubmit) return;
    const acct = Number(accountId);

    const lines =
      mode === "expense"
        ? [
            { account_id: acct, debit: amt },
            { account_id: byCode[paidBy === "cash" ? "1000" : "2000"], credit: amt },
          ]
        : [
            { account_id: byCode["1000"], debit: amt },
            { account_id: acct, credit: amt },
          ];

    create.mutate(
      { entry_date: date, memo: memo || undefined, post: true, lines },
      { onSuccess: () => router.push("/accounting/journal") },
    );
  };

  if (!canManage) {
    return (
      <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
        You don’t have permission to record entries.
      </p>
    );
  }

  return (
    <>
      <Link
        href="/accounting/journal"
        className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        Back to Journal
      </Link>

      <div className="mt-4">
        <PageHeader
          eyebrow="Ledger"
          title="Record a transaction"
          subtitle="Records a balanced entry for you — no debits/credits to worry about."
        />
      </div>

      <div className="mt-6 max-w-xl rounded-card border border-border bg-card p-6">
        {/* Type toggle */}
        <div className="flex gap-1 rounded-lg border border-border p-1">
          {(["expense", "income"] as Mode[]).map((m) => (
            <button
              key={m}
              type="button"
              onClick={() => {
                setMode(m);
                setAccountId("");
              }}
              className={cn(
                "flex-1 rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-colors",
                mode === m
                  ? "bg-brand text-brand-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {m}
            </button>
          ))}
        </div>

        <div className="mt-5 grid gap-4">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Date</span>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={inputCls} />
          </label>

          <label className="grid gap-1.5">
            <span className="text-sm font-medium">
              {mode === "expense" ? "Expense category" : "Income source"}
            </span>
            <select value={accountId} onChange={(e) => setAccountId(e.target.value)} className={inputCls}>
              <option value="">Select an account…</option>
              {options.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} · {a.name}
                </option>
              ))}
            </select>
          </label>

          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Amount (₱)</span>
            <input
              type="text"
              inputMode="decimal"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder="0.00"
              className={inputCls}
            />
          </label>

          {mode === "expense" ? (
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Paid via</span>
              <select
                value={paidBy}
                onChange={(e) => setPaidBy(e.target.value as "cash" | "credit")}
                className={inputCls}
              >
                <option value="cash">Cash</option>
                <option value="credit">On credit (Accounts Payable)</option>
              </select>
            </label>
          ) : (
            <p className="text-caption text-muted-foreground">Received in Cash.</p>
          )}

          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Memo (optional)</span>
            <input
              type="text"
              value={memo}
              onChange={(e) => setMemo(e.target.value)}
              placeholder="e.g. Meralco — July"
              className={inputCls}
            />
          </label>

          {create.isError ? (
            <p className="text-caption text-destructive">
              Couldn’t save that. Check the amount and try again.
            </p>
          ) : null}

          <div className="mt-1 flex items-center gap-3">
            <button
              type="button"
              disabled={!canSubmit}
              onClick={submit}
              className="inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {create.isPending ? "Saving…" : "Save & post"}
            </button>
            <Link
              href="/accounting/journal"
              className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
              Cancel
            </Link>
          </div>
        </div>
      </div>
    </>
  );
}
