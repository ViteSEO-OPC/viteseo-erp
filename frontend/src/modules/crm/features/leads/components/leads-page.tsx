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
import { BulkAssignBdModal } from "@/modules/crm/features/leads/components/bulk-assign-bd-modal";
import {
  useDeleteLead,
  useBulkDeleteLeads,
  useBulkAssignLeads,
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
  // Bulk Assign BD is a two-step flow: confirm the intent, THEN pick the BD. Two flags rather than
  // one so Cancel on the confirmation never opens the picker.
  const [bulkAssignConfirmOpen, setBulkAssignConfirmOpen] = useState(false);
  const [bulkAssignPickerOpen, setBulkAssignPickerOpen] = useState(false);
  const deleteLead = useDeleteLead();
  const bulkDelete = useBulkDeleteLeads();
  const bulkAssign = useBulkAssignLeads();
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
