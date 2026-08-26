"use client";

import { MoreHorizontal, Pencil, Trash2, Users } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import { Button, buttonVariants } from "@/modules/crm/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { TableSkeleton } from "@/modules/crm/features/dashboard/components/table-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import {
  RoleChip,
  StatusPill,
  initialsOf,
} from "@/modules/crm/features/settings/components/account-chips";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { cn } from "@/modules/crm/lib/utils";
import { useTableDensity } from "@/modules/crm/components/ui/table-density";
import type { TeamUser } from "@/modules/crm/features/settings/types/user-types";

type Props = {
  users: TeamUser[];
  isLoading: boolean;
  isError: boolean;
  onRetry: () => void;
  /** Delete is Super-Admin-only. */
  canDelete: boolean;
  onCredentials: (u: TeamUser) => void;
  onEdit: (u: TeamUser) => void;
  onToggleStatus: (u: TeamUser) => void;
  /** Hand a suspended BD's whole portfolio to another active BD. */
  onReassignLeads: (u: TeamUser) => void;
  onDelete: (u: TeamUser) => void;
};

export function TeamAccountsTable({
  users,
  isLoading,
  isError,
  onRetry,
  canDelete,
  onCredentials,
  onEdit,
  onToggleStatus,
  onReassignLeads,
  onDelete,
}: Props) {
  // Above the early returns — hooks must run on every render, including the loading/error paths.
  const density = useTableDensity();

  if (isLoading) return <TableSkeleton />;
  if (isError) return <ErrorState message="Failed to load team accounts" onRetry={onRetry} />;

  const th = cn(density.head.standard, "px-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground");
  const td = cn(density.row.standard, "px-3 text-sm");
  // Two cells sit a notch tighter than the rest of the row. Pre-existing drift, preserved
  // deliberately: normalising it here would be a visual change dressed up as a refactor.
  const tdTight = cn(density.row.condensed, "px-3");

  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border-subtle">
            <TableHead className={th}>Team Member</TableHead>
            <TableHead className={th}>Email</TableHead>
            <TableHead className={th}>Role</TableHead>
            <TableHead className={th}>Status</TableHead>
            <TableHead className={th}>Last Active</TableHead>
            <TableHead className={cn(th, "text-right")}>Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {users.length > 0 ? (
            users.map((u) => (
              <TableRow key={u.id} className="border-b border-border-subtle">
                <TableCell className={tdTight}>
                  <div className="flex items-center gap-3">
                    <AvatarInitials src={u.avatarUrl} initials={initialsOf(u.name)} size="sm" />
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-foreground">{u.name}</p>
                      <p className="truncate text-xs text-muted-foreground">{u.email}</p>
                    </div>
                  </div>
                </TableCell>
                <TableCell className={cn(td, "text-secondary-foreground")}>{u.email}</TableCell>
                <TableCell className={cn(density.row.standard, "px-3")}><RoleChip role={u.role} /></TableCell>
                <TableCell className={cn(density.row.standard, "px-3")}><StatusPill status={u.status} /></TableCell>
                <TableCell className={cn(td, "text-muted-foreground")}>
                  {formatActivityTimestamp(u.lastLoginAt)}
                </TableCell>
                <TableCell className={tdTight}>
                  <div className="flex items-center justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={() => onCredentials(u)}>
                      Credentials
                    </Button>
                    <Button variant="outline" size="sm" disabled title="Coming soon">
                      Reset Password
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => onToggleStatus(u)}>
                      {u.status === "active" ? "Suspend" : "Activate"}
                    </Button>
                    <DropdownMenu>
                      <DropdownMenuTrigger
                        className={cn(buttonVariants({ variant: "ghost", size: "icon-sm" }))}
                        aria-label={`More actions for ${u.name}`}
                      >
                        <MoreHorizontal className="h-4 w-4" />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-36">
                        <DropdownMenuItem onClick={() => onEdit(u)}>
                          <Pencil className="mr-2 h-3.5 w-3.5" /> Edit
                        </DropdownMenuItem>
                        {/* Resignation handover. Shown only for a suspended BD who still holds
                            leads — the one case where a portfolio is stranded with someone who can
                            no longer work it. Hidden at zero, so it never appears as a dead action. */}
                        {u.status === "inactive" && (u.leadCount ?? 0) > 0 ? (
                          <DropdownMenuItem onClick={() => onReassignLeads(u)}>
                            <Users className="mr-2 h-3.5 w-3.5" /> Reassign all leads ({u.leadCount})
                          </DropdownMenuItem>
                        ) : null}
                        {canDelete ? (
                          <DropdownMenuItem onClick={() => onDelete(u)} className="text-red-500">
                            <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete
                          </DropdownMenuItem>
                        ) : null}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                </TableCell>
              </TableRow>
            ))
          ) : (
            <TableRow>
              <TableCell colSpan={6} className="px-4 py-12 text-center text-sm text-muted-foreground">
                No team accounts yet.
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
