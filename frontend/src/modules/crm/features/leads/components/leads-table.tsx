"use client";

import { useRouter } from "next/navigation";
import { Checkbox } from "@/modules/crm/components/ui/checkbox";
import { Button } from "@/modules/crm/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { Edit, Trash2, UserPlus } from "lucide-react";
import { TableSkeleton } from "@/modules/crm/features/dashboard/components/table-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { formatDateLong } from "@/modules/crm/lib/formatters";
import type { Lead, OutreachStatus, CallingStatus } from "@/modules/crm/features/leads/types/lead-types";

// The Website column is hidden from the Leads list for Sprint 6 (BD workflow now uses
// Priority as the primary indicator). website_status remains in the model/API and on the
// Lead Detail + Edit surfaces — this is a presentation-only change to the list view.
const emailConfig: Record<OutreachStatus, { label: string; className: string }> = {
  sent: { label: "Sent", className: "bg-muted text-secondary-foreground" },
  opened: { label: "Opened", className: "bg-blue-500/10 text-blue-500" },
  replied: { label: "Replied", className: "bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400" },
  none: { label: "\u2014", className: "text-muted-foreground" },
};

const callConfig: Record<CallingStatus, { label: string; className: string }> = {
  called: { label: "Called", className: "bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400" },
  scheduled: { label: "Scheduled", className: "bg-blue-500/10 text-blue-500" },
  "not-called": { label: "Not Called", className: "bg-muted text-secondary-foreground" },
};

const chipClass = "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium";

type LeadsTableProps = {
  leads: Lead[];
  isLoading: boolean;
  isError: boolean;
  onRetry: () => void;
  searchActive: boolean;
  onEdit: (lead: Lead) => void;
  onDelete: (lead: Lead) => void;
  /** Assign/Reassign is Admin/SA only; BD sees the owner but can't change it. */
  canAssign: boolean;
  onAssign: (lead: Lead) => void;
  /** Ids of the currently-selected leads (scoped to the visible page). */
  selectedIds: Set<string>;
  /** Toggle a single row's selection. */
  onToggleSelect: (id: string) => void;
  /** Select-all / clear for the current page (driven by the header checkbox). */
  onToggleSelectAll: (checked: boolean) => void;
  /** Clear the whole selection (Bulk Actions bar). */
  onClearSelection: () => void;
  /** Open the (reused) delete confirmation for the selected leads. */
  onBulkDelete: () => void;
  /** True while a bulk delete is in flight — locks the checkboxes + bar. */
  bulkDeleting: boolean;
};

export function LeadsTable({
  leads,
  isLoading,
  isError,
  onRetry,
  searchActive,
  onEdit,
  onDelete,
  canAssign,
  onAssign,
  selectedIds,
  onToggleSelect,
  onToggleSelectAll,
  onClearSelection,
  onBulkDelete,
  bulkDeleting,
}: LeadsTableProps) {
  const router = useRouter();

  if (isLoading) return <TableSkeleton />;
  if (isError) return <ErrorState message="Failed to load leads" onRetry={onRetry} />;

  const th = "h-10 px-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground";
  const td = "px-3 py-3.5 text-sm";

  // Selection is scoped to the visible page: the header checkbox reflects the rows on screen.
  const selectedCount = leads.reduce((n, lead) => (selectedIds.has(lead.id) ? n + 1 : n), 0);
  const allSelected = leads.length > 0 && selectedCount === leads.length;
  const someSelected = selectedCount > 0 && !allSelected;
  const totalCols = canAssign ? 10 : 9;

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-card shadow-card">
      <Table>
        <TableHeader>
          {selectedCount > 0 ? (
            <TableRow className="border-b border-border-subtle bg-primary/5 hover:bg-primary/5">
              <TableHead className={th + " w-10"}>
                <Checkbox
                  aria-label="Select all"
                  checked={allSelected}
                  indeterminate={someSelected}
                  onCheckedChange={onToggleSelectAll}
                  disabled={bulkDeleting}
                />
              </TableHead>
              <TableHead colSpan={totalCols - 1} className="h-10 px-3">
                <div className="flex flex-wrap items-center gap-3 text-sm normal-case tracking-normal">
                  <span className="font-semibold text-foreground">
                    {selectedCount} Lead{selectedCount === 1 ? "" : "s"} Selected
                  </span>
                  <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    onClick={onBulkDelete}
                    disabled={bulkDeleting}
                  >
                    Delete Selected
                  </Button>
                  {/* Assign BD is intentionally disabled for now (bulk assign not yet wired). */}
                  <Button type="button" variant="outline" size="sm" disabled>
                    Assign BD
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onClearSelection}
                    disabled={bulkDeleting}
                  >
                    Clear Selection
                  </Button>
                </div>
              </TableHead>
            </TableRow>
          ) : (
            <TableRow className="border-b border-border-subtle bg-surface-table-header">
              <TableHead className={th + " w-10"}>
                <Checkbox
                  aria-label="Select all"
                  checked={allSelected}
                  indeterminate={someSelected}
                  onCheckedChange={onToggleSelectAll}
                  disabled={leads.length === 0}
                />
              </TableHead>
              <TableHead className={th}>Business Name</TableHead>
              <TableHead className={th}>Contact</TableHead>
              <TableHead className={th}>Priority</TableHead>
              <TableHead className={th}>Email</TableHead>
              <TableHead className={th}>Cold Call</TableHead>
              <TableHead className={th}>Status</TableHead>
              {canAssign ? <TableHead className={th}>Assigned BD</TableHead> : null}
              <TableHead className={th}>Last Activity</TableHead>
              <TableHead className={th + " w-24 text-center"}>Action</TableHead>
            </TableRow>
          )}
        </TableHeader>
        <TableBody>
          {leads && leads.length > 0 ? (
            leads.map((lead) => (
              <TableRow
                key={lead.id}
                data-selected={selectedIds.has(lead.id) ? "" : undefined}
                className="cursor-pointer border-b border-border-subtle transition-colors hover:bg-muted data-[selected]:bg-primary/5"
                onClick={() => router.push(`/zerem/leads/${lead.id}`)}
              >
                <TableCell className="px-3 py-3.5">
                  {/* stopPropagation so ticking a row doesn't trigger the row's navigation. */}
                  <span
                    className="inline-flex"
                    onClick={(e) => e.stopPropagation()}
                  >
                    <Checkbox
                      aria-label={`Select ${lead.business}`}
                      checked={selectedIds.has(lead.id)}
                      onCheckedChange={() => onToggleSelect(lead.id)}
                      disabled={bulkDeleting}
                    />
                  </span>
                </TableCell>
                {/* Business: avatar initial + name over industry, per the approved design.
                    `industry` already ships on the Lead payload — no API change. */}
                <TableCell className={td}>
                  <div className="flex items-center gap-3">
                    <span
                      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-secondary-foreground"
                      aria-hidden
                    >
                      {lead.business.trim().charAt(0).toUpperCase()}
                    </span>
                    <div className="min-w-0">
                      <p className="truncate font-semibold text-foreground">{lead.business}</p>
                      {lead.industry && (
                        <p className="truncate text-xs text-muted-foreground">{lead.industry}</p>
                      )}
                    </div>
                  </div>
                </TableCell>
                {/* Contact: name, email, phone stacked — all three already on the payload. */}
                <TableCell className={td}>
                  <div className="min-w-0">
                    <p className="truncate font-medium text-foreground">{lead.contact}</p>
                    {lead.email && (
                      <p className="truncate text-xs text-primary-accent">{lead.email}</p>
                    )}
                    {lead.phone && (
                      <p className="truncate text-xs text-muted-foreground">{lead.phone}</p>
                    )}
                  </div>
                </TableCell>
                <TableCell className="px-3 py-3.5">
                  <PriorityChip priority={lead.priority} />
                </TableCell>
                <TableCell className="px-3 py-3.5">
                  <span className={chipClass + " " + emailConfig[lead.emailOutreach].className}>
                    {emailConfig[lead.emailOutreach].label}
                  </span>
                </TableCell>
                <TableCell className="px-3 py-3.5">
                  <span className={chipClass + " " + callConfig[lead.coldCalling].className}>
                    {callConfig[lead.coldCalling].label}
                  </span>
                </TableCell>
                <TableCell className="px-3 py-3.5">
                  <StatusChip status={lead.updateStatus} />
                </TableCell>
                {canAssign ? (
                  <TableCell className={td}>
                    {lead.assignedTo ? (
                      <span className="text-secondary-foreground">{lead.assignedTo.name}</span>
                    ) : (
                      <span className="text-muted-foreground italic">Unassigned</span>
                    )}
                  </TableCell>
                ) : null}
                <TableCell className={td + " text-muted-foreground"}>
                  {formatDateLong(lead.lastActivity)}
                </TableCell>
                <TableCell className="px-3 py-3.5">
                  <div className="flex items-center justify-center gap-0.5">
                    {canAssign ? (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          onAssign(lead);
                        }}
                        className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={`Assign ${lead.business}`}
                      >
                        <UserPlus className="h-3.5 w-3.5" />
                      </button>
                    ) : null}
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        onEdit(lead);
                      }}
                      className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      aria-label={`Edit ${lead.business}`}
                    >
                      <Edit className="h-3.5 w-3.5" />
                    </button>
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        onDelete(lead);
                      }}
                      className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-red-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      aria-label={`Delete ${lead.business}`}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </div>
                </TableCell>
              </TableRow>
            ))
          ) : (
            <TableRow>
              <TableCell
                colSpan={canAssign ? 10 : 9}
                className="px-4 py-12 text-center text-sm text-muted-foreground"
              >
                {searchActive ? "No leads match your search." : "No leads yet"}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
