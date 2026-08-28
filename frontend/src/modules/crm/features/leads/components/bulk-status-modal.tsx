"use client";

import { useState } from "react";
import { X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { EDITABLE_STATUS_OPTIONS } from "@/modules/crm/lib/lead-status";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** How many selected leads will take the chosen status. */
  count: number;
  /** Fires with the chosen status once confirmed — the page owns the mutation and the selection. */
  onConfirm: (status: string) => void;
  /** True while the fan-out is in flight; locks the picker and both buttons. */
  pending: boolean;
};

/**
 * Pick ONE status for a bulk change. Built from the same primitives as
 * {@link BulkAssignBdModal} — Dialog + FormField + a picker + a confirm footer — so the two bulk
 * dialogs cannot drift apart, and from the same `Select` + `StatusChip` pair the Lead Detail
 * quick-change already uses, so a status looks identical wherever it is chosen.
 *
 * Options come from the frozen `EDITABLE_STATUS_OPTIONS`; nothing here invents or reorders a status.
 * The page mounts this only while open, so the picker starts empty on every run without needing a
 * `key` — no state carries over from a previous selection.
 */
export function BulkStatusModal({ open, onOpenChange, count, onConfirm, pending }: Props) {
  const [status, setStatus] = useState<string>("");

  const close = () => {
    if (pending) return; // never drop the dialog while the fan-out is still running
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) close(); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[420px]">
        <DialogHeader className="border-b border-border-subtle px-6 py-4">
          <div className="flex items-center justify-between">
            <DialogTitle className="text-lg font-bold text-card-foreground">
              Change status on {count} lead{count === 1 ? "" : "s"}
            </DialogTitle>
            <button type="button" onClick={close} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <p className="text-sm text-muted-foreground">
            Set the{" "}
            <span className="font-semibold text-foreground">
              {count} selected lead{count === 1 ? "" : "s"}
            </span>{" "}
            to one status. This replaces their current status.
          </p>
          <FormField label="Status" required>
            <Select value={status} onValueChange={(v) => v && setStatus(v)} disabled={pending}>
              <SelectTrigger className="w-full" aria-label="Status">
                {status ? <StatusChip status={status as LeadStatus} /> : "Select a status"}
              </SelectTrigger>
              <SelectContent>
                {EDITABLE_STATUS_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormField>
        </div>

        <DialogFooter className="mx-0 mb-0 flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <Button variant="outline" size="sm" onClick={close} disabled={pending}>
            Cancel
          </Button>
          <Button
            variant="default"
            size="sm"
            onClick={() => status && onConfirm(status)}
            disabled={!status || pending}
          >
            {pending ? "Updating…" : "Change status"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
