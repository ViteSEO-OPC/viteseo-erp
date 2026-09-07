"use client";

import { Plus, Trash2 } from "lucide-react";

import { formatPeso } from "@/core/lib/format";
import type { Account } from "@/modules/accounting/types";

export interface LineDraft {
  description: string;
  quantity: string;
  unit_price: string;
  account_id: string;
}

export function emptyLine(): LineDraft {
  return { description: "", quantity: "1", unit_price: "", account_id: "" };
}

export function lineAmount(l: LineDraft): number {
  const q = Number.parseFloat(l.quantity || "0");
  const p = Number.parseFloat(l.unit_price || "0");
  const v = q * p;
  return Number.isFinite(v) ? v : 0;
}

const cell = "h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm outline-none focus-visible:border-ring";

export function LineItemsEditor({
  accounts,
  accountLabel,
  lines,
  setLines,
  taxRate,
  setTaxRate,
}: {
  accounts: Account[];
  accountLabel: string;
  lines: LineDraft[];
  setLines: (lines: LineDraft[]) => void;
  taxRate: string;
  setTaxRate: (v: string) => void;
}) {
  const patch = (i: number, p: Partial<LineDraft>) =>
    setLines(lines.map((l, idx) => (idx === i ? { ...l, ...p } : l)));
  const removeAt = (i: number) =>
    setLines(lines.length > 1 ? lines.filter((_, idx) => idx !== i) : lines);
  const add = () => setLines([...lines, emptyLine()]);

  const subtotal = lines.reduce((s, l) => s + lineAmount(l), 0);
  const rate = Number.parseFloat(taxRate || "0") || 0;
  const vat = (subtotal * rate) / 100;
  const total = subtotal + vat;

  return (
    <div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="border-b border-border text-label uppercase tracking-wide text-muted-foreground">
              <th className="py-2 pr-2 text-left font-bold">Description</th>
              <th className="py-2 px-2 text-right font-bold">Qty</th>
              <th className="py-2 px-2 text-right font-bold">Unit price</th>
              <th className="py-2 px-2 text-left font-bold">{accountLabel}</th>
              <th className="py-2 px-2 text-right font-bold">Amount</th>
              <th className="py-2 pl-2" />
            </tr>
          </thead>
          <tbody>
            {lines.map((l, i) => (
              <tr key={i} className="border-b border-border last:border-0">
                <td className="py-2 pr-2">
                  <input value={l.description} onChange={(e) => patch(i, { description: e.target.value })} placeholder="Item" className={cell} />
                </td>
                <td className="py-2 px-2 w-20">
                  <input type="number" min="0" step="1" value={l.quantity} onChange={(e) => patch(i, { quantity: e.target.value })} className={`${cell} text-right`} />
                </td>
                <td className="py-2 px-2 w-32">
                  <input type="number" min="0" step="0.01" value={l.unit_price} onChange={(e) => patch(i, { unit_price: e.target.value })} placeholder="0.00" className={`${cell} text-right`} />
                </td>
                <td className="py-2 px-2 w-48">
                  <select value={l.account_id} onChange={(e) => patch(i, { account_id: e.target.value })} className={cell}>
                    <option value="">Select…</option>
                    {accounts.map((a) => (
                      <option key={a.id} value={a.id}>{a.code} · {a.name}</option>
                    ))}
                  </select>
                </td>
                <td className="py-2 px-2 text-right tabular-nums">{formatPeso(lineAmount(l))}</td>
                <td className="py-2 pl-2 text-right">
                  <button type="button" onClick={() => removeAt(i)} className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive" aria-label="Remove line">
                    <Trash2 className="size-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <button type="button" onClick={add} className="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-brand hover:opacity-80">
        <Plus className="size-4" /> Add line
      </button>

      <div className="mt-4 ml-auto max-w-xs space-y-1.5 text-sm">
        <div className="flex items-center justify-between">
          <span className="text-muted-foreground">Subtotal</span>
          <span className="tabular-nums">{formatPeso(subtotal)}</span>
        </div>
        <div className="flex items-center justify-between gap-3">
          <span className="flex items-center gap-1.5 text-muted-foreground">
            VAT
            <input type="number" min="0" max="100" step="0.01" value={taxRate} onChange={(e) => setTaxRate(e.target.value)} className="h-7 w-14 rounded-md border border-input bg-transparent px-1.5 text-right text-sm outline-none focus-visible:border-ring" />
            %
          </span>
          <span className="tabular-nums">{formatPeso(vat)}</span>
        </div>
        <div className="flex items-center justify-between border-t border-border pt-1.5 font-semibold">
          <span>Total</span>
          <span className="tabular-nums">{formatPeso(total)}</span>
        </div>
      </div>
    </div>
  );
}
