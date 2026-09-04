"use client";

import { useState } from "react";
import { X } from "lucide-react";

import { notify as toast } from "@/core/lib/notify";
import type { TeamMember } from "@/core/team/use-team-accounts";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { useUpdateUser } from "@/modules/crm/features/settings/hooks/use-user-mutations";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  member: TeamMember;
};

/**
 * Correct an account's name. That is the whole dialog, and the shrinkage is the point.
 *
 * It used to carry a CRM-access picker and a status select as well, and both had to
 * go when the members list moved to /crm/team:
 *
 *  - ROLE. This dialog's picker ran on PATCH /users, which validates against the
 *    caller's assignable ROLES and cannot express one of the org's own. A member
 *    holding a custom role therefore read as "No CRM access" here, and a stray Save
 *    stripped them — the exact failure the merge exists to end. The row's picker
 *    speaks /crm/team, which accepts and reports `custom:<id>`, so it is now the
 *    single answer to "what role does this person hold".
 *  - STATUS. Suspending is a decision with a warning attached, not a select the
 *    operator tabs past. It is an explicit action in the row menu.
 *
 * Render with key={member.id} so state initialises fresh per account.
 */
export function RenameAccountModal({ open, onOpenChange, member }: Props) {
  const [name, setName] = useState(member.name);
  const updateUser = useUpdateUser();

  const handleSave = () => {
    // `name` alone: PATCH /users leaves every key it is not sent untouched, which is
    // what keeps a rename from writing a role or an access state as a side effect.
    updateUser.mutate(
      { id: String(member.id), payload: { name: name.trim() } },
      {
        onSuccess: () => {
          toast.success("Account updated");
          onOpenChange(false);
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onOpenChange(false); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[420px]">
        <DialogHeader className="border-b border-border-subtle px-6 py-4">
          <div className="flex items-center justify-between">
            <DialogTitle className="text-lg font-bold text-card-foreground">Rename account</DialogTitle>
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <FormField label="Name" required>
            <input
              type="text"
              className={FIELD}
              value={name}
              onChange={(e) => setName(e.target.value)}
              aria-required="true"
            />
          </FormField>
          {/* The email IS the central Vite SEO Systems identity — the same reason it is
              read-only on the operator's own profile. */}
          <FormField label="Email">
            <input type="email" className={cn(FIELD, "opacity-70")} value={member.email} readOnly />
          </FormField>
          <p className="text-xs text-muted-foreground">
            This renames their one Vite SEO Systems account, so the new name shows in
            TeamOra and Sprintly too.
          </p>
        </div>

        <DialogFooter className="mx-0 mb-0 flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={updateUser.isPending}>
            Cancel
          </Button>
          <Button
            variant="default"
            size="sm"
            onClick={handleSave}
            disabled={updateUser.isPending || name.trim() === "" || name.trim() === member.name}
          >
            {updateUser.isPending ? "Saving…" : "Save changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
