"use client";

import { useEffect, useMemo, useState } from "react";
import { LeadsKpiRow } from "@/modules/crm/features/leads/components/leads-kpi-row";
import { LeadsSummaryTabs } from "@/modules/crm/features/leads/components/leads-summary-tabs";
import { LeadsToolbar } from "@/modules/crm/features/leads/components/leads-toolbar";
import { LeadsFilterBar } from "@/modules/crm/features/leads/components/leads-filter-bar";
import { LeadsTable } from "@/modules/crm/features/leads/components/leads-table";
import { Pagination } from "@/modules/crm/components/shared/pagination/pagination";
import { ImportLeadsModal } from "@/modules/crm/features/leads/components/import-leads-modal";
import { CreateLeadWizard } from "@/modules/crm/features/leads/components/create-lead-wizard";
import { EditLeadDrawer } from "@/modules/crm/features/leads/components/edit-lead-drawer";
import { AssignLeadModal } from "@/modules/crm/features/leads/components/assign-lead-modal";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useLeads, LEAD_UNASSIGNED } from "@/modules/crm/features/leads/hooks/use-leads-data";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { useNicheDistribution } from "@/modules/crm/features/leads/hooks/use-niche-distribution";
import { useExportLeads } from "@/modules/crm/features/leads/hooks/use-export-leads";
import { useDeleteLead, useBulkDeleteLeads } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
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
  const deleteLead = useDeleteLead();
  const bulkDelete = useBulkDeleteLeads();
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
  // Single source of truth for the list: one useLeads instance drives the
  // table (results) and the pager, and receives the search term from the
  // global header search.
  const {
    data,
    total,
    page,
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
  // `website_status` (the cards' own drill-down — including it would zero the other two) and MINUS
  // `sort` (order doesn't change which leads are counted). The backend applies the same
  // own-dimension rule, so this is belt-and-braces and also keeps the query key stable while
  // drilling. React Query hashes the key by value, so a fresh object per render is fine.
  const summaryFilters: Record<string, string> = Object.fromEntries(
    Object.entries(listParams).filter(([key]) => key !== "website_status" && key !== "sort"),
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
        <LeadsKpiRow filters={summaryFilters} />
        <div className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <LeadsSummaryTabs />
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
          />
          <Pagination
            page={page}
            totalPages={totalPages}
            total={total}
            onPageChange={goToPage}
            unit="leads"
          />
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
