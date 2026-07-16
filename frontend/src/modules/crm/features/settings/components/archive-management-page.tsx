"use client";

import { useMemo, useState } from "react";
import { RotateCcw } from "lucide-react";
import { SearchInput } from "@/modules/crm/components/shared/search-input/search-input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import { RelationshipSelect } from "@/modules/crm/components/shared/relationship-select/relationship-select";
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
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { TableSkeleton } from "@/modules/crm/features/dashboard/components/table-skeleton";
import { Pagination } from "@/modules/crm/components/shared/pagination/pagination";
import { initialsOf } from "@/modules/crm/features/settings/components/account-chips";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import {
  useRestoreLead,
  useRestoreLeads,
} from "@/modules/crm/features/settings/hooks/use-archive-mutations";
import { useArchivedLeads } from "@/modules/crm/features/settings/hooks/use-archived-leads";
import type { ArchiveFilters } from "@/modules/crm/features/settings/types/archive-types";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";

const PER_PAGE = 10;

const STATUS_OPTIONS = [
  { value: "new", label: "New" },
  { value: "interested", label: "Interested" },
  { value: "awaiting-reply", label: "Awaiting Reply" },
  { value: "call-back-req", label: "Call-back Req." },
  { value: "declined", label: "Declined" },
];

/** Confirmation target: one lead, the checked rows, or the whole filtered set. */
type RestoreTarget =
  | { mode: "single"; id: string; label: string }
  | { mode: "selected" }
  | { mode: "all" };

function formatArchivedDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

export function ArchiveManagementPage() {
  const [searchInput, setSearchInput] = useState("");
  const [filters, setFilters] = useState<ArchiveFilters>({});
  const [page, setPage] = useState(0);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [target, setTarget] = useState<RestoreTarget | null>(null);

  const { bdUsers } = useBdUsers();
  const { leads, total, totalArchived, archivers, isLoading, isError, refetch } =
    useArchivedLeads(filters, page);
  const restoreOne = useRestoreLead();
  const restoreMany = useRestoreLeads();

  const restoring = restoreOne.isPending || restoreMany.isPending;
  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

  // Year options — current year back five years. Presentation only; the backend filters on whatever
  // year is sent (deleted_at). "All" clears the filter.
  const years = useMemo(() => {
    const current = new Date().getFullYear();
    return Array.from({ length: 6 }, (_, i) => String(current - i));
  }, []);

  // Any filter change resets to the first page and clears the (page-scoped) selection.
  function updateFilter<K extends keyof ArchiveFilters>(key: K, value: string): void {
    setFilters((prev) => ({ ...prev, [key]: value }));
    setPage(0);
    setSelectedIds(new Set());
  }

  function changePage(next: number): void {
    setPage(next);
    setSelectedIds(new Set());
  }

  // Friendly labels for the value-list filters (year / status). base-ui's SelectValue shows the raw
  // value until its portal items mount, so we render the label ourselves (always correct, never a
  // bare "all"). The entity filters (assigned BD / archived-by) use RelationshipSelect, which owns
  // this same label-resolution — see the Lead Relationship Selector Standard.
  const yearLabel = filters.year && filters.year !== "all" ? filters.year : "All years";
  const statusLabel =
    STATUS_OPTIONS.find((s) => s.value === filters.status)?.label ?? "All statuses";

  const pageIds = leads.map((l) => l.id);
  const selectedCount = pageIds.reduce((n, id) => (selectedIds.has(id) ? n + 1 : n), 0);
  const allSelected = leads.length > 0 && selectedCount === leads.length;
  const someSelected = selectedCount > 0 && !allSelected;

  function toggleSelectAll(): void {
    setSelectedIds(allSelected ? new Set() : new Set(pageIds));
  }

  function toggleSelect(id: string): void {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function confirmRestore(): void {
    if (!target) return;
    const onSuccess = () => {
      setSelectedIds(new Set());
      setTarget(null);
    };
    if (target.mode === "single") {
      restoreOne.mutate(target.id, { onSuccess });
    } else if (target.mode === "selected") {
      restoreMany.mutate({ ids: Array.from(selectedIds) }, { onSuccess });
    } else {
      restoreMany.mutate({ filters }, { onSuccess });
    }
  }

  const confirmCopy = useMemo(() => {
    if (!target) return { title: "", description: "" };
    if (target.mode === "single") {
      return {
        title: "Restore lead?",
        description: `"${target.label}" will return to the active workspace and become editable again.`,
      };
    }
    if (target.mode === "selected") {
      return {
        title: `Restore ${selectedCount} selected lead${selectedCount === 1 ? "" : "s"}?`,
        description: "The selected leads will return to the active workspace.",
      };
    }
    return {
      title: `Restore all ${total} archived lead${total === 1 ? "" : "s"}?`,
      description: "Every lead matching the current filters will return to the active workspace.",
    };
  }, [target, selectedCount, total]);

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <h2 className="text-2xl font-bold text-foreground">Archive Management</h2>
          <span className="text-sm font-medium text-muted-foreground">
            {totalArchived} Archived Lead{totalArchived === 1 ? "" : "s"}
          </span>
        </div>
        <p className="text-sm text-muted-foreground">
          Review archived (soft-deleted) leads and restore them to the active workspace. Restored
          leads become editable again.
        </p>
      </header>

      {/* Top bar — search + filters + restore-all. */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-1 flex-wrap items-center gap-2">
          <SearchInput
            className="w-full sm:w-64"
            value={searchInput}
            onChange={setSearchInput}
            onSubmit={(v) => updateFilter("search", v)}
            placeholder="Search archived leads…"
            aria-label="Search archived leads"
          />

          <Select value={filters.year ?? "all"} onValueChange={(v) => updateFilter("year", (v as string) ?? "all")}>
            <SelectTrigger className="w-36" aria-label="Filter by year">
              <SelectValue placeholder="Year">{yearLabel}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All years</SelectItem>
              {years.map((y) => (
                <SelectItem key={y} value={y}>{y}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={filters.status ?? "all"} onValueChange={(v) => updateFilter("status", (v as string) ?? "all")}>
            <SelectTrigger className="w-40" aria-label="Filter by status">
              <SelectValue placeholder="Status">{statusLabel}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {STATUS_OPTIONS.map((s) => (
                <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>

          <RelationshipSelect
            value={filters.assignedBd ?? "all"}
            onValueChange={(v) => updateFilter("assignedBd", v || "all")}
            options={[
              { value: "all", label: "All BDs" },
              { value: "unassigned", label: "Unassigned" },
              ...bdUsers.map((u) => ({ value: u.id, label: u.name })),
            ]}
            placeholder="Assigned BD"
            triggerClassName="w-44"
            aria-label="Filter by assigned BD"
          />

          <RelationshipSelect
            value={filters.archivedBy ?? "all"}
            onValueChange={(v) => updateFilter("archivedBy", v || "all")}
            options={[
              { value: "all", label: "Archived by anyone" },
              ...archivers.map((a) => ({ value: a.id, label: a.name })),
            ]}
            placeholder="Archived by"
            triggerClassName="w-44"
            aria-label="Filter by who archived"
          />
        </div>

        <Button
          variant="teal-outline"
          size="sm"
          disabled={total === 0 || restoring}
          onClick={() => setTarget({ mode: "all" })}
        >
          <RotateCcw aria-hidden />
          Restore all filtered
        </Button>
      </div>

      {/* Bulk-action bar — appears only when rows on this page are selected. */}
      {selectedCount > 0 ? (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2">
          <span className="text-sm font-medium text-foreground">
            {selectedCount} selected
          </span>
          <Button
            variant="default"
            size="sm"
            disabled={restoring}
            onClick={() => setTarget({ mode: "selected" })}
          >
            <RotateCcw aria-hidden />
            Restore Selected
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setSelectedIds(new Set())}>
            Clear selection
          </Button>
        </div>
      ) : null}

      {/* List. */}
      {isLoading ? (
        <TableSkeleton />
      ) : isError ? (
        <ErrorState message="Failed to load archived leads." onRetry={() => refetch()} />
      ) : leads.length === 0 ? (
        <EmptyState message="No archived leads match these filters." />
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border bg-card shadow-card">
          <Table>
            <TableHeader>
              <TableRow className="bg-surface-table-header hover:bg-surface-table-header">
                <TableHead className="w-10">
                  <Checkbox
                    aria-label="Select all on this page"
                    checked={allSelected}
                    indeterminate={someSelected}
                    onCheckedChange={toggleSelectAll}
                  />
                </TableHead>
                <TableHead>Business</TableHead>
                <TableHead>Contact</TableHead>
                <TableHead>Archived</TableHead>
                <TableHead>Assigned BD</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Archived By</TableHead>
                <TableHead className="text-right">Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {leads.map((lead) => (
                <TableRow
                  key={lead.id}
                  data-state={selectedIds.has(lead.id) ? "selected" : undefined}
                >
                  <TableCell>
                    <Checkbox
                      aria-label={`Select ${lead.business}`}
                      checked={selectedIds.has(lead.id)}
                      onCheckedChange={() => toggleSelect(lead.id)}
                    />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2.5">
                      <AvatarInitials initials={initialsOf(lead.business)} />
                      <span className="font-medium text-foreground">{lead.business}</span>
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{lead.contact ?? "—"}</TableCell>
                  <TableCell className="text-muted-foreground">
                    {formatArchivedDate(lead.archivedAt)}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {lead.assignedTo?.name ?? "Unassigned"}
                  </TableCell>
                  <TableCell>
                    <StatusChip status={lead.status as LeadStatus} />
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {lead.archivedBy?.name ?? "—"}
                  </TableCell>
                  <TableCell className="text-right">
                    <Button
                      variant="teal-outline"
                      size="sm"
                      disabled={restoring}
                      onClick={() =>
                        setTarget({ mode: "single", id: lead.id, label: lead.business })
                      }
                    >
                      Restore
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {!isLoading && !isError && total > PER_PAGE ? (
        <Pagination page={page} totalPages={totalPages} total={total} onPageChange={changePage} />
      ) : null}

      <ConfirmDialog
        open={target !== null}
        onOpenChange={(open) => {
          if (!open) setTarget(null);
        }}
        title={confirmCopy.title}
        description={confirmCopy.description}
        confirmLabel="Restore"
        onConfirm={confirmRestore}
        loading={restoring}
      />
    </div>
  );
}
