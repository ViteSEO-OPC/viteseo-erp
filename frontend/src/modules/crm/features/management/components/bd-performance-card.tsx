import Link from "next/link";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { cn } from "@/modules/crm/lib/utils";
import type { BdPerformanceRow } from "@/modules/crm/features/management/types/management-types";

const th = "h-9 px-3 text-[11px] font-semibold uppercase tracking-[0.06em] text-muted-foreground";
const td = "px-3 py-3.5 text-sm text-secondary-foreground";

// Display-only placeholder for columns with no legitimate backend source yet. There is no
// follow-ups backend (an outbound email is not a follow-up), and the lead-status enum has no
// converted/won state, so Follow-ups, Converted, Rate, and Status are intentionally NOT derived
// (no proxy business rule) — they land with the future KPI Engine milestone.
function Placeholder() {
  return (
    <span className="text-muted-foreground/60" title="Available with the KPI Engine">
      —
    </span>
  );
}

type BdPerformanceCardProps = {
  rows?: BdPerformanceRow[];
  isLoading?: boolean;
  isError?: boolean;
  className?: string;
};

export function BdPerformanceCard({ rows, isLoading, isError, className }: BdPerformanceCardProps) {
  return (
    <ChartCard
      title="BD Performance Overview"
      subtitle="Live: Assigned. Follow-ups, Converted, Rate & Status arrive with the KPI Engine."
      filter={
        <Link href="/zerem/reports" className="text-xs font-semibold text-teal-700 hover:underline">
          Open Reports &amp; Performance →
        </Link>
      }
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!!rows && rows.length === 0}
      emptyMessage="No active business developers"
    >
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow className="border-b border-border-subtle">
              <TableHead className={th}>Business Developer</TableHead>
              <TableHead className={th}>Assigned</TableHead>
              <TableHead className={th}>Follow-ups</TableHead>
              <TableHead className={th}>Converted</TableHead>
              <TableHead className={th}>Rate</TableHead>
              <TableHead className={th}>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows?.map((r) => (
              <TableRow key={r.id} className="border-b border-border-subtle">
                <TableCell className="px-3 py-3">
                  <div className="flex items-center gap-3">
                    <AvatarInitials initials={r.initials} size="sm" />
                    <div>
                      <p className="text-sm font-semibold text-foreground">{r.name}</p>
                      <p className="text-xs text-muted-foreground">Business Developer</p>
                    </div>
                  </div>
                </TableCell>
                <TableCell className={td}>{r.assigned}</TableCell>
                <TableCell className={cn(td, "tabular-nums")}><Placeholder /></TableCell>
                <TableCell className={cn(td, "tabular-nums")}><Placeholder /></TableCell>
                <TableCell className={cn(td, "tabular-nums")}><Placeholder /></TableCell>
                <TableCell className="px-3 py-3.5"><Placeholder /></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </ChartCard>
  );
}
