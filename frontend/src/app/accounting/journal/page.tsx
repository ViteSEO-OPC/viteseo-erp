"use client";

import Link from "next/link";
import { Plus, Undo2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatPeso, formatDate } from "@/core/lib/format";
import { PageHeader } from "@/core/components/page-header";
import { RowList, Row } from "@/core/components/editorial";
import { useJournalEntries, useReverseEntry } from "@/modules/accounting/hooks/useJournalEntries";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import { useConfirm } from "@/core/ui/use-confirm";
import type { JournalEntry } from "@/modules/accounting/types";

export default function JournalPage() {
  const { data: entries, isLoading, isError } = useJournalEntries();
  const { canManage } = useAccountingPerms();
  const list = entries ?? [];

  return (
    <>
      <PageHeader
        eyebrow="Ledger"
        title="Journal"
        subtitle="Every transaction, as balanced debit & credit entries."
        actions={
          canManage ? (
            <Link
              href="/accounting/journal/new"
              className="inline-flex items-center gap-1.5 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90"
            >
              <Plus className="size-4" />
              New entry
            </Link>
          ) : undefined
        }
      />

      <div className="mt-6">
        {isError ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            Couldn’t load the journal. Please try again.
          </p>
        ) : isLoading ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            Loading…
          </p>
        ) : list.length === 0 ? (
          <p className="rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
            No journal entries yet.
          </p>
        ) : (
          <RowList>
            {list.map((entry, i) => (
              <EntryRow key={entry.id} entry={entry} index={i} />
            ))}
          </RowList>
        )}
      </div>
    </>
  );
}

function EntryRow({ entry, index }: { entry: JournalEntry; index: number }) {
  const reverse = useReverseEntry();
  const { canReverse } = useAccountingPerms();
  const { confirm, confirmDialog } = useConfirm();

  return (
    <Row index={index} className="items-start py-5">
      {confirmDialog}
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="font-semibold text-foreground">{formatDate(entry.entry_date)}</p>
          {entry.memo ? (
            <p className="mt-0.5 text-caption text-muted-foreground">{entry.memo}</p>
          ) : null}
        </div>
        <div className="flex items-center gap-2">
          {entry.status === "posted" && canReverse ? (
            <button
              type="button"
              disabled={reverse.isPending}
              onClick={() =>
                confirm({
                  title: "Reverse this entry?",
                  description: "This posts a mirror entry that cancels it.",
                  confirmLabel: "Reverse",
                  onConfirm: () => reverse.mutate(entry.id),
                })
              }
              className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-caption font-semibold text-muted-foreground transition-colors hover:bg-muted hover:text-destructive disabled:opacity-50"
            >
              <Undo2 className="size-3.5" /> Reverse
            </button>
          ) : null}
          <span
            className={cn(
              "rounded-full px-2.5 py-0.5 text-caption font-semibold uppercase tracking-wide",
              entry.status === "posted"
                ? "bg-brand-subtle text-dark-teal"
                : "bg-muted text-muted-foreground",
            )}
          >
            {entry.status}
          </span>
        </div>
      </div>

      <div className="mt-4 overflow-x-auto">
        <table className="w-full min-w-[480px] text-sm">
          <thead>
            <tr className="border-b border-border">
              <th className="py-2 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">
                Code
              </th>
              <th className="py-2 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">
                Account
              </th>
              <th className="py-2 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                Debit
              </th>
              <th className="py-2 text-right text-label font-bold uppercase tracking-wide text-muted-foreground">
                Credit
              </th>
            </tr>
          </thead>
          <tbody>
            {entry.lines.map((line) => {
              // Bookkeeping convention: debit flush-left, credit indented (T-account).
              const isCredit = !Number(line.debit) && Number(line.credit) > 0;
              return (
                <tr key={line.id} className="border-b border-border last:border-0">
                  <td className="py-2 font-mono text-muted-foreground">
                    {line.account_code}
                  </td>
                  <td className={cn("py-2 text-foreground", isCredit && "pl-8")}>
                    {line.account_name}
                  </td>
                  <td className="py-2 text-right tabular-nums">
                    {line.debit ? formatPeso(line.debit) : "—"}
                  </td>
                  <td className="py-2 text-right tabular-nums">
                    {line.credit ? formatPeso(line.credit) : "—"}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Row>
  );
}
