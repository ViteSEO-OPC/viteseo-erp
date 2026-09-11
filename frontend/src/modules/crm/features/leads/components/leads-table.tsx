"use client";

import { Fragment, useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { useRouter } from "next/navigation";
import { Checkbox } from "@/modules/crm/components/ui/checkbox";
import { Button, buttonVariants } from "@/modules/crm/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/modules/crm/components/ui/dropdown-menu";
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
import { ChevronDown, Download, Edit, Loader2, Trash2, UserPlus } from "lucide-react";
import { TableSkeleton } from "@/modules/crm/features/dashboard/components/table-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { formatDateLong } from "@/modules/crm/lib/formatters";
import { cn } from "@/modules/crm/lib/utils";
import { useTableDensity } from "@/modules/crm/components/ui/table-density";
import { LeadsColumnFilter } from "@/modules/crm/features/leads/components/leads-column-filter";
import type { ColumnFilterOption } from "@/modules/crm/features/leads/components/leads-column-filter";
import {
  LEAD_COLD_CALLING_VALUES,
  LEAD_EMAIL_OUTREACH_VALUES,
  LEAD_PRIORITY_VALUES,
  LEAD_STATUS_VALUES,
} from "@/modules/crm/features/leads/hooks/use-leads-data";
import type { Lead, OutreachStatus, CallingStatus } from "@/modules/crm/features/leads/types/lead-types";
import type { LeadStatus, PriorityLevel } from "@/modules/crm/features/dashboard/types/dashboard-types";

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

// Column-filter options are DERIVED from the backend whitelists exported by useLeads — no value is
// restated here. Each label is the column's OWN chip component, so a filter option and a table row
// can never drift apart in wording or colour.
const PRIORITY_FILTER_OPTIONS = LEAD_PRIORITY_VALUES.map((value) => ({
  value,
  label: <PriorityChip priority={value as PriorityLevel} />,
}));

// `new` is a valid backend status but is not in StatusChip's map; the chip's documented fallback
// renders it (Info/Blue). Cast rather than widen LeadStatus, which is shared with the Dashboard.
const STATUS_FILTER_OPTIONS = LEAD_STATUS_VALUES.map((value) => ({
  value,
  label: <StatusChip status={value as LeadStatus} />,
}));

// Email / Cold Call reuse the very chip configs the cells below render, so an option and its column
// are guaranteed to show the same label and colour. The one exception is `none`, whose table label is
// an em dash — unreadable in a menu — so the option shows the enum value itself, title-cased.
const EMAIL_FILTER_OPTIONS = LEAD_EMAIL_OUTREACH_VALUES.map((value) => ({
  value,
  label: (
    <span className={chipClass + " " + emailConfig[value].className}>
      {value === "none" ? "None" : emailConfig[value].label}
    </span>
  ),
}));

const CALL_FILTER_OPTIONS = LEAD_COLD_CALLING_VALUES.map((value) => ({
  value,
  label: <span className={chipClass + " " + callConfig[value].className}>{callConfig[value].label}</span>,
}));

type LeadColumnKey =
  | "business"
  | "contact"
  | "assigned"
  | "priority"
  | "email"
  | "call"
  | "status"
  | "activity"
  | "action";

/**
 * Column order per audience. Admin/Super Admin follows the approved design reference; the BD view is
 * unchanged from today and keeps Email and Cold Call, which the admin layout omits.
 *
 * Order AND presence are driven entirely by these lists, so every header and cell below is authored
 * exactly once — the two audiences share identical markup, styling and behaviour, and differ only in
 * which keys appear here and in what sequence. The leading selection checkbox is outside the list; it
 * is always first for both audiences, unchanged.
 */
const ADMIN_COLUMN_ORDER: readonly LeadColumnKey[] = [
  "business",
  "contact",
  "assigned",
  "priority",
  "status",
  "activity",
  "action",
];

const BD_COLUMN_ORDER: readonly LeadColumnKey[] = [
  "business",
  "contact",
  "priority",
  "email",
  "call",
  "status",
  "activity",
  "action",
];

// Rows shown before the list starts scrolling. Presentation only — it does not cap what is
// fetched or rendered, so pagination, selection and export still see every row on the page.
// BD-only: the Admin/SA table has no internal vertical scroll and grows with its (larger) page.
// Exported so the page can tell whether this table is scrolling — the pager's summary is shown
// whenever the list doesn't display every row at once, scrolled or paged.
export const MAX_VISIBLE_ROWS = 5;

// Subtle scrollbar reused from the existing CRM surfaces (activity timeline, call history,
// email thread) so this introduces no new scrollbar design.
const scrollbarClass =
  "[&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent";

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
  /** Open the "Archive all leads" confirmation — every lead in scope, not just the selection. */
  onBulkDeleteAll: () => void;
  /** Download the ticked rows as CSV. Client-side and read-only, so it is not role-gated. */
  onBulkExport: () => void;
  /** Open the status picker for the ticked rows. Rendered only for a BD (see the bar below). */
  onBulkStatus: () => void;
  /** True while a bulk delete is in flight — locks the checkboxes + bar. */
  bulkDeleting: boolean;
  /** Open the Assign BD confirmation for the selected leads. Rendered only when `canAssign`. */
  onBulkAssign: () => void;
  /** True while a bulk assign is in flight — locks the checkboxes + bar, same as a bulk delete. */
  bulkAssigning: boolean;
  /**
   * Column filters. Values and setters come from the single useLeads instance, so they map onto the
   * URL params the API already accepts (`?priority=`, `?status=`) and stay in lockstep with the
   * Priority Summary cards and Status Summary tabs, which drive the same params.
   */
  priorityFilter: string;
  onPriorityChange: (value: string) => void;
  /** Comma-separated list — the backend validates each token (multi-select). */
  statusFilter: string;
  onStatusChange: (value: string) => void;
  /** Communication-lifecycle filters — single-value, matching their IndexLeadRequest rules. */
  emailFilter: string;
  onEmailChange: (value: string) => void;
  callFilter: string;
  onCallChange: (value: string) => void;
  /**
   * Assigned BD filter. Options are live accounts from useBdUsers (resolved by the page), not a
   * fixed whitelist, plus the "Unassigned" sentinel for leads with no owner. Only rendered when
   * `canAssign`, matching the column itself.
   */
  assignedToFilter: string;
  onAssignedToChange: (value: string) => void;
  bdOptions: ColumnFilterOption[];
  /** BD table only: download one lead's PDF (replaces Delete in that audience's row actions). */
  onExportPdf: (lead: Lead) => void;
  /** Id of the lead currently exporting, so only that row shows a spinner. */
  exportingPdfId: string | null;
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
  onBulkDeleteAll,
  onBulkExport,
  onBulkStatus,
  bulkDeleting,
  onBulkAssign,
  bulkAssigning,
  priorityFilter,
  onPriorityChange,
  statusFilter,
  onStatusChange,
  emailFilter,
  onEmailChange,
  callFilter,
  onCallChange,
  assignedToFilter,
  onAssignedToChange,
  bdOptions,
  onExportPdf,
  exportingPdfId,
}: LeadsTableProps) {
  const router = useRouter();

  const scrollRef = useRef<HTMLDivElement>(null);
  const bodyRef = useRef<HTMLTableSectionElement>(null);
  const [maxHeight, setMaxHeight] = useState<number>();

  const rowCount = leads?.length ?? 0;
  // BD keeps the capped, scrolling list exactly as before. Admin/SA opted out: their page holds up
  // to 10 rows and is meant to grow naturally, so no height cap is measured or applied for them.
  const scrollable = !canAssign && rowCount > MAX_VISIBLE_ROWS;

  // Cap the list at exactly MAX_VISIBLE_ROWS. The height is measured rather than hard-coded because
  // row height is not uniform — the Contact cell stacks name / email / phone and each is optional,
  // so a fixed pixel value would clip the 5th row on some pages and leave a gap on others.
  // Hooks run before the loading/error returns below to keep the hook order stable across renders.
  useEffect(() => {
    // No reset when this turns false: the render below ignores maxHeight unless `scrollable`, so a
    // stale value is harmless and re-measured before it is used again.
    if (!scrollable) return;

    const container = scrollRef.current;
    const body = bodyRef.current;
    if (!container || !body) return;

    // ResizeObserver delivers an initial callback on observe(), which supplies the first
    // measurement — so state is only ever set from the subscription, never synchronously here.
    // It also re-measures when row heights shift on viewport resize (truncation) or data change.
    const observer = new ResizeObserver(() => {
      const lastVisible = body.children[MAX_VISIBLE_ROWS - 1] as HTMLElement | undefined;
      if (!lastVisible) return;
      // Measured from the top of the scroll container, so the sticky header is included in the
      // budget and stays pinned above exactly five rows. scrollTop keeps this correct mid-scroll.
      setMaxHeight(
        Math.round(
          lastVisible.getBoundingClientRect().bottom -
            container.getBoundingClientRect().top +
            container.scrollTop,
        ),
      );
    });

    observer.observe(body);
    return () => observer.disconnect();
  }, [scrollable, rowCount, canAssign]);

  // Above the early returns — hooks must run on every render, including the loading/error paths.
  const density = useTableDensity();

  if (isLoading) return <TableSkeleton />;
  if (isError) return <ErrorState message="Failed to load leads" onRetry={onRetry} />;

  const th = cn(density.head.standard, "px-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground");
  const td = cn(density.row.standard, "px-3 text-sm");

  // Selection is scoped to the visible page: the header checkbox reflects the rows on screen.
  const selectedCount = leads.reduce((n, lead) => (selectedIds.has(lead.id) ? n + 1 : n), 0);
  const allSelected = leads.length > 0 && selectedCount === leads.length;
  const someSelected = selectedCount > 0 && !allSelected;
  // Either bulk action locks the whole bar + the row checkboxes, so the selection can't be edited
  // out from under a request that is already running against it.
  const bulkBusy = bulkDeleting || bulkAssigning;

  const columnOrder = canAssign ? ADMIN_COLUMN_ORDER : BD_COLUMN_ORDER;
  // +1 for the leading selection checkbox column, which sits outside the order list.
  const totalCols = columnOrder.length + 1;

  // Header cells, authored once and rendered in the order the audience calls for.
  const headerCells: Record<LeadColumnKey, ReactNode> = {
    business: <TableHead className={th}>Business Name</TableHead>,
    contact: <TableHead className={th}>Contact</TableHead>,
    assigned: (
      <TableHead className={th}>
        <span className="inline-flex items-center">
          Assigned BD
          {/* Same component/icon/spacing/behaviour as the other column filters — only the
              option source differs (live BD accounts instead of a fixed enum). */}
          <LeadsColumnFilter
            column="Assigned BD"
            options={bdOptions}
            selected={assignedToFilter ? [assignedToFilter] : []}
            onChange={(values) => onAssignedToChange(values[0] ?? "")}
          />
        </span>
      </TableHead>
    ),
    priority: (
      <TableHead className={th}>
        <span className="inline-flex items-center">
          Priority
          {/* Single-select: the API's `priority` rule accepts one value, not a list. */}
          <LeadsColumnFilter
            column="Priority"
            options={PRIORITY_FILTER_OPTIONS}
            selected={priorityFilter ? [priorityFilter] : []}
            onChange={(values) => onPriorityChange(values[0] ?? "")}
          />
        </span>
      </TableHead>
    ),
    email: (
      <TableHead className={th}>
        <span className="inline-flex items-center">
          Email
          <LeadsColumnFilter
            column="Email"
            options={EMAIL_FILTER_OPTIONS}
            selected={emailFilter ? [emailFilter] : []}
            onChange={(values) => onEmailChange(values[0] ?? "")}
          />
        </span>
      </TableHead>
    ),
    call: (
      <TableHead className={th}>
        <span className="inline-flex items-center">
          Cold Call
          <LeadsColumnFilter
            column="Cold Call"
            options={CALL_FILTER_OPTIONS}
            selected={callFilter ? [callFilter] : []}
            onChange={(values) => onCallChange(values[0] ?? "")}
          />
        </span>
      </TableHead>
    ),
    status: (
      <TableHead className={th}>
        <span className="inline-flex items-center">
          Status
          {/* Multi-select: the API's `status` rule accepts a comma-separated list. */}
          <LeadsColumnFilter
            column="Status"
            multiple
            options={STATUS_FILTER_OPTIONS}
            selected={statusFilter ? statusFilter.split(",") : []}
            onChange={(values) => onStatusChange(values.join(","))}
          />
        </span>
      </TableHead>
    ),
    activity: <TableHead className={th}>Last Activity</TableHead>,
    action: <TableHead className={th + " w-24 text-center"}>Action</TableHead>,
  };

  return (
    // Scrolling is applied here (not to Table's own container) so the shared Table primitive stays
    // untouched for every other CRM table. `[&>div]:overflow-visible` neutralises that inner
    // container: its `overflow-x: auto` computes `overflow-y` to `auto`, which would otherwise make
    // it the scroll container and stop the header sticking to this one.
    // `scrollable` is BD-only, so Admin/SA always take the plain (naturally growing) branch.
    <div
      ref={scrollRef}
      style={scrollable && maxHeight ? { maxHeight } : undefined}
      className={
        "overflow-x-auto rounded-lg border border-border bg-card shadow-card" +
        (scrollable ? ` overflow-y-auto scroll-smooth [&>div]:overflow-visible ${scrollbarClass}` : "")
      }
    >
      <Table>
        {/* `bg-card` keeps the pinned header opaque over the rows passing beneath — it matches the
            container's own background, so the header looks identical to today when not scrolling
            and the selected-state row's translucent `bg-primary/5` composites exactly as before. */}
        <TableHeader className="sticky top-0 z-10 bg-card">
          {selectedCount > 0 ? (
            <TableRow className="border-b border-border-subtle bg-primary/5 hover:bg-primary/5">
              <TableHead className={th + " w-10"}>
                <Checkbox
                  aria-label="Select all"
                  checked={allSelected}
                  indeterminate={someSelected}
                  onCheckedChange={onToggleSelectAll}
                  disabled={bulkBusy}
                />
              </TableHead>
              <TableHead colSpan={totalCols - 1} className={cn(density.head.standard, "px-3")}>
                <div className="flex flex-wrap items-center gap-3 text-sm normal-case tracking-normal">
                  <span className="font-semibold text-foreground">
                    {selectedCount} Lead{selectedCount === 1 ? "" : "s"} Selected
                  </span>
                  {/* BD ONLY, and the inverse of the two gates below it: a BD's bulk bar was
                      Export and Clear alone, so working a batch of leads through a calling or
                      emailing session meant opening each one. Admin/SA are excluded on purpose —
                      this task did not touch their bar, and they keep Delete and Reassign in the
                      exact positions they already know. Every PATCH is still authorized per lead
                      (crm.leads.edit + ownership), so this widens no one's reach. */}
                  {!canAssign ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={onBulkStatus}
                      disabled={bulkBusy}
                    >
                      Status
                    </Button>
                  ) : null}
                  {/* Same `canAssign` gate as the per-row Delete directly below and as bulk
                      Reassign beside it. Selection itself is open to every role, so without this a
                      BD could tick rows, see a destructive button, and collect a 403 from each
                      DELETE /leads/{id} — the server was never at risk, but the control should not
                      have been offered. It arrived ungated with the RC3 sync (571866f1) and was
                      simply missed when c59584d4 gated the bulk Reassign it was adding. */}
                  {/* Both deletions live behind ONE menu rather than as two loose buttons: a
                      destructive control that acts on the whole pipeline should not sit in the bar
                      at the same weight as one that acts on the ticked rows, one click away from
                      each other. Each item still opens its own confirmation — the menu never acts. */}
                  {canAssign ? (
                    <DropdownMenu>
                      <DropdownMenuTrigger
                        className={cn(
                          buttonVariants({ variant: "destructive", size: "sm" }),
                          "gap-1",
                        )}
                        disabled={bulkBusy}
                        aria-label="Bulk actions"
                      >
                        Bulk Actions
                        <ChevronDown className="h-3.5 w-3.5" aria-hidden />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="start" className="w-56">
                        <DropdownMenuItem onClick={onBulkDelete}>
                          <Trash2 className="mr-2 h-3.5 w-3.5" aria-hidden />
                          Delete Selected ({selectedCount})
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {/* Deliberately last and separated: it ignores the selection entirely. */}
                        <DropdownMenuItem
                          onClick={onBulkDeleteAll}
                          className="text-destructive focus:text-destructive"
                        >
                          <Trash2 className="mr-2 h-3.5 w-3.5" aria-hidden />
                          Delete All Leads
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  ) : null}
                  {/* Bulk ownership assignment is Admin/SA only — same `canAssign` gate as the
                      per-row Assign action and the Assigned BD column, so a BD never sees it (and
                      the PATCH /leads/{id}/assign route would reject them anyway). */}
                  {canAssign ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={onBulkAssign}
                      disabled={bulkBusy}
                    >
                      {bulkAssigning ? "Reassigning…" : `Reassign Selected (${selectedCount})`}
                    </Button>
                  ) : null}
                  {/* Not role-gated, unlike the two actions above it: exporting is reading, and
                      the rows are already on screen. `GET /leads` is ownership-scoped server-side,
                      so a BD's selection can only contain their own leads. Placed after Reassign so
                      Delete and Reassign keep the exact positions Admin/SA already know. */}
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onBulkExport}
                    disabled={bulkBusy}
                  >
                    Export Selected
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onClearSelection}
                    disabled={bulkBusy}
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
              {columnOrder.map((key) => (
                <Fragment key={key}>{headerCells[key]}</Fragment>
              ))}
            </TableRow>
          )}
        </TableHeader>
        <TableBody ref={bodyRef}>
          {leads && leads.length > 0 ? (
            leads.map((lead) => {
              // Same authoring pattern as the header: one definition per cell, order decided by
              // `columnOrder`. Markup is identical for both audiences.
              const cells: Record<LeadColumnKey, ReactNode> = {
                // Business: avatar initial + name over industry, per the approved design.
                // `industry` already ships on the Lead payload — no API change.
                business: (
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
                ),
                // Contact: name, email, phone stacked — all three already on the payload.
                contact: (
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
                ),
                assigned: (
                  <TableCell className={td}>
                    {lead.assignedTo ? (
                      <span className="inline-flex items-center gap-1.5">
                        <span className="text-secondary-foreground">{lead.assignedTo.name}</span>
                        {/* Suspended owner — the lead is stranded until an Admin reassigns it.
                            Same `bg-muted`/`text-muted-foreground` pairing the Team Accounts
                            StatusPill uses for this state, so it is correct in both themes with no
                            new colour of its own. */}
                        {lead.assignedTo.suspended ? (
                          <span
                            className="inline-flex items-center rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
                            title="This BD's Zerem access is suspended"
                          >
                            Suspended
                          </span>
                        ) : null}
                      </span>
                    ) : (
                      <span className="text-muted-foreground italic">Unassigned</span>
                    )}
                  </TableCell>
                ),
                priority: (
                  <TableCell className={td}>
                    <PriorityChip priority={lead.priority} />
                  </TableCell>
                ),
                email: (
                  <TableCell className={td}>
                    <span className={chipClass + " " + emailConfig[lead.emailOutreach].className}>
                      {emailConfig[lead.emailOutreach].label}
                    </span>
                  </TableCell>
                ),
                call: (
                  <TableCell className={td}>
                    <span className={chipClass + " " + callConfig[lead.coldCalling].className}>
                      {callConfig[lead.coldCalling].label}
                    </span>
                  </TableCell>
                ),
                status: (
                  <TableCell className={td}>
                    <StatusChip status={lead.updateStatus} />
                  </TableCell>
                ),
                activity: (
                  <TableCell className={td + " text-muted-foreground"}>
                    {formatDateLong(lead.lastActivity)}
                  </TableCell>
                ),
                action: (
                  <TableCell className={td}>
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
                      {/* Admin/Super Admin keep Delete. The BD table swaps it for Export PDF, which
                          downloads only this lead via the shared single-lead export endpoint. */}
                      {canAssign ? (
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
                      ) : (
                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            onExportPdf(lead);
                          }}
                          disabled={exportingPdfId === lead.id}
                          className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                          aria-label={`Export ${lead.business} as PDF`}
                        >
                          {exportingPdfId === lead.id ? (
                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          ) : (
                            <Download className="h-3.5 w-3.5" />
                          )}
                        </button>
                      )}
                    </div>
                  </TableCell>
                ),
              };

              return (
                <TableRow
                  key={lead.id}
                  data-selected={selectedIds.has(lead.id) ? "" : undefined}
                  className="cursor-pointer border-b border-border-subtle transition-colors hover:bg-muted data-[selected]:bg-primary/5"
                  onClick={() => router.push(`/zerem/leads/${lead.id}`)}
                >
                  <TableCell className={td}>
                    {/* stopPropagation so ticking a row doesn't trigger the row's navigation. */}
                    <span
                      className="inline-flex"
                      onClick={(e) => e.stopPropagation()}
                    >
                      <Checkbox
                        aria-label={`Select ${lead.business}`}
                        checked={selectedIds.has(lead.id)}
                        onCheckedChange={() => onToggleSelect(lead.id)}
                        disabled={bulkBusy}
                      />
                    </span>
                  </TableCell>
                  {columnOrder.map((key) => (
                    <Fragment key={key}>{cells[key]}</Fragment>
                  ))}
                </TableRow>
              );
            })
          ) : (
            <TableRow>
              <TableCell
                colSpan={totalCols}
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
