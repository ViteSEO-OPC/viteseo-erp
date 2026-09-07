"use client";

import { useState } from "react";

import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import {
  useAccounts,
  useCreateAccount,
  useUpdateAccount,
  useDeleteAccount,
} from "@/modules/accounting/hooks/useAccounts";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import { PromptModal } from "@/core/ui/prompt-modal";
import { useConfirm } from "@/core/ui/use-confirm";
import { notify } from "@/core/lib/notify";
import type { Account } from "@/modules/accounting/types";

const TYPE_LABEL: Record<Account["type"], string> = {
  asset: "Asset",
  liability: "Liability",
  equity: "Equity",
  income: "Income",
  expense: "Expense",
};

// Section headings — standard chart-of-accounts order. The backend already
// returns rows in this order (asset → liability → equity → income → expense).
const TYPE_SECTION: Record<Account["type"], string> = {
  asset: "Assets",
  liability: "Liabilities",
  equity: "Equity",
  income: "Income",
  expense: "Expenses",
};

const TYPE_ORDER: Account["type"][] = ["asset", "liability", "equity", "income", "expense"];

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function ChartOfAccountsPage() {
  const { data: accounts, isLoading, isError } = useAccounts();
  const create = useCreateAccount();
  const update = useUpdateAccount();
  const del = useDeleteAccount();
  const { canManageAccounts } = useAccountingPerms();

  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [type, setType] = useState("expense");
  const [isBank, setIsBank] = useState(false);
  const [renaming, setRenaming] = useState<Account | null>(null);
  const { confirm, confirmDialog } = useConfirm();

  const canCreate = Boolean(code) && Boolean(name) && !create.isPending;

  const submit = () => {
    if (!canCreate) return;
    create.mutate(
      { code, name, type, is_bank: type === "asset" && isBank },
      {
        onSuccess: () => {
          setCode("");
          setName("");
          setType("expense");
          setIsBank(false);
        },
      },
    );
  };

  // Group accounts into the standard type sections (rows arrive type-ordered).
  const grouped = TYPE_ORDER.map((t) => ({
    type: t,
    rows: (accounts ?? []).filter((a) => a.type === t),
  })).filter((g) => g.rows.length > 0);

  return (
    <>
      <PageHeader
        eyebrow="Ledger"
        title="Chart of Accounts"
        subtitle="The ledger accounts for your organization."
      />

      {canManageAccounts ? (
        <div className="mt-6 max-w-3xl rounded-card border border-border bg-card p-6">
          <SectionHeading title="New account" />
          <div className="mt-4 grid gap-4 sm:grid-cols-4">
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Code</span>
              <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="e.g. 5400" className={inputCls} />
            </label>
            <label className="grid gap-1.5 sm:col-span-2">
              <span className="text-sm font-medium">Name</span>
              <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Marketing Expense" className={inputCls} />
            </label>
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Type</span>
              <select value={type} onChange={(e) => setType(e.target.value)} className={inputCls}>
                {(Object.keys(TYPE_LABEL) as Account["type"][]).map((t) => (
                  <option key={t} value={t}>{TYPE_LABEL[t]}</option>
                ))}
              </select>
            </label>
          </div>
          {/* Only an asset can be a bank account, and only a bank account is
              offered on Bank Reconciliation — a business banks in more than one
              place, so it names its own rather than us guessing. */}
          {type === "asset" ? (
            <label className="mt-4 flex items-start gap-2.5">
              <input
                type="checkbox"
                checked={isBank}
                onChange={(e) => setIsBank(e.target.checked)}
                className="mt-0.5 size-4 rounded border-border"
              />
              <span className="text-sm">
                This is a bank or cash account
                <span className="block text-caption text-muted-foreground">
                  Money actually sits here, so it can be reconciled against a bank statement.
                </span>
              </span>
            </label>
          ) : null}
          {create.isError ? (
            <p className="mt-3 text-caption text-destructive">Couldn’t add — the code may already exist.</p>
          ) : null}
          <button
            type="button"
            disabled={!canCreate}
            onClick={submit}
            className="mt-4 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {create.isPending ? "Saving…" : "Add account"}
          </button>
        </div>
      ) : null}

      <div className="mt-6">
        {isError ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            Couldn’t load the chart of accounts. Please try again.
          </p>
        ) : isLoading ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            Loading…
          </p>
        ) : grouped.length === 0 ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            No accounts yet.
          </p>
        ) : (
          <div className="space-y-10">
            {grouped.map((g) => (
              <section key={g.type}>
                <SectionHeading
                  title={TYPE_SECTION[g.type]}
                  meta={`${g.rows.length} account${g.rows.length === 1 ? "" : "s"}`}
                />
                <div className="mt-3 overflow-x-auto border-t border-rule">
                  <table className="w-full min-w-[420px] text-sm">
                    <tbody>
                      {g.rows.map((a) => (
                        <tr key={a.id} className="border-b border-border last:border-0">
                          <td className="w-28 py-2.5 pr-4 font-mono text-muted-foreground">{a.code}</td>
                          <td className="py-2.5 pr-4 font-medium text-foreground">
                            {a.name}
                            {a.is_bank ? (
                              <span className="ml-2 rounded border border-border px-1.5 py-0.5 text-caption uppercase tracking-wide text-muted-foreground">
                                bank
                              </span>
                            ) : null}
                            {!a.is_active ? (
                              <span className="ml-2 text-caption uppercase tracking-wide text-muted-foreground">(inactive)</span>
                            ) : null}
                          </td>
                          {canManageAccounts ? (
                            <td className="py-2.5 text-right">
                              <div className="flex items-center justify-end gap-3 text-caption font-semibold">
                                <button
                                  type="button"
                                  onClick={() => setRenaming(a)}
                                  className="text-brand hover:underline"
                                >
                                  Edit
                                </button>
                                <button
                                  type="button"
                                  onClick={() => update.mutate({ id: a.id, name: a.name, is_active: !a.is_active })}
                                  className="text-muted-foreground hover:text-foreground"
                                >
                                  {a.is_active ? "Deactivate" : "Activate"}
                                </button>
                                {a.type === "asset" ? (
                                  <button
                                    type="button"
                                    onClick={() => update.mutate({ id: a.id, name: a.name, is_bank: !a.is_bank })}
                                    className="text-muted-foreground hover:text-foreground"
                                  >
                                    {a.is_bank ? "Not a bank" : "Mark as bank"}
                                  </button>
                                ) : null}
                                <button
                                  type="button"
                                  onClick={() =>
                                    confirm({
                                      title: `Delete ${a.code} ${a.name}?`,
                                      description: "This can’t be undone.",
                                      confirmLabel: "Delete",
                                      onConfirm: () =>
                                        del.mutate(a.id, {
                                          // The server says WHY: ledger entries, or a
                                          // purchase order / budget / recurring invoice
                                          // still pointing at it. Guessing one reason
                                          // here would print the wrong one.
                                          onError: (e) =>
                                            notify.error(
                                              (e as { response?: { data?: { errors?: { account?: string[] } } } })
                                                ?.response?.data?.errors?.account?.[0] ??
                                                "This account is still in use and can’t be deleted — deactivate it instead.",
                                            ),
                                        }),
                                    })
                                  }
                                  className="text-destructive hover:underline"
                                >
                                  Delete
                                </button>
                              </div>
                            </td>
                          ) : null}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            ))}
          </div>
        )}
      </div>

      {confirmDialog}
      <PromptModal
        open={renaming !== null}
        title="Rename account"
        label="Account name"
        initialValue={renaming?.name ?? ""}
        hint={renaming ? `${renaming.code} · code and type can’t be changed` : undefined}
        confirmLabel="Save"
        pending={update.isPending}
        onClose={() => setRenaming(null)}
        onConfirm={(v) => {
          update.mutate(
            { id: renaming!.id, name: v, is_active: renaming!.is_active },
            { onSuccess: () => setRenaming(null) },
          );
        }}
      />
    </>
  );
}
