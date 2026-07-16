"use client";

import { useEffect, useState } from "react";
import { LeadsKpiRow } from "@/modules/crm/features/leads/components/leads-kpi-row";
import { LeadsSummaryTabs } from "@/modules/crm/features/leads/components/leads-summary-tabs";
import { LeadsToolbar } from "@/modules/crm/features/leads/components/leads-toolbar";
import { LeadsFilterBar } from "@/modules/crm/features/leads/components/leads-filter-bar";
import { LeadsTable } from "@/modules/crm/features/leads/components/leads-table";
import { Pagination } from "@/modules/crm/features/leads/components/pagination";
import { ImportLeadsModal } from "@/modules/crm/features/leads/components/import-leads-modal";
import { CreateLeadWizard } from "@/modules/crm/features/leads/components/create-lead-wizard";
import { EditLeadDrawer } from "@/modules/crm/features/leads/components/edit-lead-drawer";
import { AssignLeadModal } from "@/modules/crm/features/leads/components/assign-lead-modal";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useLeads } from "@/modules/crm/features/leads/hooks/use-leads-data";
import { useNicheDistribution } from "@/modules/crm/features/leads/hooks/use-niche-distribution";
import { useExportLeads } from "@/modules/crm/features/leads/hooks/use-export-leads";
import { useDeleteLead } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import { useHeaderSearch } from "@/modules/crm/providers/header-search-provider";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

export function LeadsPageLayout() {
  const [importOpen, setImportOpen] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [editLead, setEditLead] = useState<Lead | null>(null);
  const [assignLead, setAssignLead] = useState<Lead | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Lead | null>(null);
  const deleteLead = useDeleteLead();
  // Assign/Reassign ownership is Admin/SA only (leads.assign is not held by BD).
  const { hasMinRole } = usePermissions();
  const canAssign = hasMinRole("crm_admin");

  const handleDelete = () => {
    if (!deleteTarget) return;
    deleteLead.mutate(deleteTarget.id, {
      onSuccess: () => setDeleteTarget(null),
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
    status,
    isLoading,
    isError,
    retry,
  } = useLeads();

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
        <LeadsKpiRow />
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
            searchActive={search.trim().length > 0 || priority.length > 0 || status.length > 0}
            onEdit={(lead) => setEditLead(lead)}
            onDelete={(lead) => setDeleteTarget(lead)}
            canAssign={canAssign}
            onAssign={(lead) => setAssignLead(lead)}
          />
          <Pagination
            page={page}
            totalPages={totalPages}
            total={total}
            onPageChange={goToPage}
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

      <ConfirmDialog
        open={!!deleteTarget}
        onOpenChange={(o) => {
          if (!o) setDeleteTarget(null);
        }}
        title="Delete lead?"
        description={
          deleteTarget
            ? `"${deleteTarget.business}" will be deleted. This can't be undone.`
            : ""
        }
        confirmLabel="Delete Lead"
        onConfirm={handleDelete}
        loading={deleteLead.isPending}
        destructive
      />
    </>
  );
}
