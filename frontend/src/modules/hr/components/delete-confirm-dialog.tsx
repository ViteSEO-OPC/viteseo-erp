"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";

/**
 * Entity names are often questions in their own right ("How do I file a leave
 * request?"), so only add our own "?" when the name doesn't already end one.
 */
function askDelete(name = "") {
  return `Are you sure you want to delete “${name}”${/[?!.]$/.test(name) ? "" : "?"}`;
}

export interface DeleteConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * Runs the deletion. Throwing keeps the dialog open — resolve only when the
   * delete succeeded.
   */
  onConfirm: () => void | Promise<void>;
  /** Header, e.g. "Delete FAQ". */
  title: string;
  /** Quoted back to the user in the default question. */
  entityName?: string;
  /** Overrides the question entirely, for verbs other than "delete". */
  question?: React.ReactNode;
  /** The consequence — what this permanently does. */
  description: React.ReactNode;
  confirmLabel?: string;
  isPending?: boolean;
}

/**
 * HR's destructive-confirmation modal. Deliberately HR-local: `core/ui` already
 * exposes a `ConfirmDialog` (used by Projects) and `modules/crm` has a clone of
 * its own, but both are outside this pod. Converging the three is a CTO call.
 */
export function DeleteConfirmDialog({
  open,
  onOpenChange,
  onConfirm,
  title,
  entityName,
  question,
  description,
  confirmLabel = "Delete",
  isPending = false,
}: DeleteConfirmDialogProps) {
  const [busy, setBusy] = React.useState(false);

  // Snapshot the copy when the dialog opens. Callers drive `open` off the row
  // being deleted (`open={!!deleting}`), so clearing that row on close would
  // blank the text mid-exit-animation. Adjusting state during render is React's
  // recommended pattern here.
  const content = { title, entityName, question, description, confirmLabel };
  const [snap, setSnap] = React.useState(content);
  const [wasOpen, setWasOpen] = React.useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) setSnap(content);
  }

  const pending = isPending || busy;

  async function handleConfirm() {
    setBusy(true);
    try {
      await onConfirm();
      onOpenChange(false);
    } catch {
      // The caller surfaces the error; leave the dialog up so the user can retry.
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[440px] gap-0 p-0">
        <DialogHeader className="mb-0 px-7 pt-6 pb-5">
          {/* Sizes restated because cn()'s tailwind-merge runs on its default
              config and strips the project's custom text-* scale. */}
          <DialogTitle className="text-[length:var(--text-h3)] font-bold text-danger">
            {snap.title}
          </DialogTitle>
        </DialogHeader>

        <div className="h-px bg-border" />

        <div className="flex items-start gap-3.5 px-7 py-6">
          <span
            aria-hidden
            className="flex size-10 shrink-0 items-center justify-center rounded-full bg-danger-subtle text-[length:var(--text-h3)] font-bold text-danger"
          >
            !
          </span>
          <div className="min-w-px flex-1 space-y-2">
            <p className="text-[length:var(--text-caption)] font-semibold text-foreground">
              {snap.question ?? askDelete(snap.entityName)}
            </p>
            <DialogDescription className="text-[length:var(--text-label)]">
              {snap.description}
            </DialogDescription>
          </div>
        </div>

        <div className="h-px bg-border" />

        <DialogFooter className="mt-0 gap-3 px-7 py-4.5">
          <DialogClose asChild>
            <Button
              type="button"
              variant="ghost"
              size="lg"
              className="font-bold text-muted-foreground"
              disabled={pending}
            >
              Cancel
            </Button>
          </DialogClose>
          <Button
            type="button"
            size="lg"
            onClick={handleConfirm}
            disabled={pending}
            className={cn("bg-danger px-6 font-bold text-white hover:bg-danger/90")}
          >
            {pending ? "Deleting…" : snap.confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
