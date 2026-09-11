"use client";

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";

type ConfirmDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  onConfirm: () => void;
  loading?: boolean;
  destructive?: boolean;
  /**
   * Extra content between the description and the buttons — a scope summary, an affected count, an
   * acknowledgement the user has to tick. Optional, so every existing caller renders unchanged.
   */
  children?: React.ReactNode;
  /** Withhold the confirm button until the caller says the user has done enough to mean it. */
  confirmDisabled?: boolean;
};

/**
 * Reusable confirmation dialog built on the existing Dialog primitive + design system.
 * Used for destructive actions (e.g. Delete Lead).
 *
 * `children` and `confirmDisabled` exist so an unusually broad action can raise the bar WITHOUT a
 * second confirmation component: "Archive all leads" uses them to state the scope and to require an
 * explicit acknowledgement before Confirm becomes clickable. Both are optional and default to the
 * previous behaviour, so every existing caller is untouched.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  onConfirm,
  loading = false,
  destructive = false,
  children,
  confirmDisabled = false,
}: ConfirmDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[400px]">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description ? (
            <DialogDescription>{description}</DialogDescription>
          ) : null}
        </DialogHeader>
        {children}
        <DialogFooter>
          <Button
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={loading}
          >
            {cancelLabel}
          </Button>
          <Button
            variant={destructive ? "destructive" : "default"}
            size="sm"
            onClick={onConfirm}
            disabled={loading || confirmDisabled}
          >
            {loading ? "Working…" : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
