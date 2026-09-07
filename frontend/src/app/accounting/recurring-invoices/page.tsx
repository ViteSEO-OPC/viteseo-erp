"use client";

import { useMemo, useState } from "react";
import { Plus, Play, Pause, Trash2, RefreshCw } from "lucide-react";

import { formatPeso, formatDate } from "@/core/lib/format";
import { notify } from "@/core/lib/notify";
import { useConfirm } from "@/core/ui/use-confirm";
import { useOrgSettings } from "@/modules/accounting/hooks/useOrgSettings";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { useContacts } from "@/modules/accounting/hooks/useContacts";
import { useAccounts } from "@/modules/accounting/hooks/useAccounts";
import {
  useRecurringInvoices,
  useCreateRecurringInvoice,
  usePauseRecurringInvoice,
  useDeleteRecurringInvoice,
  useRunRecurringInvoices,
} from "@/modules/accounting/hooks/useRecurringInvoices";
import { manilaToday } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const FREQ = [
  { key: "weekly", label: "Weekly" },
  { key: "monthly", label: "Monthly" },
  { key: "quarterly", label: "Quarterly" },
  { key: "yearly", label: "Yearly" },
];

const STATUS_STYLE: Record<string, string> = {
  active: "bg-brand/10 text-brand",
  paused: "bg-warning/15 text-warning",
  ended: "bg-muted text-muted-foreground",
};

interface LineDraft {
  description: string;
  quantity: string;
  unit_price: string;
  account_id: string;
}

const emptyLine = (): LineDraft => ({ description: "", quantity: "1", unit_price: "", account_id: "" });

export default function RecurringInvoicesPage() {
  const { data: customers } = useContacts("customer");
  const { data: accounts } = useAccounts();
  const { data: schedules, isError } = useRecurringInvoices();

  const create = useCreateRecurringInvoice();
  const toggle = usePauseRecurringInvoice();
  const remove = useDeleteRecurringInvoice();
  const run = useRunRecurringInvoices();
  const { confirm, confirmDialog } = useConfirm();

  const incomeAccounts = useMemo(
    () => (accounts ?? []).filter((a) => a.type === "income" && a.is_active),
    [accounts],
  );

  const { data: orgSettings } = useOrgSettings();

  // The org's configured VAT rate when VAT-registered, the statutory 12%
  // otherwise -- the same rule the one-off Invoices screen uses.
  const defaultVatRate =
    orgSettings?.is_vat_registered && orgSettings.default_tax_rate != null
      ? String(orgSettings.default_tax_rate)
      : "12";

  const [contactId, setContactId] = useState("");
  const [description, setDescription] = useState("");
  const [frequency, setFrequency] = useState("monthly");
  const [startDate, setStartDate] = useState(() => manilaToday());
  const [dueDays, setDueDays] = useState("15");
  const [vatClass, setVatClass] = useState<"vatable" | "zero_rated" | "exempt">("vatable");
  const [endDate, setEndDate] = useState("");
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);

  const setLine = (i: number, patch: Partial<LineDraft>) =>
    setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  const addLine = () => setLines((prev) => [...prev, emptyLine()]);
  const removeLine = (i: number) => setLines((prev) => (prev.length > 1 ? prev.filter((_, idx) => idx !== i) : prev));

  const draftTotal = lines.reduce(
    (sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0),
    0,
  );

  const canCreate =
    Boolean(contactId) &&
    lines.every((l) => l.account_id && Number(l.unit_price) >= 0) &&
    draftTotal > 0 &&
    !create.isPending;

  const reset = () => {
    setContactId("");
    setDescription("");
    setFrequency("monthly");
    setDueDays("15");
    setEndDate("");
    setLines([emptyLine()]);
  };

  const send = (confirmBackdate: boolean) => {
    const payload = {
      contact_id: Number(contactId),
      description: description || null,
      frequency,
      // Sent explicitly. Without them InvoiceService fell to
      // `$taxRate > 0 ? 'vatable' : 'exempt'`, so every invoice a retainer
      // generated was booked VAT-EXEMPT and filed that way on the 2550Q.
      // Read at submit, not held in state: org settings arrive asynchronously,
      // so a rate captured at first render would send 12% for an org whose
      // configured rate is something else.
      tax_rate: vatClass === "vatable" ? Number.parseFloat(defaultVatRate) || 0 : 0,
      vat_class: vatClass,
      due_days: Number(dueDays) || 0,
      start_date: startDate,
      end_date: endDate || null,
      confirm_backdate: confirmBackdate,
      lines: lines.map((l) => ({
        description: l.description || null,
        quantity: Number(l.quantity) || 1,
        unit_price: Number(l.unit_price) || 0,
        account_id: Number(l.account_id),
      })),
    };
    create.mutate(payload, {
      onSuccess: reset,
      onError: (err: unknown) => {
        // Back-dated confirmation: the API asks us to confirm before posting
        // a pile of past-dated invoices. Ask, then resend with confirmation.
        const e = err as { response?: { status?: number; data?: { errors?: { confirm_backdate?: string[] } } } };
        const msg = e?.response?.data?.errors?.confirm_backdate?.[0];
        if (e?.response?.status === 422 && msg && !confirmBackdate) {
          confirm({
            title: "Back-dated invoices",
            description: msg,
            confirmLabel: "Create anyway",
            destructive: false,
            onConfirm: () => send(true),
          });
          return;
        }
        notify.error("Couldn’t create the schedule. Check the fields and try again.");
      },
    });
  };

  const submit = () => {
    if (!canCreate) return;
    send(false);
  };

  const doRun = () => {
    run.mutate(undefined, {
      onSuccess: (r) =>
        r.generated > 0
          ? notify.success(`Generated ${r.generated} invoice${r.generated === 1 ? "" : "s"}.`)
          : notify.info("Nothing due right now."),
    });
  };

  return (
    <>
      {confirmDialog}
      <PageHeader
        eyebrow="Receivables"
        title="Recurring Invoices"
        subtitle="Set a schedule once — invoices generate automatically each period."
        actions={
          <button
            type="button"
            onClick={doRun}
            disabled={run.isPending}
            className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground hover:bg-muted/40 disabled:opacity-50"
          >
            <RefreshCw className={`size-4 ${run.isPending ? "animate-spin" : ""}`} /> Generate due now
          </button>
        }
      />

      {/* Create schedule */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <SectionHeading title="New schedule" />
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Customer</span>
            <select value={contactId} onChange={(e) => setContactId(e.target.value)} className={inputCls}>
              <option value="">Select…</option>
              {(customers ?? []).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Description</span>
            <input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="e.g. Monthly retainer" className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Frequency</span>
            <select value={frequency} onChange={(e) => setFrequency(e.target.value)} className={inputCls}>
              {FREQ.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Start date</span>
            <input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Due in (days)</span>
            <input type="number" min={0} value={dueDays} onChange={(e) => setDueDays(e.target.value)} className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">VAT treatment</span>
            <select
              value={vatClass}
              onChange={(e) => setVatClass(e.target.value as "vatable" | "zero_rated" | "exempt")}
              className={inputCls}
            >
              <option value="vatable">Vatable ({defaultVatRate}%)</option>
              <option value="zero_rated">Zero-rated (0%)</option>
              <option value="exempt">VAT-exempt</option>
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">End date (optional)</span>
            <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} className={inputCls} />
          </label>
        </div>

        {/* Lines */}
        <div className="mt-5">
          <span className="text-sm font-medium">Line items</span>
          <div className="mt-2 space-y-2">
            {lines.map((l, i) => (
              <div key={i} className="grid gap-2 sm:grid-cols-12">
                <input
                  placeholder="Description"
                  value={l.description}
                  onChange={(e) => setLine(i, { description: e.target.value })}
                  className={`${inputCls} sm:col-span-4`}
                />
                <select
                  value={l.account_id}
                  onChange={(e) => setLine(i, { account_id: e.target.value })}
                  className={`${inputCls} sm:col-span-3`}
                >
                  <option value="">Income account…</option>
                  {incomeAccounts.map((a) => (
                    <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
                  ))}
                </select>
                <input
                  type="number" min={0} step="0.01" placeholder="Qty"
                  value={l.quantity}
                  onChange={(e) => setLine(i, { quantity: e.target.value })}
                  className={`${inputCls} sm:col-span-2`}
                />
                <input
                  type="number" min={0} step="0.01" placeholder="Unit price"
                  value={l.unit_price}
                  onChange={(e) => setLine(i, { unit_price: e.target.value })}
                  className={`${inputCls} sm:col-span-2`}
                />
                <button
                  type="button"
                  onClick={() => removeLine(i)}
                  disabled={lines.length === 1}
                  className="flex h-9 items-center justify-center rounded-lg border border-border text-muted-foreground hover:text-destructive disabled:opacity-30 sm:col-span-1"
                  title="Remove line"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            ))}
          </div>
          <button type="button" onClick={addLine} className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline">
            <Plus className="size-4" /> Add line
          </button>
        </div>

        <div className="mt-5 flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Per-invoice total: <span className="font-semibold text-foreground">{formatPeso(draftTotal)}</span>
          </p>
          <button
            type="button"
            disabled={!canCreate}
            onClick={submit}
            className="inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {create.isPending ? "Creating…" : "Create schedule"}
          </button>
        </div>
      </div>

      {/* Schedules list */}
      <div className="mt-6 overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full min-w-[760px] text-sm">
          <thead>
            <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-2.5 text-left font-bold">Customer</th>
              <th className="px-4 py-2.5 text-left font-bold">Every</th>
              <th className="px-4 py-2.5 text-left font-bold">Next run</th>
              <th className="px-4 py-2.5 text-right font-bold">Amount</th>
              <th className="px-4 py-2.5 text-left font-bold">Status</th>
              <th className="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {(schedules ?? []).length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">{isError ? "Couldn’t load recurring invoices. Please try again." : "No recurring invoices yet."}</td></tr>
            ) : (
              (schedules ?? []).map((s) => (
                <tr key={s.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-3">
                    <p className="font-medium text-foreground">{s.contact_name ?? "—"}</p>
                    {s.description ? <p className="text-caption text-muted-foreground">{s.description}</p> : null}
                  </td>
                  <td className="px-4 py-3 capitalize text-muted-foreground">{s.frequency}</td>
                  <td className="px-4 py-3 text-muted-foreground">
                    {s.status === "ended" ? "—" : formatDate(s.next_run_date)}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-foreground">{formatPeso(s.amount)}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-0.5 text-caption font-semibold capitalize ${STATUS_STYLE[s.status] ?? "bg-muted text-muted-foreground"}`}>
                      {s.status}
                    </span>
                    {s.occurrences > 0 ? (
                      <span className="ml-2 text-caption text-muted-foreground">{s.occurrences} sent</span>
                    ) : null}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-2">
                      {s.status !== "ended" ? (
                        <button
                          type="button"
                          onClick={() => toggle.mutate({ id: s.id, action: s.status === "active" ? "pause" : "resume" })}
                          className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-caption font-semibold text-foreground hover:bg-muted/40"
                        >
                          {s.status === "active" ? <><Pause className="size-3.5" /> Pause</> : <><Play className="size-3.5" /> Resume</>}
                        </button>
                      ) : null}
                      <button
                        type="button"
                        onClick={() =>
                          confirm({
                            title: `Delete this recurring invoice for ${s.contact_name}?`,
                            description: "Already-generated invoices stay.",
                            confirmLabel: "Delete",
                            onConfirm: () => remove.mutate(s.id),
                          })
                        }
                        className="text-caption font-semibold text-destructive hover:underline"
                      >
                        Delete
                      </button>
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
