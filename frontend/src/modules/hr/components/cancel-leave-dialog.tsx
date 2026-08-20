"use client";

import { useEffect, useState } from "react";
import { Ban } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * Confirm cancelling a leave request, asking for a required reason. Mirrors the
 * shared ReviewDialog's centered style. `onConfirm` receives the trimmed reason;
 * the dialog stays open if it throws so the user can retry.
 */
export function CancelLeaveDialog({
  open,
  onOpenChange,
  subject,
  title = "Cancel Leave Request",
  onConfirm,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** What's being cancelled, e.g. "Annual Leave · Jul 1 – Jul 3". */
  subject?: string;
  /** Heading — overridable so overtime can reuse this same flow. */
  title?: string;
  onConfirm: (reason: string) => Promise<void>;
}) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (open) setReason("");
  }, [open]);

  const missingReason = !reason.trim();

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
          <div className="flex size-14 items-center justify-center rounded-full bg-danger-subtle">
            <Ban className="size-7 text-danger" />
          </div>

          <DialogTitle className="mt-4">{title}</DialogTitle>
          <DialogDescription className="mt-1.5 text-sm">
            {subject
              ? `Please tell us why you're cancelling ${subject}.`
              : "Please provide a reason for cancelling this request."}
          </DialogDescription>
        </div>

        <div className="mt-4 space-y-1.5 text-left">
          <label className="text-sm font-medium text-foreground">
            Reason for cancellation <span className="text-destructive">*</span>
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

        <div className="mt-6 flex items-center justify-center gap-3">
          <Button
            variant="destructive"
            className="bg-danger text-white hover:bg-danger/90"
            disabled={submitting || missingReason}
            onClick={confirm}
          >
            {submitting ? "Cancelling…" : "Cancel request"}
          </Button>
          <DialogClose asChild>
            <Button variant="ghost">Keep request</Button>
          </DialogClose>
        </div>
      </DialogContent>
    </Dialog>
  );
}
