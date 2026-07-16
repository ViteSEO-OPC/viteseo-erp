"use client";

import { useRouter } from "next/navigation";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { PriorityChip } from "@/modules/crm/components/shared/status-badge/priority-chip";
import { StatusChip } from "@/modules/crm/components/shared/status-badge/status-chip";
import { TableSkeleton } from "@/modules/crm/features/dashboard/components/table-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { useRecentLeads } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

export function RecentLeadsTable() {
  const router = useRouter();
  const { data: leads, total, isLoading, isError } = useRecentLeads();

  return (
    <div>
      <SectionHeader
        title="Recent Leads"
        linkText={total != null ? `View all ${total}` : "View all"}
        linkHref="/leads"
      />
      {isLoading && <TableSkeleton />}
      {isError && <ErrorState message="Failed to load leads" />}
      {!isLoading && !isError && leads && leads.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-border bg-card shadow-card">
          <Table>
            <TableHeader>
              <TableRow className="border-b border-border-subtle bg-surface-table-header">
                <TableHead className="h-10 px-4 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                  Business
                </TableHead>
                <TableHead className="h-10 px-4 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                  Industry
                </TableHead>
                <TableHead className="h-10 px-4 text-center text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                  Score
                </TableHead>
                <TableHead className="h-10 px-4 text-center text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                  Priority
                </TableHead>
                <TableHead className="h-10 px-4 text-center text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                  Status
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {leads.map((lead) => (
                <TableRow
                  key={lead.id}
                  onClick={() => router.push(`/zerem/leads/${lead.id}`)}
                  className="cursor-pointer border-b border-border-subtle transition-colors hover:bg-muted"
                >
                  <TableCell className="px-4 py-3 text-sm font-semibold text-foreground">
                    {lead.business}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-sm text-secondary-foreground">
                    {lead.industry}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-center text-sm font-bold text-foreground">
                    {lead.score}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-center">
                    <PriorityChip priority={lead.priority} />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-center">
                    <StatusChip status={lead.status} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {!isLoading && !isError && (!leads || leads.length === 0) && (
        <EmptyState message="No leads yet" />
      )}
    </div>
  );
}
