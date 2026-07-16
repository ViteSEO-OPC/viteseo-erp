"use client";

import { useState } from "react";
import { Plus } from "lucide-react";
import { toast } from "sonner";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { assignableRoles as computeAssignable } from "@/modules/crm/lib/rbac";
import { useUsers } from "@/modules/crm/features/settings/hooks/use-users-data";
import { useUpdateUser, useDeleteUser } from "@/modules/crm/features/settings/hooks/use-user-mutations";
import { TeamAccountsTable } from "@/modules/crm/features/settings/components/team-accounts-table";
import { CreateAccountModal } from "@/modules/crm/features/settings/components/create-account-modal";
import { EditAccountModal } from "@/modules/crm/features/settings/components/edit-account-modal";
import { CredentialsModal } from "@/modules/crm/features/settings/components/credentials-modal";
import type { TeamUser } from "@/modules/crm/features/settings/types/user-types";

export function TeamAccountsPage() {
  const { user } = useAuth();
  const { hasRole } = usePermissions();
  const assignable = computeAssignable(user?.roles);
  const isSuperAdmin = hasRole("super_admin");

  const { users, total, isLoading, isError, retry } = useUsers();
  const updateUser = useUpdateUser();
  const deleteUser = useDeleteUser();

  const [createOpen, setCreateOpen] = useState(false);
  const [editUser, setEditUser] = useState<TeamUser | null>(null);
  const [credsUser, setCredsUser] = useState<TeamUser | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<TeamUser | null>(null);

  const bdOnly = assignable.length === 1 && assignable[0] === "crm_bd";
  const createLabel = bdOnly ? "Create BD Account" : "Create Account";
  const listLabel = isSuperAdmin ? "Team accounts" : "BD accounts";

  const handleToggleStatus = (u: TeamUser) => {
    const nextStatus = u.status === "active" ? "inactive" : "active";
    updateUser.mutate(
      { id: u.id, payload: { status: nextStatus } },
      {
        onSuccess: () =>
          toast.success(nextStatus === "inactive" ? "Account suspended" : "Account reactivated"),
      },
    );
  };

  const confirmDelete = () => {
    if (!deleteTarget) return;
    deleteUser.mutate(deleteTarget.id, { onSuccess: () => setDeleteTarget(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h2 className="text-2xl font-bold text-foreground">Team Accounts</h2>
          <p className="mt-1 text-sm text-muted-foreground">Create and manage BD accounts.</p>
        </div>
        <Button
          variant="default"
          size="sm"
          onClick={() => setCreateOpen(true)}
          disabled={assignable.length === 0}
        >
          <Plus className="mr-1.5 h-4 w-4" /> {createLabel}
        </Button>
      </header>

      <AlertBanner
        variant="info"
        message="Only a name and working email are needed — a temporary password is generated and the sign-in details are shown for you to pass on."
      />

      <Card className="border border-border bg-card p-0 shadow-card">
        <div className="flex items-center gap-2 border-b border-border-subtle px-5 py-4">
          <h3 className="text-sm font-bold text-card-foreground">{listLabel}</h3>
          <span className="text-xs text-muted-foreground">
            {total} member{total === 1 ? "" : "s"}
          </span>
        </div>
        <div className="p-2">
          <TeamAccountsTable
            users={users}
            isLoading={isLoading}
            isError={isError}
            onRetry={retry}
            canDelete={isSuperAdmin}
            onCredentials={setCredsUser}
            onEdit={setEditUser}
            onToggleStatus={handleToggleStatus}
            onDelete={setDeleteTarget}
          />
        </div>
      </Card>

      <CreateAccountModal
        open={createOpen}
        onOpenChange={setCreateOpen}
        assignableRoles={assignable}
        defaultRole="crm_bd"
      />

      {editUser ? (
        <EditAccountModal
          key={editUser.id}
          open
          onOpenChange={(o) => { if (!o) setEditUser(null); }}
          user={editUser}
          assignableRoles={assignable}
        />
      ) : null}

      {credsUser ? (
        <CredentialsModal
          key={credsUser.id}
          open
          onOpenChange={(o) => { if (!o) setCredsUser(null); }}
          user={credsUser}
        />
      ) : null}

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}
        title="Delete account"
        description={
          deleteTarget
            ? `Delete ${deleteTarget.name}'s account? Their created records are preserved and the account can be restored by a developer.`
            : ""
        }
        confirmLabel="Delete"
        destructive
        loading={deleteUser.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
