"use client";

import { useState } from "react";
import { X, Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { useMarkLost } from "@/modules/crm/features/leads/hooks/use-lead-outcome";

// Value-equals-label (SelectValue renders the raw value). Labels map to the backend `reason` enum;
// "No reason" sends nothing (reason is optional and stored in the lead.lost Activity properties).
const REASONS = ["No reason", "Competitor", "Budget", "No Response", "Not Interested", "Other"] as const;
const REASON_VALUE: Record<string, string | null> = {
  "No reason": null,
  Competitor: "competitor",
  Budget: "budget",
  "No Response": "no-response",
  "Not Interested": "not-interested",
  Other: "other",
};

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leadId: string;
  leadName: string;
};

/**
 * Mark as Lost — confirmation dialog with an optional reason. Confirming logs a `lead.lost` Activity
 * (reason stored in its properties) and completes open follow-ups. It creates NO Deal and does not
 * affect Revenue, Conversion, or the Lead Score (the deterministic scoring pipeline is left intact).
 */
export function LostConfirmDialog({ open, onOpenChange, leadId, leadName }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[440px]">
        {open && <LostForm leadId={leadId} leadName={leadName} onClose={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function LostForm({ leadId, leadName, onClose }: { leadId: string; leadName: string; onClose: () => void }) {
  const markLost = useMarkLost();
  const [reason, setReason] = useState<string>("No reason");

  function handleConfirm() {
    markLost.mutate({ id: leadId, reason: REASON_VALUE[reason] ?? null }, { onSuccess: onClose });
  }

  return (
    <>
      <DialogHeader className="px-6 pb-0 pt-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1">
            <DialogTitle className="text-xl font-bold text-card-foreground">Mark as Lost</DialogTitle>
            <DialogDescription>
              Mark {leadName} as a lost opportunity. No deal is created.
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
        <FormField label="Reason" optional>
          <Select value={reason} onValueChange={(v) => setReason(v ?? reason)}>
            <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              {REASONS.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
            </SelectContent>
          </Select>
        </FormField>
      </div>

      <DialogFooter className="mx-0 mb-0 flex-row items-center justify-end gap-2.5 border-t-0 bg-transparent px-6 pb-6 pt-2">
        <Button variant="ghost" size="sm" onClick={onClose} disabled={markLost.isPending}>Cancel</Button>
        <Button variant="destructive" size="sm" onClick={handleConfirm} disabled={markLost.isPending}>
          {markLost.isPending ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
          Confirm Lost
        </Button>
      </DialogFooter>
    </>
  );
}
