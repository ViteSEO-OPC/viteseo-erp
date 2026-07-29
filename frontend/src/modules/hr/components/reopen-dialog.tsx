"use client";

import * as React from "react";
import { RotateCcw } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";

export interface ReopenDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** What is being reopened, e.g. "Maya's sick leave request". */
  subject: string;
  /**
   * Replaces the default "…goes back to the queue for a fresh decision" line, for undos
   * that aren't a queue at all — a reversed payslip, a loan resuming collection.
   */
  description?: React.ReactNode;
  /**
   * What reopening actually does here — credits returned, access re-locked, a claim
   * owed again. Each queue reverses something different, so the caller says so
   * rather than the dialog guessing.
   */
  consequence?: React.ReactNode;
  /** Runs the reopen. Throwing keeps the dialog open so the reason can be corrected. */
  onConfirm: (reason: string) => Promise<void>;
  confirmLabel?: string;
  title?: string;
}

/**
 * Confirm undoing a status mark. Mirrors ReviewDialog's centered layout so an
 * approve and its undo feel like the same control, in neutral tones — reopening
 * is a correction, not a destructive act.
 */
export function ReopenDialog({
  open,
  onOpenChange,
  subject,
  description,
  consequence,
  onConfirm,
  confirmLabel = "Reopen",
  title = "Undo this decision?",
}: ReopenDialogProps) {
  const [reason, setReason] = React.useState("");
  const [submitting, setSubmitting] = React.useState(false);

  // Snapshot the copy on open: callers drive `open` off the selected row, so
  // clearing that row on close would blank the text mid-exit-animation.
  const content = { subject, description, consequence, confirmLabel, title };
  const [snap, setSnap] = React.useState(content);
  const [wasOpen, setWasOpen] = React.useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setSnap(content);
      setReason("");
    }
  }

  async function confirm() {
    setSubmitting(true);
    try {
      await onConfirm(reason.trim());
      onOpenChange(false);
    } catch {
      // The caller surfaces the error; leave the dialog up so HR can retry.
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <div className="flex flex-col items-center text-center">
          <div className="flex size-14 items-center justify-center rounded-full bg-info-subtle">
            <RotateCcw className="size-7 text-info" />
          </div>

          <DialogTitle className="mt-4">{snap.title}</DialogTitle>
          <DialogDescription className="mt-1.5 text-sm">
            {snap.description ?? `${snap.subject} goes back to the queue for a fresh decision.`}
            {snap.consequence ? <span className="mt-1.5 block">{snap.consequence}</span> : null}
          </DialogDescription>
        </div>

        <div className="mt-4 space-y-1.5 text-left">
          <label className="text-sm font-medium text-foreground">
            Reason <span className="font-normal text-muted-foreground">(Optional)</span>
          </label>
          <textarea
            rows={3}
            autoFocus
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Why is this being reopened?"
            className="w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
          />
          <p className="text-caption text-muted-foreground">Recorded on the audit trail.</p>
        </div>

        <div className="mt-6 flex items-center justify-center gap-3">
          <Button disabled={submitting} onClick={confirm}>
            {submitting ? "Reopening…" : snap.confirmLabel}
          </Button>
          <DialogClose asChild>
            <Button variant="ghost">Cancel</Button>
          </DialogClose>
        </div>
      </DialogContent>
    </Dialog>
  );
}
