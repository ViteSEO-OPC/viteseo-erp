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
import { Button } from "@/modules/crm/components/ui/button";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { RelationshipSelect } from "@/modules/crm/components/shared/relationship-select/relationship-select";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** How many selected leads the chosen BD will take ownership of. */
  count: number;
  /** Fires with the chosen BD once the user confirms — the page owns the mutation and the selection. */
  onConfirm: (bdId: string, bdName: string) => void;
  /** True while the fan-out is in flight; locks the picker and both buttons. */
  pending: boolean;
};

/**
 * Pick ONE Business Development Manager for a bulk assignment (Admin/SA only — the Assign BD
 * action that opens it is gated by the same `canAssign` the single-lead flow uses).
 *
 * The single-lead {@link AssignLeadModal} is deliberately not reused: it is bound to one `Lead`,
 * pre-selects that lead's current owner and owns its own mutation, none of which is meaningful for a
 * multi-lead selection with many different owners. Both dialogs are built from the SAME primitives
 * (Dialog + FormField + RelationshipSelect) and the same {@link useBdUsers} source, so the BD list
 * can never disagree between them.
 *
 * The page mounts this only while open, so `assignedTo` starts empty on every run without needing a
 * `key` — no state carries over from a previous selection.
 */
export function BulkAssignBdModal({ open, onOpenChange, count, onConfirm, pending }: Props) {
  const { bdUsers, isLoading } = useBdUsers();
  const [assignedTo, setAssignedTo] = useState<string>("");

  const handleAssign = () => {
    const bd = bdUsers.find((user) => user.id === assignedTo);
    if (!bd) return;
    onConfirm(bd.id, bd.name);
  };

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
              Assign {count} lead{count === 1 ? "" : "s"}
            </DialogTitle>
            <button type="button" onClick={close} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <p className="text-sm text-muted-foreground">
            Assign the{" "}
            <span className="font-semibold text-foreground">
              {count} selected lead{count === 1 ? "" : "s"}
            </span>{" "}
            to a Business Development Manager. This replaces any current owner.
          </p>
          <FormField label="Business Development Manager" required>
            <RelationshipSelect
              value={assignedTo}
              onValueChange={setAssignedTo}
              options={bdUsers.map((u) => ({ value: u.id, label: u.name }))}
              placeholder={isLoading ? "Loading…" : "Select a BD"}
              disabled={pending}
              triggerClassName="w-full"
              aria-label="Business Development Manager"
              emptyLabel="No active BD accounts"
            />
          </FormField>
        </div>

        <DialogFooter className="mx-0 mb-0 flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <Button variant="outline" size="sm" onClick={close} disabled={pending}>
            Cancel
          </Button>
          <Button variant="default" size="sm" onClick={handleAssign} disabled={!assignedTo || pending}>
            {pending ? "Assigning…" : "Assign"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
