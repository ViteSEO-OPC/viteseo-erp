"use client";

import { useEffect, useMemo, useState } from "react";
import { LeadsKpiRow } from "@/modules/crm/features/leads/components/leads-kpi-row";
import { LeadsSummaryTabs } from "@/modules/crm/features/leads/components/leads-summary-tabs";
import { LeadsToolbar } from "@/modules/crm/features/leads/components/leads-toolbar";
import { LeadsFilterBar } from "@/modules/crm/features/leads/components/leads-filter-bar";
import { LeadsTable, MAX_VISIBLE_ROWS } from "@/modules/crm/features/leads/components/leads-table";
import { Pagination } from "@/modules/crm/components/shared/pagination/pagination";
import { ImportLeadsModal } from "@/modules/crm/features/leads/components/import-leads-modal";
import { CreateLeadWizard } from "@/modules/crm/features/leads/components/create-lead-wizard";
import { EditLeadDrawer } from "@/modules/crm/features/leads/components/edit-lead-drawer";
import { AssignLeadModal } from "@/modules/crm/features/leads/components/assign-lead-modal";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useLeads, LEAD_UNASSIGNED } from "@/modules/crm/features/leads/hooks/use-leads-data";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { useExportLeadPdf } from "@/modules/crm/features/leads/hooks/use-export-lead-pdf";
import { ReportsScopeSelector } from "@/modules/crm/features/reports/components/reports-scope-selector";
import { ALL_SCOPE } from "@/modules/crm/features/performance/lib/performance-scope";
import type { PerformanceScope } from "@/modules/crm/features/performance/lib/performance-scope";
import { useNicheDistribution } from "@/modules/crm/features/leads/hooks/use-niche-distribution";
import { useExportLeads } from "@/modules/crm/features/leads/hooks/use-export-leads";
import { downloadLeadsCsv } from "@/modules/crm/features/leads/lib/leads-csv";
import { notify as toast } from "@/core/lib/notify";
import { BulkAssignBdModal } from "@/modules/crm/features/leads/components/bulk-assign-bd-modal";
import { BulkStatusModal } from "@/modules/crm/features/leads/components/bulk-status-modal";
import {
  useDeleteLead,
  useBulkDeleteLeads,
  useDeleteAllLeads,
  useDeletableLeadCount,
  useBulkAssignLeads,
  useBulkUpdateLeadStatus,
} from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import { useHeaderSearch } from "@/modules/crm/providers/header-search-provider";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

export function LeadsPageLayout() {
  const [importOpen, setImportOpen] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [editLead, setEditLead] = useState<Lead | null>(null);
  const [assignLead, setAssignLead] = useState<Lead | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Lead | null>(null);
  // Bulk selection lives here (alongside the single-delete state) and is scoped to the visible
  // page — the reset effect below clears it whenever the page or filters change.
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [bulkOpen, setBulkOpen] = useState(false);
  // "Archive all leads" is a separate dialog from the shared delete one below, not another mode of
  // it: it needs its own copy, a server-supplied count, and an acknowledgement the others do not.
  const [deleteAllOpen, setDeleteAllOpen] = useState(false);
  const [deleteAllAcknowledged, setDeleteAllAcknowledged] = useState(false);
  const [bulkStatusOpen, setBulkStatusOpen] = useState(false);
  // Bulk Assign BD is a two-step flow: confirm the intent, THEN pick the BD. Two flags rather than
  // one so Cancel on the confirmation never opens the picker.
  const [bulkAssignConfirmOpen, setBulkAssignConfirmOpen] = useState(false);
  const [bulkAssignPickerOpen, setBulkAssignPickerOpen] = useState(false);
  const deleteLead = useDeleteLead();
  const bulkDelete = useBulkDeleteLeads();
  const deleteAll = useDeleteAllLeads();
  // Fetched only while the dialog is open — a count for a decision, not page furniture.
  const deletableCount = useDeletableLeadCount(deleteAllOpen);
  const bulkAssign = useBulkAssignLeads();
  const bulkStatus = useBulkUpdateLeadStatus();
  // Assign/Reassign ownership is Admin/SA only (leads.assign is not held by BD).
  const { hasMinRole } = usePermissions();
  const canAssign = hasMinRole("crm_admin");

  const handleDelete = () => {
    if (!deleteTarget) return;
    deleteLead.mutate(deleteTarget.id, {
      onSuccess: () => setDeleteTarget(null),
    });
  };

  const toggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const clearSelection = () => setSelectedIds(new Set());

  const handleBulkDelete = () => {
    const ids = Array.from(selectedIds);
    if (ids.length === 0) return;
    bulkDelete.mutate(ids, {
      // Drop only the successfully-deleted ids; any that failed stay selected so the user can
      // retry — the selection state is never corrupted by a partial failure.
      onSuccess: ({ succeeded }) => {
        setSelectedIds((prev) => {
          const next = new Set(prev);
          succeeded.forEach((id) => next.delete(id));
          return next;
        });
        setBulkOpen(false);
      },
    });
  };

  const openDeleteAll = () => {
    // The acknowledgement resets every time, so a previous confirmation can never carry over into
    // a later one.
    setDeleteAllAcknowledged(false);
    setDeleteAllOpen(true);
  };

  const handleDeleteAll = () => {
    deleteAll.mutate(undefined, {
      onSuccess: () => {
        setDeleteAllOpen(false);
        // Every lead in scope is gone from the live list, so any surviving selection refers to rows
        // that are no longer there.
        setSelectedIds(new Set());
      },
    });
  };

  // Export the CURRENT SELECTION, as distinct from the toolbar Export above it, which walks every
  // page of the active filter. Purely client-side: the rows are already in `data`, so this reuses
  // the same downloadLeadsCsv the filtered export uses and issues no request at all. Nothing is
  // exported that the caller could not already read — `GET /leads` is ownership-scoped server-side,
  // so a BD's selection can only ever contain their own leads.
  //
  // Selection stays page-scoped (unchanged), so this exports the ticked rows on this page and the
  // selection is deliberately left intact afterwards — an export is not destructive and re-exporting
  // or continuing to act on the same rows is the likelier next step.
  const handleBulkExport = () => {
    const rows = (data ?? []).filter((lead) => selectedIds.has(lead.id));
    if (rows.length === 0) return;
    const today = new Date().toISOString().slice(0, 10);
    downloadLeadsCsv(rows, `leads-selected-${today}.csv`);
    toast.success(`Exported ${rows.length} lead${rows.length === 1 ? "" : "s"}.`);
  };

  // Set one status across the selection. Mirrors handleBulkDelete/handleBulkAssign exactly: the
  // mutation fans out the existing PATCH /leads/{id} per lead, and only the ids that actually took
  // the new status leave the selection — any that failed stay ticked for a retry.
  //
  // The per-lead fan-out is intentional. `status` is a LeadObserver SCORING_INPUT, so each PATCH
  // re-runs LeadScorer::refresh and writes its own `updated` Activity. A bulk change therefore
  // leaves exactly the scores and audit trail that changing the leads one by one would — nothing
  // is bypassed, batched away, or written directly.
  const handleBulkStatus = (status: string) => {
    const ids = Array.from(selectedIds);
    if (ids.length === 0) return;
    bulkStatus.mutate(
      { ids, status },
      {
        onSuccess: ({ succeeded }) => {
          setSelectedIds((prev) => {
            const next = new Set(prev);
            succeeded.forEach((id) => next.delete(id));
            return next;
          });
          setBulkStatusOpen(false);
        },
      },
    );
  };

  const handleBulkAssign = (bdId: string, bdName: string) => {
    const ids = Array.from(selectedIds);
    if (ids.length === 0) return;
    bulkAssign.mutate(
      { ids, assignedTo: bdId, bdName },
      {
        // Mirrors the bulk delete: drop only the ids that actually took the new owner, so any that
        // failed stay selected for a retry instead of silently disappearing from the selection.
        onSuccess: ({ succeeded }) => {
          setSelectedIds((prev) => {
            const next = new Set(prev);
            succeeded.forEach((id) => next.delete(id));
            return next;
          });
          setBulkAssignPickerOpen(false);
        },
      },
    );
  };
  // Single source of truth for the list: one useLeads instance drives the
  // table (results) and the pager, and receives the search term from the
  // global header search.
  const {
    data,
    total,
    page,
    perPage,
    totalPages,
    goToPage,
    clearFilters,
    hasActiveFilters,
    listParams,
    industryCounts,
    search,
    setSearch,
    sort,
    setSort,
    selectedIndustries,
    toggleIndustry,
    priority,
    setPriority,
    status,
    setStatus,
    emailOutreach,
    setEmailOutreach,
    coldCalling,
    setColdCalling,
    assignedTo,
    setAssignedTo,
    isLoading,
    isError,
    retry,
  } = useLeads();

  // More than one page of results → page controls. BD's table also caps its viewport at
  // MAX_VISIBLE_ROWS and scrolls beyond that, which is the other case where the summary is useful.
  const paginated = total > perPage;
  const scrolled = !canAssign && (data?.length ?? 0) > MAX_VISIBLE_ROWS;

  // Single-lead PDF export, shared with Lead Details — one hook, one endpoint, one implementation.
  const { exportLeadPdf, exportingId } = useExportLeadPdf();

  // Assigned BD filter options — live accounts from the shared useBdUsers hook (the same source the
  // Assign modal uses), so no owner name is ever invented. Guarded by `canAssign`: only Admin/SA see
  // the column, and only they can read GET /users. Labels reuse the column's own cell styling.
  const { bdUsers } = useBdUsers(canAssign);
  const bdOptions = useMemo(
    () => [
      ...bdUsers.map((user) => ({
        value: user.id,
        label: <span className="text-secondary-foreground">{user.name}</span>,
      })),
      {
        value: LEAD_UNASSIGNED,
        label: <span className="text-muted-foreground italic">Unassigned</span>,
      },
    ],
    [bdUsers],
  );

  /**
   * Admin/Super Admin scope tabs, rendered with the Reports & Performance selector rather than a
   * second implementation. The scope is derived from — and written back to — the SAME `assigned_to`
   * URL param the Assigned BD column filter uses, so the tabs add no state, no extra request and no
   * second table: they are another way to set one existing server-side filter.
   *
   * `assigned_to=unassigned` (reachable from the column filter) maps to a scope matching no chip, so
   * nothing is highlighted — marking "All Leads" active there would show a filtered table as unfiltered.
   */
  const leadsScope: PerformanceScope =
    assignedTo === ""
      ? ALL_SCOPE
      : {
          mode: "bd",
          userId: assignedTo,
          name: bdUsers.find((user) => user.id === assignedTo)?.name ?? "",
        };

  const handleScopeChange = (next: PerformanceScope) =>
    setAssignedTo(next.mode === "bd" ? next.userId : "");

  // Select-all / clear targets the leads currently on screen ("current filtered results").
  const toggleSelectAll = (checked: boolean) => {
    setSelectedIds(checked ? new Set((data ?? []).map((lead) => lead.id)) : new Set());
  };

  // Selection is per-page: reset it whenever the page or the filter/sort/search set changes so
  // the "N Selected" count always matches the visible rows. Uses React's "adjust state during
  // render" pattern (keyed on a signature) rather than an effect. A successful delete keeps the
  // same page + filters (it only invalidates the query), so this won't clear the survivors.
  const selectionKey = `${page}::${JSON.stringify(listParams)}`;
  const [prevSelectionKey, setPrevSelectionKey] = useState(selectionKey);
  if (prevSelectionKey !== selectionKey) {
    setPrevSelectionKey(selectionKey);
    setSelectedIds(new Set());
  }

  // Lead Priority Overview cards mirror the table's dataset: pass the current filters MINUS
  // `priority` (the cards' own drill-down — including it would zero the other two) and MINUS
  // `sort` (order doesn't change which leads are counted). The backend applies the same
  // own-dimension rule (`applyPriority: false`), so this is belt-and-braces and also keeps the
  // query key stable while drilling. React Query hashes the key by value, so a fresh object per
  // render is fine.
  const summaryFilters: Record<string, string> = Object.fromEntries(
    Object.entries(listParams).filter(([key]) => key !== "priority" && key !== "sort"),
  );

  /**
   * Filters for the status tabs: everything the list is using except `status` — which the endpoint
   * deliberately ignores so the tabs stay a switcher — and `sort`, which changes ordering but never
   * the set. Dropping both keeps one cache entry per real dataset instead of refetching identical
   * counts on every tab click and sort change.
   */
  const statusSummaryFilters: Record<string, string> = Object.fromEntries(
    Object.entries(listParams).filter(([key]) => key !== "status" && key !== "sort"),
  );

  // Niche distribution uses the API's real per-industry totals (industry_counts) — accurate and
  // page-independent — feeding the chips + bar + summary. `total` (meta.total) drives "Showing X".
  const nicheDistribution = useNicheDistribution(industryCounts, total);

  // Export mirrors the current filter/sort state (reuses listParams); client-side, no new endpoint.
  const { exportLeads, isExporting } = useExportLeads();

  // Search lives in the approved global header search. Register this page to drive that input.
  const headerSearch = useHeaderSearch();
  const { value: headerValue, setValue: setHeaderValue, setRegistered } = headerSearch;

  useEffect(() => {
    setRegistered(true);
    return () => {
      setRegistered(false);
      setHeaderValue("");
    };
  }, [setRegistered, setHeaderValue]);

  // Keep the header input reflecting the URL-driven search term. So when a summary drill-down
  // clears ?search=, the input clears too (and the debounce below won't re-apply the old term).
  useEffect(() => {
    setHeaderValue(search);
  }, [search, setHeaderValue]);

  // Debounce the header input (300ms) into the URL ?search= (via router.replace).
  useEffect(() => {
    const timeout = setTimeout(() => setSearch(headerValue), 300);
    return () => clearTimeout(timeout);
  }, [headerValue, setSearch]);

  return (
    <>
      <div className="flex flex-col gap-6">
        {/* Lead Priority Overview (website-status cards) — BD only. The approved Admin/Super Admin
            layout leads with the BD tabs instead, so the cards are not rendered for that audience;
            not rendering them also skips their /dashboard/priority-buckets request. The BD page is
            unchanged, and the Dashboard keeps its own copy of these cards either way. */}
        {/* `listParams.priority` is the validated, URL-driven value the list itself queries on, so
            the selected card and the filtered table can never disagree. */}
        {canAssign ? null : (
          <LeadsKpiRow
            filters={summaryFilters}
            activePriority={listParams.priority ?? ""}
          />
        )}
        <div className="flex flex-col gap-4">
          {/* Admin/Super Admin only — a BD never mounts this, so their Leads page is unchanged. */}
          {canAssign ? (
            <ReportsScopeSelector
              scope={leadsScope}
              onChange={handleScopeChange}
              allLabel="All Leads"
              ariaLabel="Filter leads by assigned Business Development Manager"
            />
          ) : null}
          <div className="flex flex-wrap items-center justify-between gap-3">
            {/* Admin/Super Admin: counts follow the list filters (BD tabs included). A BD passes
                nothing, so their tabs behave exactly as before. */}
            <LeadsSummaryTabs scopeParams={canAssign ? statusSummaryFilters : undefined} />
            <LeadsToolbar
              onCreate={() => setCreateOpen(true)}
              onImport={() => setImportOpen(true)}
              onExport={() => exportLeads(listParams)}
              exporting={isExporting}
            />
          </div>
          <LeadsFilterBar
            nicheDistribution={nicheDistribution}
            selectedNiches={selectedIndustries}
            onToggleNiche={toggleIndustry}
            sort={sort}
            onSortChange={setSort}
            onClear={clearFilters}
            clearDisabled={!hasActiveFilters}
          />
          <LeadsTable
            leads={data}
            isLoading={isLoading}
            isError={isError}
            onRetry={retry}
            searchActive={
              search.trim().length > 0 ||
              priority.length > 0 ||
              status.length > 0 ||
              emailOutreach.length > 0 ||
              coldCalling.length > 0 ||
              assignedTo.length > 0
            }
            onEdit={(lead) => setEditLead(lead)}
            onDelete={(lead) => setDeleteTarget(lead)}
            canAssign={canAssign}
            onAssign={(lead) => setAssignLead(lead)}
            selectedIds={selectedIds}
            onToggleSelect={toggleSelect}
            onToggleSelectAll={toggleSelectAll}
            onClearSelection={clearSelection}
            onBulkDelete={() => setBulkOpen(true)}
            onBulkDeleteAll={openDeleteAll}
            onBulkExport={handleBulkExport}
            onBulkStatus={() => setBulkStatusOpen(true)}
            bulkDeleting={bulkDelete.isPending}
            onBulkAssign={() => setBulkAssignConfirmOpen(true)}
            bulkAssigning={bulkAssign.isPending}
            priorityFilter={priority}
            onPriorityChange={setPriority}
            statusFilter={status}
            onStatusChange={setStatus}
            emailFilter={emailOutreach}
            onEmailChange={setEmailOutreach}
            callFilter={coldCalling}
            onCallChange={setColdCalling}
            assignedToFilter={assignedTo}
            onAssignedToChange={setAssignedTo}
            bdOptions={bdOptions}
            onExportPdf={(lead) => exportLeadPdf(lead.id, lead.business)}
            exportingPdfId={exportingId}
          />
          {/* Page controls appear only once the result set outgrows a single page. The "Showing
              X–Y of Z" summary appears a step earlier — as soon as the list stops showing every row
              at once, which for BD also covers a page that fits but scrolls inside its ~5-row
              viewport. Admin/SA never scroll, so for them the two conditions coincide and the whole
              pager still appears only above `perPage` — unchanged. Both read the API's filtered
              `total`, so they re-evaluate on every search/filter change. */}
          {paginated || scrolled ? (
            <Pagination
              page={page}
              totalPages={totalPages}
              total={total}
              perPage={perPage}
              onPageChange={goToPage}
              showControls={paginated}
              unit="leads"
            />
          ) : null}
        </div>
      </div>

      <ImportLeadsModal
        open={importOpen}
        onOpenChange={setImportOpen}
      />

      <CreateLeadWizard
        open={createOpen}
        onOpenChange={setCreateOpen}
      />

      <EditLeadDrawer
        open={!!editLead}
        onOpenChange={(o) => {
          if (!o) setEditLead(null);
        }}
        lead={editLead}
      />

      {assignLead ? (
        <AssignLeadModal
          key={assignLead.id}
          open
          onOpenChange={(o) => {
            if (!o) setAssignLead(null);
          }}
          lead={assignLead}
        />
      ) : null}

      {/* Step 1 of Bulk Assign BD: confirm the intent before any picker or request. Cancel simply
          closes — nothing is opened and no lead is touched. */}
      <ConfirmDialog
        open={bulkAssignConfirmOpen}
        onOpenChange={setBulkAssignConfirmOpen}
        title="Assign Selected Leads"
        description="Are you sure you want to assign the selected leads to a Business Development representative?"
        confirmLabel="Assign"
        onConfirm={() => {
          setBulkAssignConfirmOpen(false);
          setBulkAssignPickerOpen(true);
        }}
      />

      {/* Step 2: pick the one BD who takes ownership. Mounted only while open so the picker starts
          empty on every run (no stale selection, and no key needed to force that). */}
      {bulkAssignPickerOpen ? (
        <BulkAssignBdModal
          open
          onOpenChange={(o) => {
            if (!o) setBulkAssignPickerOpen(false);
          }}
          count={selectedIds.size}
          onConfirm={handleBulkAssign}
          pending={bulkAssign.isPending}
        />
      ) : null}

      {/* Bulk status picker (BD only — the button that opens it is gated in the bulk bar). Mounted
          only while open so it starts empty on every run, same as the bulk-assign picker above. */}
      {bulkStatusOpen ? (
        <BulkStatusModal
          open={bulkStatusOpen}
          onOpenChange={setBulkStatusOpen}
          count={selectedIds.size}
          onConfirm={handleBulkStatus}
          pending={bulkStatus.isPending}
        />
      ) : null}

      {/* Archive all leads — its own dialog, because it is the one action here whose scope is not
          what the user selected. The copy leads with what it actually does (archive, recoverable)
          rather than with the word "delete", and the acknowledgement makes confirming a deliberate
          act rather than a second click in the same place as Cancel. */}
      <ConfirmDialog
        open={deleteAllOpen}
        onOpenChange={(o) => {
          if (o) return;
          if (deleteAll.isPending) return;
          setDeleteAllOpen(false);
        }}
        title="Archive all leads?"
        description="This affects every lead you have access to — not just the ones selected, and not just this page."
        confirmLabel="Archive All Leads"
        onConfirm={handleDeleteAll}
        loading={deleteAll.isPending}
        confirmDisabled={!deleteAllAcknowledged}
        destructive
      >
        <div className="flex flex-col gap-3 text-sm">
          <p className="text-muted-foreground">
            {deletableCount.isLoading
              ? "Counting affected leads…"
              : deletableCount.isError || deletableCount.count === undefined
                ? "Every lead in your scope will be moved to Archive."
                : deletableCount.count === 0
                  ? "There are no leads to archive."
                  : null}
            {!deletableCount.isLoading &&
            !deletableCount.isError &&
            typeof deletableCount.count === "number" &&
            deletableCount.count > 0 ? (
              <>
                <span className="font-semibold text-card-foreground">
                  {deletableCount.count.toLocaleString("en-PH")} lead
                  {deletableCount.count === 1 ? "" : "s"}
                </span>{" "}
                will be moved to Archive.
              </>
            ) : null}
          </p>
          {/* The reassurance is the point: without it "delete all" reads as destruction, and the
              team's instinct is to never touch it even when archiving is what they want. */}
          <p className="text-muted-foreground">
            They are <span className="font-medium text-card-foreground">not permanently deleted</span>{" "}
            — you can find them under Archive and restore them from there.
          </p>
          <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-border bg-muted p-3">
            <input
              type="checkbox"
              checked={deleteAllAcknowledged}
              onChange={(e) => setDeleteAllAcknowledged(e.target.checked)}
              disabled={deleteAll.isPending}
              className="mt-0.5 h-4 w-4 shrink-0 accent-teal-400"
            />
            <span className="text-xs text-card-foreground">
              I understand this archives every lead I have access to.
            </span>
          </label>
        </div>
      </ConfirmDialog>

      {/* One shared ConfirmDialog serves both single- and bulk-delete; only its text switches. */}
      <ConfirmDialog
        open={bulkOpen || !!deleteTarget}
        onOpenChange={(o) => {
          if (o) return;
          // Guard against Esc/overlay-dismiss while a delete is in flight.
          if (bulkOpen ? bulkDelete.isPending : deleteLead.isPending) return;
          setBulkOpen(false);
          setDeleteTarget(null);
        }}
        title={
          bulkOpen
            ? `Delete ${selectedIds.size} selected lead${selectedIds.size === 1 ? "" : "s"}?`
            : "Delete lead?"
        }
        description={
          bulkOpen
            ? "This action cannot be undone."
            : deleteTarget
              ? `"${deleteTarget.business}" will be deleted. This can't be undone.`
              : ""
        }
        confirmLabel={bulkOpen ? "Delete Selected" : "Delete Lead"}
        onConfirm={bulkOpen ? handleBulkDelete : handleDelete}
        loading={bulkOpen ? bulkDelete.isPending : deleteLead.isPending}
        destructive
      />
    </>
  );
}
