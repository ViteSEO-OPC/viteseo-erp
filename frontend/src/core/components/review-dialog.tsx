"use client";

import { useState } from "react";
import { Check, X } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * Confirm an approve/decline, in the shared centered style: a tinted status
 * icon, centered copy, and a colored primary action beside a plain Cancel.
 * Approve is a plain confirmation; decline asks for a reason. `onConfirm`
 * receives the reason (empty string for approve, or when no reason is given).
 *
 * `reasonRequired` (default true) gates the decline button on a non-empty
 * reason — set it false for endpoints that accept an empty reason.
 */
export function ReviewDialog({
  open,
  onOpenChange,
  mode,
  subject,
  onConfirm,
  reasonRequired = true,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  mode: "approve" | "reject";
  subject: string;
  onConfirm: (reason: string) => Promise<void>;
  reasonRequired?: boolean;
}) {
  const isReject = mode === "reject";
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);

  // Reset whenever the dialog opens — during render (React's recommended pattern) rather
  // than in an effect. Starts false so a dialog mounted already-open resets too.
  const [wasOpen, setWasOpen] = useState(false);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setReason("");
    }
  }

  const missingReason = isReject && reasonRequired && !reason.trim();

  async function confirm() {
    if (missingReason) return;
    setSubmitting(true);
    try {
      await onConfirm(reason.trim());
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <div className="flex flex-col items-center text-center">
          <div
            className={cn(
              "flex size-14 items-center justify-center rounded-full",
              isReject ? "bg-danger-subtle" : "bg-success-subtle",
            )}
          >
            {isReject ? (
              <X className="size-7 text-danger" />
            ) : (
              <Check className="size-7 text-success" />
            )}
          </div>

          <DialogTitle className="mt-4">
            {isReject ? "Reject Request" : "Approve Request"}
          </DialogTitle>
          <DialogDescription className="mt-1.5 text-sm">
            {isReject
              ? `Please provide a reason for rejecting ${subject}.`
              : `Are you sure you want to approve ${subject}? This action cannot be undone.`}
          </DialogDescription>
        </div>

        {isReject && (
          <div className="mt-4 space-y-1.5 text-left">
            <label className="text-sm font-medium text-foreground">
              Reason for rejection{" "}
              {reasonRequired ? (
                <span className="text-destructive">*</span>
              ) : (
                <span className="font-normal text-muted-foreground">(Optional)</span>
              )}
            </label>
            <textarea
              rows={4}
              autoFocus
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Enter reason here…"
              className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </div>
        )}

        <div className="mt-6 flex items-center justify-center gap-3">
          {isReject ? (
            <Button
              variant="destructive"
              className="bg-danger text-white hover:bg-danger/90"
              disabled={submitting || missingReason}
              onClick={confirm}
            >
              {submitting ? "Saving…" : "Reject request"}
            </Button>
          ) : (
            <Button variant="success" disabled={submitting} onClick={confirm}>
              {submitting ? "Saving…" : "Approve Request"}
            </Button>
          )}
          <DialogClose asChild>
            <Button variant="ghost">Cancel</Button>
          </DialogClose>
        </div>
      </DialogContent>
    </Dialog>
  );
}
