"use client";

import { useState } from "react";
import { X } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
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
import { ROLE_LABELS, isRole, type Role } from "@/modules/crm/lib/rbac";
import { FIELD, SELECT } from "@/modules/crm/features/settings/components/form-styles";
import type { TeamUser, TeamUserStatus } from "@/modules/crm/features/settings/types/user-types";
import { cn } from "@/modules/crm/lib/utils";
import { SelectShell } from "@/modules/crm/components/shared/select-shell/select-shell";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: TeamUser;
  assignableRoles: Role[];
};

/** Sentinel for the "no CRM access" option — `<option>` values are always strings. */
const NO_CRM_ACCESS = "";

/** Render with key={user.id} so state initializes fresh per account. */
export function EditAccountModal({ open, onOpenChange, user, assignableRoles }: Props) {
  // The account's CRM access as it stands right now. A user with no CRM role reads as `null` —
  // "No CRM access" — and NEVER as an assignable role. Pre-selecting `assignableRoles[0]` used to
  // put "Admin" in the box for any HRIS/Projects-only colleague a Super Admin opened, so a stray
  // Save silently promoted them. Existing CRM users keep exactly the role they already hold.
  const currentRole: Role | null = user.role && isRole(user.role) ? user.role : null;

  const [name, setName] = useState(user.name);
  const [role, setRole] = useState<Role | null>(currentRole);
  const [status, setStatus] = useState<TeamUserStatus>(user.status);
  const updateUser = useUpdateUser();

  // CRM access is an explicit three-state choice: none, or one of the roles this admin may assign.
  const roleChanged = role !== currentRole;

  const handleSave = () => {
    // `role` is sent ONLY when the admin actually changed it. An unchanged selection means a rename
    // or a status toggle writes no roles at all — which is what keeps live BD/Admin accounts inert
    // under an ordinary account edit.
    updateUser.mutate(
      { id: user.id, payload: { name: name.trim(), status, ...(roleChanged ? { role } : {}) } },
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
            <DialogTitle className="text-lg font-bold text-card-foreground">Edit account</DialogTitle>
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-6 py-5">
          <FormField label="Name" required>
            <input type="text" className={FIELD} value={name} onChange={(e) => setName(e.target.value)} aria-required="true" />
          </FormField>
          <FormField label="Email">
            <input type="email" className={cn(FIELD, "opacity-70")} value={user.email} readOnly />
          </FormField>
          <FormField label="CRM access">
            <SelectShell>
              <select
                className={SELECT}
                value={role ?? NO_CRM_ACCESS}
                onChange={(e) => setRole(e.target.value === NO_CRM_ACCESS ? null : (e.target.value as Role))}
              >
                {/* Always offered, and always the value for an account with no CRM role — so the
                    absence of CRM access is something the admin reads and chooses, never a blank
                    that silently resolves to a role. */}
                <option value={NO_CRM_ACCESS}>No CRM access</option>
                {assignableRoles.map((r) => (
                  <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                ))}
                {/* The account's existing role, when this admin could not otherwise assign it (an
                    Admin viewing a CRM Admin). Rendered disabled so their current access is visible
                    and accurately reflected, but not reassignable. */}
                {currentRole && !assignableRoles.includes(currentRole) ? (
                  <option value={currentRole} disabled>{ROLE_LABELS[currentRole]}</option>
                ) : null}
              </select>
            </SelectShell>
          </FormField>
          {roleChanged ? (
            <p className="text-xs text-muted-foreground">
              {role === null
                ? "Removes CRM access only. Their account, password and TeamOra/Sprintly access are unchanged."
                : `Grants ${ROLE_LABELS[role]} access. Their other systems and password are unchanged.`}
            </p>
          ) : null}
          <FormField label="Status">
            <SelectShell>
              <select className={SELECT} value={status} onChange={(e) => setStatus(e.target.value as TeamUserStatus)}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </SelectShell>
          </FormField>
        </div>

        <DialogFooter className="mx-0 mb-0 flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={updateUser.isPending}>
            Cancel
          </Button>
          <Button variant="default" size="sm" onClick={handleSave} disabled={updateUser.isPending || name.trim() === ""}>
            {updateUser.isPending ? "Saving…" : "Save changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
