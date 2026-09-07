"use client";

import { useMemo, useState } from "react";
import { Plus, Trash2, FileCheck2 } from "lucide-react";

import { formatPeso, formatDate } from "@/core/lib/format";
import { notify } from "@/core/lib/notify";
import { useConfirm } from "@/core/ui/use-confirm";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { useContacts } from "@/modules/accounting/hooks/useContacts";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import {
  usePurchaseOrders,
  useCreatePurchaseOrder,
  useConvertPurchaseOrder,
  useCancelPurchaseOrder,
  useDeletePurchaseOrder,
} from "@/modules/accounting/hooks/usePurchaseOrders";
import { manilaToday } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const STATUS_STYLE: Record<string, string> = {
  open: "bg-brand/10 text-brand",
  billed: "bg-muted text-muted-foreground",
  cancelled: "bg-destructive/10 text-destructive",
};

interface LineDraft {
  description: string;
  quantity: string;
  unit_price: string;
  account_id: string;
}
const emptyLine = (): LineDraft => ({ description: "", quantity: "1", unit_price: "", account_id: "" });

export default function PurchaseOrdersPage() {
  const { data: vendors } = useContacts("vendor");
  const { data: accounts } = useAccounts();
  const { data: pos, isError } = usePurchaseOrders();

  const create = useCreatePurchaseOrder();
  const convert = useConvertPurchaseOrder();
  const cancel = useCancelPurchaseOrder();
  const remove = useDeletePurchaseOrder();
  const { confirm, confirmDialog } = useConfirm();

  const expenseAccounts = useMemo(
    () => (accounts ?? []).filter((a) => a.type === "expense" && a.is_active),
    [accounts],
  );

  const [contactId, setContactId] = useState("");
  const [description, setDescription] = useState("");
  const [orderDate, setOrderDate] = useState(() => manilaToday());
  const [expectedDate, setExpectedDate] = useState("");
  const [taxRate, setTaxRate] = useState("0");
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);

  const setLine = (i: number, patch: Partial<LineDraft>) =>
    setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  const addLine = () => setLines((prev) => [...prev, emptyLine()]);
  const removeLine = (i: number) => setLines((prev) => (prev.length > 1 ? prev.filter((_, idx) => idx !== i) : prev));

  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const total = subtotal * (1 + (Number(taxRate) || 0) / 100);

  const canCreate =
    Boolean(contactId) && lines.every((l) => l.account_id) && subtotal > 0 && !create.isPending;

  const submit = () => {
    if (!canCreate) return;
    create.mutate(
      {
        contact_id: Number(contactId),
        order_date: orderDate,
        expected_date: expectedDate || null,
        description: description || null,
        tax_rate: Number(taxRate) || 0,
        lines: lines.map((l) => ({
          description: l.description || null,
          quantity: Number(l.quantity) || 1,
          unit_price: Number(l.unit_price) || 0,
          account_id: Number(l.account_id),
        })),
      },
      {
        onSuccess: () => {
          setContactId(""); setDescription(""); setExpectedDate(""); setTaxRate("0"); setLines([emptyLine()]);
        },
        onError: () => notify.error("Couldn’t create the purchase order. Check the fields and try again."),
      },
    );
  };

  return (
    <>
      {confirmDialog}
      <PageHeader
        eyebrow="Payables"
        title="Purchase Orders"
        subtitle="Commit to a vendor before the bill arrives — then convert to a bill in one click."
      />

      {/* Create PO */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <SectionHeading title="New purchase order" />
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Vendor</span>
            <select value={contactId} onChange={(e) => setContactId(e.target.value)} className={inputCls}>
              <option value="">Select…</option>
              {(vendors ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Description</span>
            <input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="e.g. Office supplies" className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Order date</span>
            <input type="date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Expected date (optional)</span>
            <input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} className={inputCls} />
          </label>
        </div>

        {/* Lines */}
        <div className="mt-5">
          <span className="text-sm font-medium">Line items</span>
          <div className="mt-2 space-y-2">
            {lines.map((l, i) => (
              <div key={i} className="grid gap-2 sm:grid-cols-12">
                <input placeholder="Description" value={l.description} onChange={(e) => setLine(i, { description: e.target.value })} className={`${inputCls} sm:col-span-4`} />
                <select value={l.account_id} onChange={(e) => setLine(i, { account_id: e.target.value })} className={`${inputCls} sm:col-span-3`}>
                  <option value="">Expense account…</option>
                  {expenseAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
                </select>
                <input type="number" min={0} step="0.01" placeholder="Qty" value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} className={`${inputCls} sm:col-span-2`} />
                <input type="number" min={0} step="0.01" placeholder="Unit price" value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} className={`${inputCls} sm:col-span-2`} />
                <button type="button" onClick={() => removeLine(i)} disabled={lines.length === 1} className="flex h-9 items-center justify-center rounded-lg border border-border text-muted-foreground hover:text-destructive disabled:opacity-30 sm:col-span-1" title="Remove line">
                  <Trash2 className="size-4" />
                </button>
              </div>
            ))}
          </div>
          <button type="button" onClick={addLine} className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline">
            <Plus className="size-4" /> Add line
          </button>
        </div>

        <div className="mt-5 flex flex-wrap items-end justify-between gap-4">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Tax rate (%)</span>
            <input type="number" min={0} max={100} step="0.01" value={taxRate} onChange={(e) => setTaxRate(e.target.value)} className={`${inputCls} w-28`} />
          </label>
          <div className="flex items-center gap-6">
            <p className="text-sm text-muted-foreground">
              Total: <span className="font-semibold text-foreground">{formatPeso(total)}</span>
            </p>
            <button type="button" disabled={!canCreate} onClick={submit} className="inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
              {create.isPending ? "Creating…" : "Create PO"}
            </button>
          </div>
        </div>
      </div>

      {/* List */}
      <div className="mt-6 overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full min-w-[760px] text-sm">
          <thead>
            <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-2.5 text-left font-bold">PO No.</th>
              <th className="px-4 py-2.5 text-left font-bold">Vendor</th>
              <th className="px-4 py-2.5 text-left font-bold">Order date</th>
              <th className="px-4 py-2.5 text-right font-bold">Amount</th>
              <th className="px-4 py-2.5 text-left font-bold">Status</th>
              <th className="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {(pos ?? []).length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">{isError ? "Couldn’t load purchase orders. Please try again." : "No purchase orders yet."}</td></tr>
            ) : (
              (pos ?? []).map((po) => (
                <tr key={po.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-3 font-mono text-foreground">{po.po_no}</td>
                  <td className="px-4 py-3">
                    <p className="font-medium text-foreground">{po.vendor_name ?? "—"}</p>
                    {po.description ? <p className="text-caption text-muted-foreground">{po.description}</p> : null}
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{formatDate(po.order_date)}</td>
                  <td className="px-4 py-3 text-right tabular-nums text-foreground">{formatPeso(po.amount)}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-0.5 text-caption font-semibold capitalize ${STATUS_STYLE[po.status] ?? "bg-muted text-muted-foreground"}`}>
                      {po.status}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-2">
                      {po.status === "open" ? (
                        <>
                          <button
                            type="button"
                            disabled={convert.isPending}
                            onClick={() =>
                              confirm({
                                title: `Convert ${po.po_no} to a bill?`,
                                description: "This records the expense in your ledger.",
                                confirmLabel: "Convert to bill",
                                destructive: false,
                                onConfirm: () =>
                                  convert.mutate(
                                    { id: po.id, issue_date: manilaToday() },
                                    { onError: () => notify.error("Couldn’t convert this PO.") },
                                  ),
                              })
                            }
                            className="inline-flex items-center gap-1 rounded-lg bg-brand px-2.5 py-1.5 text-caption font-semibold text-brand-foreground hover:opacity-90 disabled:opacity-50"
                          >
                            <FileCheck2 className="size-3.5" /> Convert to bill
                          </button>
                          <button type="button" onClick={() => confirm({ title: `Cancel ${po.po_no}?`, confirmLabel: "Cancel PO", onConfirm: () => cancel.mutate(po.id) })} className="text-caption font-semibold text-muted-foreground hover:underline">
                            Cancel
                          </button>
                          <button type="button" onClick={() => confirm({ title: `Delete ${po.po_no}?`, description: "This can’t be undone.", confirmLabel: "Delete", onConfirm: () => remove.mutate(po.id) })} className="text-caption font-semibold text-destructive hover:underline">
                            Delete
                          </button>
                        </>
                      ) : po.status === "billed" ? (
                        <span className="text-caption text-muted-foreground">Billed →</span>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}
