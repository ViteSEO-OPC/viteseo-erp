"use client";

import { AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * The second step of a back-dated pay change. The server refused the date because it reaches
 * into payroll that has already run, and its message names those periods — so HR confirms
 * knowing exactly what they are repricing, then the caller resends with
 * `acknowledge_repricing: true`.
 *
 * Open while `message` is set.
 *
 * `title`, `note` and `confirmLabel` default to the back-dated pay change this was written for.
 * A caller whose refusal is a different KIND of repricing overrides them — a day-rule override
 * reaches one date for everyone and is settled by re-running the period, not by an adjustment
 * run, and telling HR otherwise would send them to the wrong screen.
 */
export function RepricingConfirmDialog({
  message,
  pending,
  onCancel,
  onConfirm,
  title = "This period was already paid",
  note = "Recording it is fine, but the difference is not paid automatically. Settle it with an adjustment run in Payroll.",
  confirmLabel = "Record it anyway",
}: {
  message: string | null;
  pending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  title?: string;
  note?: string;
  confirmLabel?: string;
}) {
  return (
    <Dialog open={message !== null} onOpenChange={(o) => !o && onCancel()}>
      <DialogContent className="max-w-[calc(100%-3rem)] sm:max-w-[460px]">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
        </DialogHeader>
        <div className="flex items-start gap-2 rounded-lg border border-warning bg-warning-subtle p-3">
          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden />
          <p className="text-xs text-foreground">{message}</p>
        </div>
        <p className="text-xs text-muted-foreground">{note}</p>
        <DialogFooter>
          <Button type="button" variant="ghost" onClick={onCancel} disabled={pending}>
            Pick another date
          </Button>
          <Button type="button" onClick={onConfirm} disabled={pending}>
            {pending ? "Saving…" : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
