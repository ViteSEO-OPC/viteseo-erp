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
import { useAssignLead } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  lead: Lead;
};

/** Assign / reassign a lead to a BD (Admin/SA only). Render with key={lead.id} to reset state. */
export function AssignLeadModal({ open, onOpenChange, lead }: Props) {
  const { bdUsers, isLoading } = useBdUsers();
  const assignLead = useAssignLead();
  const [assignedTo, setAssignedTo] = useState<string>(lead.assignedToId ?? "");

  const handleAssign = () => {
    if (!assignedTo) return;
    assignLead.mutate(
      { id: lead.id, assignedTo },
      { onSuccess: () => onOpenChange(false) },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onOpenChange(false); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col p-0 sm:max-w-[420px]">
        <DialogHeader className="border-b border-border-subtle px-6 py-4">
          <div className="flex items-center justify-between">
            <DialogTitle className="text-lg font-bold text-card-foreground">
              {lead.assignedTo ? "Reassign lead" : "Assign lead"}
            </DialogTitle>
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <p className="text-sm text-muted-foreground">
            Assign <span className="font-semibold text-foreground">{lead.business}</span> to a
            Business Development Manager.
          </p>
          <FormField label="Business Development Manager" required>
            <RelationshipSelect
              value={assignedTo}
              onValueChange={setAssignedTo}
              options={bdUsers.map((u) => ({ value: u.id, label: u.name }))}
              placeholder={isLoading ? "Loading…" : "Select a BD"}
              // The lead already carries its owner's name, so the current BD resolves to a name even
              // if it falls outside the (paginated) options list — never a raw id.
              fallbackLabel={lead.assignedTo?.name}
              triggerClassName="w-full"
              aria-label="Business Development Manager"
            />
          </FormField>
        </div>

        <DialogFooter className="flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={assignLead.isPending}>
            Cancel
          </Button>
          <Button variant="default" size="sm" onClick={handleAssign} disabled={!assignedTo || assignLead.isPending}>
            {assignLead.isPending ? "Assigning…" : "Assign"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
