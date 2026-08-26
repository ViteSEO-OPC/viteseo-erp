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
import { useTableDensity } from "@/modules/crm/components/ui/table-density";
import type { BdPerformanceRow } from "@/modules/crm/features/management/types/management-types";

// Display-only placeholder for Status — the one column with no approved definition. It is not
// blocked by missing data: BD Ranking exists, but it yields an interim peer-relative INDEX, and
// banding that into labels would invent the proxy business rule this card refuses to create. Which
// value Status carries is a Product Owner decision, so it stays undrawn until one is made.
function Placeholder() {
  return (
    <span className="text-muted-foreground/60" title="Pending a Product Owner definition">
      —
    </span>
  );
}

// An undefined conversion rate — the BD has engaged nobody, so there is no ratio to report. This is
// deliberately NOT 0%, which would claim they worked leads and converted none (approved edge rule).
function NoRate() {
  return (
    <span className="text-muted-foreground/60" title="No engaged leads yet">
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
  const density = useTableDensity();
  // A condensed HEADER over standard ROWS — this card's own pairing, and pre-existing. Preserved
  // rather than normalised, so this refactor changes no pixels.
  const th = cn(density.head.condensed, "px-3 text-[11px] font-semibold uppercase tracking-[0.06em] text-muted-foreground");
  const td = cn(density.row.standard, "px-3 text-sm text-secondary-foreground");
  const tdTight = cn(density.row.condensed, "px-3");

  return (
    <ChartCard
      title="BD Performance Overview"
      subtitle="Live: Assigned, Follow-ups, Converted & Rate. Status awaits a Product Owner definition."
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
              <TableHead className={th}>Business Development Manager</TableHead>
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
                <TableCell className={tdTight}>
                  <div className="flex items-center gap-3">
                    <AvatarInitials src={r.avatarUrl} initials={r.initials} size="sm" />
                    <div>
                      <p className="text-sm font-semibold text-foreground">{r.name}</p>
                      <p className="text-xs text-muted-foreground">Business Development Manager</p>
                    </div>
                  </div>
                </TableCell>
                <TableCell className={td}>{r.assigned}</TableCell>
                {/* Completed this month. 0 is a real count, never the em dash `rate` uses. */}
                <TableCell className={cn(td, "tabular-nums")}>{r.followUps}</TableCell>
                <TableCell className={cn(td, "tabular-nums")}>{r.converted}</TableCell>
                <TableCell className={cn(td, "tabular-nums")}>
                  {r.rate === null ? <NoRate /> : `${r.rate.toFixed(1)}%`}
                </TableCell>
                <TableCell className={cn(density.row.standard, "px-3")}><Placeholder /></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </ChartCard>
  );
}
