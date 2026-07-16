"use client";

import { useState } from "react";
import { X } from "lucide-react";
import { toast } from "sonner";
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

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: TeamUser;
  assignableRoles: Role[];
};

/** Render with key={user.id} so state initializes fresh per account. */
export function EditAccountModal({ open, onOpenChange, user, assignableRoles }: Props) {
  const [name, setName] = useState(user.name);
  const [role, setRole] = useState<Role>(
    user.role && isRole(user.role)
      ? user.role
      : (assignableRoles[0] ?? "crm_bd"),
  );
  const [status, setStatus] = useState<TeamUserStatus>(user.status);
  const updateUser = useUpdateUser();
  const showRoleSelect = assignableRoles.length > 1;

  const handleSave = () => {
    updateUser.mutate(
      { id: user.id, payload: { name: name.trim(), role, status } },
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
      <DialogContent showCloseButton={false} className="flex w-full flex-col p-0 sm:max-w-[420px]">
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
          {showRoleSelect ? (
            <FormField label="Role">
              <select className={SELECT} value={role} onChange={(e) => setRole(e.target.value as Role)}>
                {assignableRoles.map((r) => (
                  <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                ))}
              </select>
            </FormField>
          ) : null}
          <FormField label="Status">
            <select className={SELECT} value={status} onChange={(e) => setStatus(e.target.value as TeamUserStatus)}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </FormField>
        </div>

        <DialogFooter className="flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
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
