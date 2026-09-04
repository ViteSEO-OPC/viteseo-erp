"use client";

import {
  Ban,
  KeyRound,
  MoreHorizontal,
  Pencil,
  RotateCcw,
  Trash2,
  UserMinus,
  Users,
} from "lucide-react";

import type { TeamMember } from "@/core/team/use-team-accounts";
import { buttonVariants } from "@/modules/crm/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { StatusPill } from "@/modules/crm/features/settings/components/account-chips";
import { cn } from "@/modules/crm/lib/utils";

type Props = {
  member: TeamMember;
  /**
   * Deleting an account is Super-Admin-only, and deliberately so — an Admin
   * suspends. The shared row's own `allowRemove` could not express that: it goes
   * to DELETE /crm/team/{user}, which admits any CRM admin. Delete therefore
   * lives here, on /users, behind the policy that actually enforces the rule.
   */
  canDelete: boolean;
  onCredentials: () => void;
  onRename: () => void;
  onToggleStatus: () => void;
  onReassignLeads: () => void;
  onRevokeAccess: () => void;
  onDelete: () => void;
};

/**
 * Everything Zerem can do to an account that /crm/team has no words for.
 *
 * A menu rather than a row of buttons: five actions beside a role picker is a row
 * an operator scans instead of reads, and four of the five are rare — the common
 * act on this screen is changing somebody's role, which the picker already is.
 *
 * NOTHING DESTRUCTIVE ON YOUR OWN ROW. Suspending, revoking or deleting yourself
 * locks you out of the screen you are standing on. A CRM admin never saw their own
 * row here before (the old /users list showed them BD accounts only), so this is
 * the property that list had by accident, kept on purpose now that the list shows
 * everyone.
 */
export function TeamMemberActions({
  member,
  canDelete,
  onCredentials,
  onRename,
  onToggleStatus,
  onReassignLeads,
  onRevokeAccess,
  onDelete,
}: Props) {
  // A member on one of the org's own roles holds direct permissions and no CRM
  // role at all, so PATCH /users {role: null} would detach nothing and report
  // success. Their way out is the role picker beside this menu, or a suspension.
  const holdsPreset = member.role !== null && !member.role.startsWith("custom:");

  const canAct = !member.is_you;
  // The resignation handover, shown only for a suspended member who still holds
  // leads — the one case where a portfolio is stranded with someone who can no
  // longer work it. Hidden at zero, so it never appears as a dead action.
  const canReassign = member.suspended && member.lead_count > 0;

  return (
    <>
      {member.suspended ? <StatusPill status="inactive" /> : null}

      <DropdownMenu>
        <DropdownMenuTrigger
          className={cn(buttonVariants({ variant: "ghost", size: "icon-sm" }))}
          aria-label={`More actions for ${member.name}`}
        >
          <MoreHorizontal className="h-4 w-4" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-56">
          <DropdownMenuItem onClick={onCredentials}>
            <KeyRound className="mr-2 h-3.5 w-3.5" /> Sign-in details
          </DropdownMenuItem>
          <DropdownMenuItem onClick={onRename}>
            <Pencil className="mr-2 h-3.5 w-3.5" /> Rename
          </DropdownMenuItem>

          {canAct ? (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={onToggleStatus}>
                {member.suspended ? (
                  <>
                    <RotateCcw className="mr-2 h-3.5 w-3.5" /> Restore Zerem access
                  </>
                ) : (
                  <>
                    <Ban className="mr-2 h-3.5 w-3.5" /> Suspend Zerem access
                  </>
                )}
              </DropdownMenuItem>
              {canReassign ? (
                <DropdownMenuItem onClick={onReassignLeads}>
                  <Users className="mr-2 h-3.5 w-3.5" /> Reassign all leads ({member.lead_count})
                </DropdownMenuItem>
              ) : null}
              {holdsPreset ? (
                <DropdownMenuItem onClick={onRevokeAccess}>
                  <UserMinus className="mr-2 h-3.5 w-3.5" /> Remove Zerem access
                </DropdownMenuItem>
              ) : null}
              {canDelete ? (
                <DropdownMenuItem onClick={onDelete} className="text-red-500">
                  <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete account
                </DropdownMenuItem>
              ) : null}
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>
    </>
  );
}
