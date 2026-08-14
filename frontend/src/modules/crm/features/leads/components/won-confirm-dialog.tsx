"use client";

import { useState } from "react";
import { X, Check, Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { notify as toast } from "@/core/lib/notify";
import { ALREADY_WON_MESSAGE, useMarkWon } from "@/modules/crm/features/leads/hooks/use-lead-outcome";

const FIELD =
  "h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-teal-400 focus:outline-none focus:ring-2 focus:ring-teal-400/30";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leadId: string;
  leadName: string;
  /** Estimated Deal Value (leads.amount) — pre-fills the editable deal amount. */
  defaultAmount: number | null;
  /**
   * The lead already has a Deal. Unlike Lost, confirming does NOT submit: one active Deal per lead
   * is a financial invariant (Revenue = Σ deals.amount), and the backend would 409 anyway.
   */
  alreadyWon: boolean;
};

/**
 * Mark as Won — confirmation dialog. The amount defaults to the lead's estimate (leads.amount) and
 * is editable before confirming. Confirming creates a Deal (backend); Revenue/Conversion are sourced
 * only from Deals and update server-side.
 */
export function WonConfirmDialog({
  open,
  onOpenChange,
  leadId,
  leadName,
  defaultAmount,
  alreadyWon,
}: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[440px]">
        {open && (
          <WonForm
            leadId={leadId}
            leadName={leadName}
            defaultAmount={defaultAmount}
            alreadyWon={alreadyWon}
            onClose={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function WonForm({
  leadId,
  leadName,
  defaultAmount,
  alreadyWon,
  onClose,
}: {
  leadId: string;
  leadName: string;
  defaultAmount: number | null;
  alreadyWon: boolean;
  onClose: () => void;
}) {
  const markWon = useMarkWon();
  const [amount, setAmount] = useState(defaultAmount != null ? String(defaultAmount) : "");

  function handleConfirm() {
    // No second Deal can be created, so skip the round-trip that would only come back 409.
    // useMarkWon keeps that 409 branch as the backstop for what this cached flag cannot see.
    if (alreadyWon) {
      toast.info(ALREADY_WON_MESSAGE);
      onClose();
      return;
    }
    const parsed = amount.trim() === "" ? null : Number(amount);
    markWon.mutate({ id: leadId, amount: parsed }, { onSuccess: onClose });
  }

  return (
    <>
      <DialogHeader className="px-6 pb-0 pt-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1">
            <DialogTitle className="text-xl font-bold text-card-foreground">Mark as Won</DialogTitle>
            <DialogDescription>
              Create a deal for {leadName}. This records realized revenue.
            </DialogDescription>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            aria-label="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </DialogHeader>

      <div className="px-6 py-4">
        {/* Unlike Lost, this one IS a dead end — one Deal per lead — so it states the outcome of
            confirming rather than merely noting the existing state. */}
        {alreadyWon ? (
          <p className="mb-3 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
            This lead already has a deal. No second deal will be created.
          </p>
        ) : null}
        <FormField label="Deal Amount (₱)">
          <input
            type="number"
            min="0"
            inputMode="numeric"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            className={FIELD}
            placeholder="Deal value (₱)"
            aria-label="Deal amount"
            autoFocus
          />
        </FormField>
      </div>

      <DialogFooter className="mx-0 mb-0 flex-row items-center justify-end gap-2.5 border-t-0 bg-transparent px-6 pb-6 pt-2">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={markWon.isPending}>Cancel</Button>
        <Button variant="default" size="sm" onClick={handleConfirm} disabled={markWon.isPending}>
          {markWon.isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
          ) : (
            <Check className="h-4 w-4" aria-hidden />
          )}
          Confirm Won
        </Button>
      </DialogFooter>
    </>
  );
}
