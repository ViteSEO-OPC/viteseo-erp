"use client";

import { useState } from "react";
import { Plus } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { assignableRoles as computeAssignable } from "@/modules/crm/lib/rbac";
import { useUsers } from "@/modules/crm/features/settings/hooks/use-users-data";
import { useUpdateUser, useDeleteUser, useReassignAllLeads } from "@/modules/crm/features/settings/hooks/use-user-mutations";
import { BulkAssignBdModal } from "@/modules/crm/features/leads/components/bulk-assign-bd-modal";
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
  const reassignLeads = useReassignAllLeads();

  const [createOpen, setCreateOpen] = useState(false);
  const [editUser, setEditUser] = useState<TeamUser | null>(null);
  const [credsUser, setCredsUser] = useState<TeamUser | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<TeamUser | null>(null);
  // Resignation handover is the same two-step shape as the Leads bulk assign: confirm the intent,
  // THEN pick the destination. Two flags rather than one so Cancel on the picker cannot silently
  // re-open the confirmation.
  const [reassignTarget, setReassignTarget] = useState<TeamUser | null>(null);
  const [reassignPickerOpen, setReassignPickerOpen] = useState(false);
  const [suspendTarget, setSuspendTarget] = useState<TeamUser | null>(null);

  const bdOnly = assignable.length === 1 && assignable[0] === "crm_bd";
  const createLabel = bdOnly ? "Create BD Account" : "Create Account";
  const listLabel = isSuperAdmin ? "Team accounts" : "BD accounts";

  /**
     Suspending asks first; reactivating does not.

     The asymmetry is the point: suspending cuts a working BD out of Zerem mid-day, and the button
     sits one position away from Credentials in a row of otherwise harmless actions — the same
     misclick risk Delete already guards against. Reactivating only restores access that was
     deliberately removed, is visible immediately in the status pill, and is undone by clicking
     again, so a second click there would be friction with nothing behind it.
   */
  const handleToggleStatus = (u: TeamUser) => {
    if (u.status === "active") {
      setSuspendTarget(u);

      return;
    }

    applyStatus(u, "active");
  };

  const applyStatus = (u: TeamUser, nextStatus: "active" | "inactive") => {
    updateUser.mutate(
      { id: u.id, payload: { status: nextStatus } },
      {
        onSuccess: () => {
          toast.success(nextStatus === "inactive" ? "Account suspended" : "Account reactivated");
          setSuspendTarget(null);
        },
      },
    );
  };

  const confirmReassign = (bdId: string) => {
    if (!reassignTarget) return;
    reassignLeads.mutate(
      { userId: reassignTarget.id, toUserId: bdId },
      {
        onSuccess: () => {
          setReassignPickerOpen(false);
          setReassignTarget(null);
        },
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
            onReassignLeads={(u) => setReassignTarget(u)}
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

      {/* Suspend asks before it cuts access. Not `destructive`: the design reserves that treatment
          for Delete, and a suspension is reversible from this same row. */}
      <ConfirmDialog
        open={suspendTarget !== null}
        onOpenChange={(o) => { if (!o) setSuspendTarget(null); }}
        title="Suspend Zerem access"
        description={
          suspendTarget
            ? `Suspend ${suspendTarget.name}'s access to Zerem? They keep their account and their TeamOra and Sprintly access is unaffected. Their leads and open work stay with them until you reassign them.`
            : ""
        }
        confirmLabel="Suspend"
        loading={updateUser.isPending}
        onConfirm={() => { if (suspendTarget) applyStatus(suspendTarget, "inactive"); }}
      />

      {/* Step 1 of the resignation handover: confirm the intent before any picker or request. */}
      <ConfirmDialog
        open={reassignTarget !== null && !reassignPickerOpen}
        onOpenChange={(o) => { if (!o) setReassignTarget(null); }}
        title={reassignTarget ? `Reassign ${reassignTarget.leadCount} leads from ${reassignTarget.name}` : ""}
        description={
          reassignTarget
            ? `${reassignTarget.name}'s ${reassignTarget.leadCount} leads and their open follow-ups, meetings, tasks and calendar work will move to the BD you choose next. Completed work, calls, notes and performance history stay with ${reassignTarget.name}.`
            : ""
        }
        confirmLabel="Choose a BD"
        onConfirm={() => setReassignPickerOpen(true)}
      />

      {/* Step 2: the SAME picker the Leads bulk assign uses, so the destination list (active BDs
          only) can never disagree between the two entry points. */}
      {reassignTarget && reassignPickerOpen ? (
        <BulkAssignBdModal
          open
          onOpenChange={(o) => { if (!o) { setReassignPickerOpen(false); setReassignTarget(null); } }}
          count={reassignTarget.leadCount ?? 0}
          onConfirm={(bdId) => confirmReassign(bdId)}
          pending={reassignLeads.isPending}
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
