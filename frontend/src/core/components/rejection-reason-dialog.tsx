"use client";

import { Ban } from "lucide-react";

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
 * Shows HR's reason for declining a request. Open it from a rejected row in any
 * employee-facing request list. `reason` is the request's `review_note`.
 */
export function RejectionReasonDialog({
  open,
  onOpenChange,
  title = "Request declined",
  subject,
  reason,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Dialog heading, e.g. "Leave declined". */
  title?: string;
  /** Optional line naming what was declined (e.g. "Annual Leave · Jul 1 – Jul 3"). */
  subject?: string;
  reason: string | null;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex flex-col gap-4">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2.5">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-danger-subtle text-danger">
              <Ban className="size-4" />
            </span>
            {title}
          </DialogTitle>
          <DialogDescription>
            {subject ? `${subject} — HR's reason:` : "HR provided the following reason."}
          </DialogDescription>
        </DialogHeader>

        <p className="whitespace-pre-wrap rounded-lg border border-border bg-muted/40 px-3.5 py-3 text-sm text-foreground">
          {reason?.trim() || "No reason was provided."}
        </p>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Close</Button>
          </DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
