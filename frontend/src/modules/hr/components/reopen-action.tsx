"use client";

import * as React from "react";
import { AxiosError } from "axios";
import { RotateCcw } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { notify as toast } from "@/core/lib/notify";
import { ReopenDialog } from "@/modules/hr/components/reopen-dialog";

/**
 * The row-level "undo this mark" control: a quiet Reopen button that opens the
 * confirmation, then reports the outcome.
 *
 * Every queue reverses something different but fails the same way — the API refuses
 * with a 422 whose message explains why (a locked payroll run, a payslip already out
 * the door). Surfacing that message verbatim is the whole point: a generic "could not
 * reopen" would hide the one thing HR needs to know to get unstuck.
 */
export function ReopenAction<T>({
  subject,
  description,
  consequence,
  onReopen,
  label = "Reopen",
  successMessage = "Reopened",
  size = "sm",
  className,
  title,
  confirmLabel,
}: {
  subject: string;
  /** Replaces the default "back to the queue" line — see ReopenDialog. */
  description?: React.ReactNode;
  consequence?: React.ReactNode;
  /** Resolve on success; throw to keep the dialog open. */
  onReopen: (reason: string) => Promise<T>;
  label?: string;
  /**
   * Overridden where "Reopened" is the wrong word, e.g. reversing a payment. A function
   * gets the API result, for outcomes only the server knows — whether reopening a change
   * request could still roll the old value back, say.
   */
  // NoInfer: T comes from onReopen alone, so this callback's argument is typed rather
  // than being an inference candidate that collapses T to unknown.
  successMessage?: string | ((result: NoInfer<T>) => string);
  size?: "xs" | "sm";
  className?: string;
  title?: string;
  confirmLabel?: string;
}) {
  const [open, setOpen] = React.useState(false);

  async function confirm(reason: string) {
    try {
      const result = await onReopen(reason);
      toast.success(
        typeof successMessage === "function" ? successMessage(result) : successMessage,
      );
    } catch (err) {
      const message =
        err instanceof AxiosError && err.response?.status === 422
          ? (err.response.data?.errors?.status?.[0] ??
            err.response.data?.message ??
            null)
          : null;
      toast.error(message ?? "Could not reopen this. Please try again.");
      throw err; // keeps the dialog open so the message stays in context
    }
  }

  return (
    <>
      <Button
        type="button"
        size={size}
        variant="outline"
        className={cn("text-muted-foreground", className)}
        onClick={(e) => {
          e.stopPropagation(); // rows open a detail modal on click
          setOpen(true);
        }}
      >
        <RotateCcw />
        {label}
      </Button>

      <ReopenDialog
        open={open}
        onOpenChange={setOpen}
        subject={subject}
        description={description}
        consequence={consequence}
        onConfirm={confirm}
        title={title}
        confirmLabel={confirmLabel}
      />
    </>
  );
}
