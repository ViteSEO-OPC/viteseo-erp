"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import {
  useCreateOfficeExpense,
  useUpdateOfficeExpense,
} from "@/modules/hr/hooks/use-office-expenses";
import {
  EXPENSE_CATEGORIES,
  type OfficeExpense,
} from "@/modules/hr/types/office-expense";

const selectClass =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";

export function OfficeExpenseFormDialog({
  open,
  onOpenChange,
  expense,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  expense?: OfficeExpense | null;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto">
        {/* Keyed so state re-initialises fresh each time it opens (no effects). */}
        {open && <FormBody key={expense?.id ?? "new"} expense={expense ?? null} onDone={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function FormBody({ expense, onDone }: { expense: OfficeExpense | null; onDone: () => void }) {
  const isEdit = !!expense;
  const createMut = useCreateOfficeExpense();
  const updateMut = useUpdateOfficeExpense();

  const [form, setForm] = useState(() => ({
    category: expense?.category ?? "supplies",
    amount: expense ? String(expense.amount) : "",
    expense_date: expense?.expense_date ?? "",
    vendor: expense?.vendor ?? "",
    description: expense?.description ?? "",
  }));
  const [receipt, setReceipt] = useState<File | null>(null);

  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
  const pending = createMut.isPending || updateMut.isPending;

  async function submit() {
    if (!form.amount || !form.expense_date || !form.description.trim()) return;
    const input = {
      category: form.category,
      amount: Number(form.amount),
      expense_date: form.expense_date,
      description: form.description.trim(),
      vendor: form.vendor.trim(),
    };
    try {
      if (expense) {
        await updateMut.mutateAsync({ id: expense.id, input, receipt });
        toast.success("Expense updated");
      } else {
        await createMut.mutateAsync({ input, receipt });
        toast.success("Expense recorded");
      }
      onDone();
    } catch {
      toast.error("Could not save the expense.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isEdit ? "Edit expense" : "Record office expense"}</DialogTitle>
        <DialogDescription>Company/office spending — attach a receipt if you have one.</DialogDescription>
      </DialogHeader>

      <div className="space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="oe-cat">Category</Label>
            <select id="oe-cat" className={selectClass} value={form.category} onChange={(e) => set("category", e.target.value)}>
              {EXPENSE_CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="oe-amt">Amount (₱)</Label>
            <Input id="oe-amt" type="number" min="0" step="0.01" value={form.amount} onChange={(e) => set("amount", e.target.value)} />
          </div>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="oe-date">Date</Label>
            <Input id="oe-date" type="date" value={form.expense_date} onChange={(e) => set("expense_date", e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="oe-vendor">Vendor</Label>
            <Input id="oe-vendor" value={form.vendor} onChange={(e) => set("vendor", e.target.value)} placeholder="Optional" />
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="oe-desc">Description</Label>
          <textarea
            id="oe-desc"
            rows={2}
            value={form.description}
            onChange={(e) => set("description", e.target.value)}
            className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="oe-receipt">Receipt (optional)</Label>
          <Input id="oe-receipt" type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => setReceipt(e.target.files?.[0] ?? null)} />
        </div>
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button disabled={pending} onClick={submit}>
          {pending ? "Saving…" : isEdit ? "Save changes" : "Record expense"}
        </Button>
      </DialogFooter>
    </>
  );
}
