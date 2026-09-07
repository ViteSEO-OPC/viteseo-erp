"use client";

import { useState } from "react";

import { notify as toast } from "@/core/lib/notify";
import { CustomRolesManager } from "@/core/roles/custom-roles-manager";
import { TeamMembersSection } from "@/core/team/team-accounts-manager";
import { TEAM_MODULES, type TeamMember } from "@/core/team/use-team-accounts";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { BulkAssignBdModal } from "@/modules/crm/features/leads/components/bulk-assign-bd-modal";
import { AddTeamMemberSection } from "@/modules/crm/features/settings/components/add-team-member-section";
import { BuiltInRolesReference } from "@/modules/crm/features/settings/components/built-in-roles-reference";
import { CredentialsModal } from "@/modules/crm/features/settings/components/credentials-modal";
import { RenameAccountModal } from "@/modules/crm/features/settings/components/rename-account-modal";
import { TeamMemberActions } from "@/modules/crm/features/settings/components/team-member-actions";
import {
  useDeleteUser,
  useReassignAllLeads,
  useUpdateUser,
} from "@/modules/crm/features/settings/hooks/use-user-mutations";

/**
 * Team & Roles — the whole staff-access job on one page, as in TeamOra and Sprintly.
 *
 * WHY THIS REPLACED TWO ENTRIES. Zerem had Team Accounts and Roles & Access, and the
 * split was not merely one entry too many: Team Accounts listed from `GET /users`,
 * which selects by SPATIE ROLE. Somebody in one of the org's OWN roles holds no role
 * at all — only direct permissions — so an org staffed that way got an empty table
 * with no explanation, and an admin could create account after account and watch the
 * screen stay blank. The list now reads `GET /crm/team`, which counts org MEMBERSHIP
 * and therefore sees everyone, and reports `custom:<id>` for the roles /users cannot
 * name.
 *
 * ONLY THE LIST MOVED. Every write still goes to its own /users endpoint, because
 * /crm/team can express none of them: the colleague lookup and grant, the rename,
 * suspension, the resignation handover, and the Super-Admin-only delete. They hang off
 * the member row through {@link TeamMemberActions}, which is what `rowActions` on the
 * shared section exists for.
 *
 * FOUR SECTIONS, IN THE ORDER AN ADMIN WORKS THEM — add a member, see the members,
 * read what the built-in roles grant, build your own. The same four in the same order
 * as the other two portals, because a client learns this layout once.
 *
 * NO GATE OF ITS OWN: /zerem/settings is already `minRole: crm_admin` in PAGE_ACCESS
 * and every endpoint re-checks on the server.
 */
export function TeamRolesPage() {
  const { hasRole } = usePermissions();
  const isSuperAdmin = hasRole("super_admin");

  const updateUser = useUpdateUser();
  const deleteUser = useDeleteUser();
  const reassignLeads = useReassignAllLeads();

  const [credentialsTarget, setCredentialsTarget] = useState<TeamMember | null>(null);
  const [renameTarget, setRenameTarget] = useState<TeamMember | null>(null);
  const [suspendTarget, setSuspendTarget] = useState<TeamMember | null>(null);
  const [revokeTarget, setRevokeTarget] = useState<TeamMember | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<TeamMember | null>(null);
  // Resignation handover is the same two-step shape as the Leads bulk assign: confirm the intent,
  // THEN pick the destination. Two flags rather than one so Cancel on the picker cannot silently
  // re-open the confirmation.
  const [reassignTarget, setReassignTarget] = useState<TeamMember | null>(null);
  const [reassignPickerOpen, setReassignPickerOpen] = useState(false);

  /** What the member's role is called — presets by their picker name, custom by the org's. */
  const roleLabelOf = (member: TeamMember): string => {
    if (member.role === null) return "No Zerem access";
    return (
      TEAM_MODULES.crm.roles.find((preset) => preset.value === member.role)?.label ??
      member.role_label ??
      member.role
    );
  };

  /**
   * Suspending asks first; restoring does not.
   *
   * The asymmetry is the point: suspending cuts a working BD out of Zerem mid-day. Restoring only
   * gives back access that was deliberately removed, is visible immediately in the row's pill, and
   * is undone by clicking again — so a second click there would be friction with nothing behind it.
   */
  const handleToggleStatus = (member: TeamMember) => {
    if (!member.suspended) {
      setSuspendTarget(member);
      return;
    }
    applyStatus(member, "active");
  };

  const applyStatus = (member: TeamMember, nextStatus: "active" | "inactive") => {
    updateUser.mutate(
      { id: String(member.id), payload: { status: nextStatus } },
      {
        onSuccess: () => {
          toast.success(nextStatus === "inactive" ? "Account suspended" : "Account reactivated");
          setSuspendTarget(null);
        },
      },
    );
  };

  const confirmRevoke = () => {
    if (!revokeTarget) return;
    updateUser.mutate(
      { id: String(revokeTarget.id), payload: { role: null } },
      {
        onSuccess: () => {
          toast.success(`${revokeTarget.name} no longer has Zerem access.`);
          setRevokeTarget(null);
        },
      },
    );
  };

  const confirmReassign = (bdId: string) => {
    if (!reassignTarget) return;
    reassignLeads.mutate(
      { userId: String(reassignTarget.id), toUserId: bdId },
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
    deleteUser.mutate(String(deleteTarget.id), { onSuccess: () => setDeleteTarget(null) });
  };

  return (
    <div className="flex flex-col gap-10">
      <header>
        <h1 className="text-2xl font-bold text-foreground">Team &amp; Roles</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Add your Zerem staff and set what they can do.
        </p>
      </header>

      <AddTeamMemberSection />

      <TeamMembersSection
        module="crm"
        // Closing an account is Super-Admin-only, and /crm/team's DELETE would hand the
        // same power to any CRM admin. The row menu offers the /users delete instead,
        // where the policy that enforces the rule actually lives.
        allowRemove={false}
        description={
          <>
            The people who can sign in to Zerem, and the role each of them holds. A
            colleague who works only in TeamOra or Sprintly is not listed here until
            you give them access above. The picker offers the built-in roles below and
            every custom role you define at the bottom of this page. Everything else
            about an account — its sign-in details, its name, suspending it, handing on
            a resigning BD&rsquo;s leads — is in the menu at the end of the row.
          </>
        }
        rowActions={(member) => (
          <TeamMemberActions
            member={member}
            canDelete={isSuperAdmin}
            onCredentials={() => setCredentialsTarget(member)}
            onRename={() => setRenameTarget(member)}
            onToggleStatus={() => handleToggleStatus(member)}
            onReassignLeads={() => setReassignTarget(member)}
            onRevokeAccess={() => setRevokeTarget(member)}
            onDelete={() => setDeleteTarget(member)}
          />
        )}
      />

      <BuiltInRolesReference />

      {/* The org's own role builder — a platform surface, not a CRM one. Its built-in
          list is off: the reference directly above is Zerem's fuller account of the
          same roles, and two answers to that question on one page is what this merge
          exists to remove. */}
      <CustomRolesManager
        feature="crm"
        showBuiltInRoles={false}
        className="border-t border-border pt-6"
      />

      {credentialsTarget ? (
        <CredentialsModal
          key={credentialsTarget.id}
          open
          onOpenChange={(o) => { if (!o) setCredentialsTarget(null); }}
          member={credentialsTarget}
          roleLabel={roleLabelOf(credentialsTarget)}
        />
      ) : null}

      {renameTarget ? (
        <RenameAccountModal
          key={renameTarget.id}
          open
          onOpenChange={(o) => { if (!o) setRenameTarget(null); }}
          member={renameTarget}
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

      {/* Removing the role outright, as opposed to suspending it. Offered only for a
          member on a built-in role — see TeamMemberActions for why a custom-role holder
          cannot be revoked this way. */}
      <ConfirmDialog
        open={revokeTarget !== null}
        onOpenChange={(o) => { if (!o) setRevokeTarget(null); }}
        title="Remove Zerem access"
        description={
          revokeTarget
            ? `${revokeTarget.name} keeps their Vite SEO Systems account, their password and their TeamOra and Sprintly access — they simply stop being a Zerem user, and drop off this list. Give it back any time by entering their email in "Add a member" above.`
            : ""
        }
        confirmLabel="Remove access"
        loading={updateUser.isPending}
        onConfirm={confirmRevoke}
      />

      {/* Step 1 of the resignation handover: confirm the intent before any picker or request. */}
      <ConfirmDialog
        open={reassignTarget !== null && !reassignPickerOpen}
        onOpenChange={(o) => { if (!o) setReassignTarget(null); }}
        title={reassignTarget ? `Reassign ${reassignTarget.lead_count} leads from ${reassignTarget.name}` : ""}
        description={
          reassignTarget
            ? `${reassignTarget.name}'s ${reassignTarget.lead_count} leads and their open follow-ups, meetings, tasks and calendar work will move to the BD you choose next. Completed work, calls, notes and performance history stay with ${reassignTarget.name}.`
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
          count={reassignTarget.lead_count}
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
