"use client";

import { useState } from "react";

import { LoadFailed } from "@/modules/accounting/components/load-failed";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { DataTable, type Column } from "@/core/components/data-table";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import { useContacts } from "@/modules/accounting/hooks/useContacts";
import { useBills, useCreateBill, usePayBill } from "@/modules/accounting/hooks/useBills";
import type { Bill } from "@/modules/accounting/types";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import { PromptModal } from "@/core/ui/prompt-modal";
import { notify } from "@/core/lib/notify";
import { formatPeso, formatDate } from "@/core/lib/format";
import {
  LineItemsEditor,
  emptyLine,
  lineAmount,
  type LineDraft,
} from "../_components/LineItemsEditor";
import { addDays, manilaToday } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function BillsPage() {
  const { data: accounts } = useAccounts();
  const { data: vendors } = useContacts("vendor");
  const { data: bills, isError } = useBills();
  const create = useCreateBill();
  const pay = usePayBill();

  const expenseAccounts = (accounts ?? []).filter((a) => a.type === "expense");
  const { canManage } = useAccountingPerms();

  const [contactId, setContactId] = useState("");
  const [date, setDate] = useState(() => manilaToday());
  const [taxRate, setTaxRate] = useState("12");
  const [vatClass, setVatClass] = useState<"vatable" | "exempt">("vatable");
  const [dueDays, setDueDays] = useState("30");
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);
  const [paying, setPaying] = useState<{ id: number; balance: number } | null>(null);

  const validLines = lines.filter((l) => l.account_id && lineAmount(l) > 0);
  const canCreate = Boolean(contactId) && validLines.length > 0 && Boolean(date) && !create.isPending;

  const submit = () => {
    if (!canCreate) return;
    create.mutate(
      {
        contact_id: Number(contactId),
        issue_date: date,
        due_date: addDays(date, Number(dueDays) || 0),
        tax_rate: vatClass === "vatable" ? Number.parseFloat(taxRate || "0") || 0 : 0,
        vat_class: vatClass,
        lines: validLines.map((l) => ({
          description: l.description || undefined,
          quantity: Number.parseFloat(l.quantity || "0"),
          unit_price: Number.parseFloat(l.unit_price || "0"),
          account_id: Number(l.account_id),
        })),
      },
      {
        onSuccess: () => {
          setContactId("");
          setLines([emptyLine()]);
          setDueDays("30");
          setTaxRate("12");
          setVatClass("vatable");
        },
      },
    );
  };

  const columns: Column<Bill>[] = [
    { header: "Bill", cell: (r) => <span className="font-mono text-foreground">{r.bill_no}</span> },
    { header: "Vendor", cell: (r) => <span className="font-medium text-foreground">{r.vendor_name}</span> },
    { header: "Date", cell: (r) => <span className="text-muted-foreground">{formatDate(r.issue_date)}</span> },
    { header: "Total", align: "right", cell: (r) => <span className="tabular-nums">{formatPeso(r.amount)}</span> },
    {
      header: "",
      align: "right",
      cell: (r) => {
        if (r.status === "paid") {
          return <span className="rounded-full bg-brand-subtle px-2.5 py-0.5 text-caption font-semibold uppercase tracking-wide text-dark-teal">Paid</span>;
        }
        const balance = r.amount - r.amount_paid;
        if (!canManage) {
          return (
            <span className="rounded-full bg-muted px-2.5 py-0.5 text-caption font-semibold uppercase tracking-wide text-muted-foreground">
              {r.amount_paid > 0 ? `Partial · ${formatPeso(balance)} left` : "Unpaid"}
            </span>
          );
        }
        return (
          <div className="flex items-center justify-end gap-2">
            {r.amount_paid > 0 ? (
              <span className="text-caption font-semibold text-muted-foreground">{formatPeso(balance)} left</span>
            ) : null}
            <button
              type="button"
              disabled={pay.isPending}
              onClick={() => setPaying({ id: r.id, balance })}
              className="inline-flex items-center rounded-lg border border-border px-3 py-1.5 text-caption font-semibold text-foreground transition-colors hover:bg-muted disabled:opacity-50"
            >
              Record payment
            </button>
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader eyebrow="Payables" title="Bills" subtitle="Track what you owe suppliers — line items + VAT, auto-posted to A/P & Expense." />

      {canManage ? (
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <SectionHeading title="New bill" />
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Vendor</span>
            <select value={contactId} onChange={(e) => setContactId(e.target.value)} className={inputCls}>
              <option value="">Select a vendor…</option>
              {(vendors ?? []).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Bill date</span>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={inputCls} />
          </label>
          {/* Without this the payload carried no due date, the API stored null,
              and Aged Payables fell back to `due_date ?? issue_date` -- so every
              bill was overdue the morning after it was recorded. */}
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Due in (days)</span>
            <input
              type="number" min={0} max={365} step={1}
              value={dueDays}
              onChange={(e) => setDueDays(e.target.value)}
              className={inputCls}
            />
            <span className="text-caption text-muted-foreground">
              Due {formatDate(addDays(date, Number(dueDays) || 0))}
            </span>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">VAT treatment</span>
            <select
              value={vatClass}
              onChange={(e) => {
                const v = e.target.value as "vatable" | "exempt";
                setVatClass(v);
                setTaxRate(v === "vatable" ? "12" : "0");
              }}
              className={inputCls}
            >
              <option value="vatable">Vatable (12%)</option>
              <option value="exempt">VAT-exempt</option>
            </select>
          </label>
        </div>

        <div className="mt-5">
          <LineItemsEditor
            accounts={expenseAccounts}
            accountLabel="Expense account"
            lines={lines}
            setLines={setLines}
            taxRate={taxRate}
            setTaxRate={setTaxRate}
          />
        </div>

        {create.isError ? (
          <p className="mt-3 text-caption text-destructive">Couldn’t record the bill. Check the fields.</p>
        ) : null}
        <button type="button" disabled={!canCreate} onClick={submit} className="mt-4 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
          {create.isPending ? "Saving…" : "Record bill"}
        </button>
      </div>
      ) : null}

      <div className="mt-6">
        {isError ? (
          <LoadFailed what="bills" />
        ) : (
          <DataTable<Bill> columns={columns} rows={bills ?? []} rowKey={(r) => r.id} pageSize={10} searchable={false} filterable={false} empty="No bills yet." />
        )}
      </div>

      <PromptModal
        open={paying !== null}
        title="Record payment"
        label="Payment amount"
        type="amount"
        initialValue={paying ? String(paying.balance) : ""}
        hint={paying ? `Balance due: ${formatPeso(paying.balance)}` : undefined}
        confirmLabel="Record payment"
        pending={pay.isPending}
        onClose={() => setPaying(null)}
        onConfirm={(v) => {
          const amt = Number.parseFloat(v);
          if (!Number.isFinite(amt) || amt <= 0) return;
          pay.mutate(
            { id: paying!.id, amount: amt },
            { onSuccess: () => setPaying(null), onError: () => notify.error("Couldn’t record the payment.") },
          );
        }}
      />
    </>
  );
}
