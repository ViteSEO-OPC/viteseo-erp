"use client";

import { useState } from "react";

import { LoadFailed } from "@/modules/accounting/components/load-failed";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { DataTable, type Column } from "@/core/components/data-table";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import { useContacts } from "@/modules/accounting/hooks/useContacts";
import { useCreateInvoice, useInvoices, usePayInvoice, useSendInvoice } from "@/modules/accounting/hooks/useInvoices";
import { useOrgSettings } from "@/modules/accounting/hooks/useOrgSettings";
import type { Invoice } from "@/modules/accounting/types";
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

export default function InvoicesPage() {
  const { data: accounts } = useAccounts();
  const { data: customers } = useContacts("customer");
  const { data: invoices, isError } = useInvoices();
  const { data: orgSettings } = useOrgSettings();
  const create = useCreateInvoice();
  const pay = usePayInvoice();
  const send = useSendInvoice();

  // The org's configured VAT rate (Business settings) when VAT-registered;
  // the statutory 12% otherwise.
  const defaultVatRate =
    orgSettings?.is_vat_registered && orgSettings.default_tax_rate != null
      ? String(orgSettings.default_tax_rate)
      : "12";

  const incomeAccounts = (accounts ?? []).filter((a) => a.type === "income");
  const { canManage } = useAccountingPerms();

  const [contactId, setContactId] = useState("");
  const [date, setDate] = useState(() => manilaToday());
  const [taxRate, setTaxRate] = useState("12");
  const [vatClass, setVatClass] = useState<"vatable" | "zero_rated" | "exempt">("vatable");
  const [dueDays, setDueDays] = useState("30");
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);
  // Invoice currently being paid (drives the payment modal).
  const [paying, setPaying] = useState<{ id: number; balance: number } | null>(null);

  // Prefill the VAT rate from Business settings once they load — adjusted during render
  // (React's recommended pattern) rather than in an effect. Null so the first render syncs too.
  const [syncedVatRate, setSyncedVatRate] = useState<string | null>(null);
  if (defaultVatRate !== syncedVatRate) {
    setSyncedVatRate(defaultVatRate);
    setTaxRate((prev) => (prev === "12" ? defaultVatRate : prev));
  }

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
          setTaxRate(defaultVatRate);
          setVatClass("vatable");
        },
      },
    );
  };

  const columns: Column<Invoice>[] = [
    {
      header: "Invoice",
      // Plain text, not a link. This pointed at `/invoice/{id}`, a route that
      // does not exist in this app -- the primary click target on the screen
      // 404'd. There is no invoice detail or print view yet; the backend builds
      // the whole payload for one (letterhead, contact, lines) and nothing
      // fetches it. Building that page is its own piece of work; claiming it
      // exists is not.
      cell: (r) => <span className="font-mono text-foreground">{r.invoice_no}</span>,
    },
    { header: "Customer", cell: (r) => <span className="font-medium text-foreground">{r.customer_name}</span> },
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
            {r.sent_at === null ? (
              <button
                type="button"
                disabled={send.isPending}
                onClick={() =>
                  send.mutate(r.id, {
                    onSuccess: () => notify.success(`Emailed to ${r.customer_name}.`),
                    onError: (e) =>
                      notify.error(
                        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
                          "Couldn’t send the invoice.",
                      ),
                  })
                }
                className="inline-flex items-center rounded-lg border border-border px-3 py-1.5 text-caption font-semibold text-foreground transition-colors hover:bg-muted disabled:opacity-50"
              >
                Send
              </button>
            ) : (
              <span className="text-caption text-muted-foreground">Sent {formatDate(r.sent_at)}</span>
            )}
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
      <PageHeader eyebrow="Receivables" title="Invoices" subtitle="Bill your clients — line items + VAT, auto-posted to A/R & Income." />

      {canManage ? (
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <SectionHeading title="New invoice" />
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Customer</span>
            <select value={contactId} onChange={(e) => setContactId(e.target.value)} className={inputCls}>
              <option value="">Select a customer…</option>
              {(customers ?? []).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Issue date</span>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={inputCls} />
          </label>
          {/* Without this the payload carried no due date, the API stored null,
              and every report fell back to `due_date ?? issue_date` -- so each
              document was overdue the next morning. Days rather than a date, to
              match the Recurring Invoices form and how terms are actually said. */}
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
                const v = e.target.value as "vatable" | "zero_rated" | "exempt";
                setVatClass(v);
                setTaxRate(v === "vatable" ? defaultVatRate : "0");
              }}
              className={inputCls}
            >
              <option value="vatable">Vatable (12%)</option>
              <option value="zero_rated">Zero-rated (0%)</option>
              <option value="exempt">VAT-exempt</option>
            </select>
          </label>
        </div>

        <div className="mt-5">
          <LineItemsEditor
            accounts={incomeAccounts}
            accountLabel="Income account"
            lines={lines}
            setLines={setLines}
            taxRate={taxRate}
            setTaxRate={setTaxRate}
          />
        </div>

        {create.isError ? (
          <p className="mt-3 text-caption text-destructive">Couldn’t create the invoice. Check the fields.</p>
        ) : null}
        <button type="button" disabled={!canCreate} onClick={submit} className="mt-4 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50">
          {create.isPending ? "Saving…" : "Create invoice"}
        </button>
      </div>
      ) : null}

      <div className="mt-6">
        {isError ? (
          <LoadFailed what="invoices" />
        ) : (
          <DataTable<Invoice> columns={columns} rows={invoices ?? []} rowKey={(r) => r.id} pageSize={10} searchable={false} filterable={false} empty="No invoices yet." />
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
