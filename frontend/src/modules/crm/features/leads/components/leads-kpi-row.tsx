import { PriorityCard } from "@/modules/crm/features/dashboard/components/priority-card";
import { PrioritySkeleton } from "@/modules/crm/features/dashboard/components/priority-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { usePrioritySummary } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

/**
 * Lead Priority Overview — the SAME component and the SAME data source the Dashboard renders
 * (GET /leads/priority-summary). The Dashboard is the entry point into this workspace, so a user
 * clicking a card there must find the identical segmentation here, not a different report.
 *
 * `filters` carries the current table filters so the cards stay in step with the list. The endpoint
 * deliberately ignores `priority` itself, so drilling into one bucket never zeroes the other two.
 */
type Props = {
  filters: Record<string, string>;
  /**
   * The list's current `priority` filter, or "" when none is applied — the bucket whose card
   * renders as selected. It has to arrive separately because `filters` above is deliberately the
   * summary filter set, which strips `priority` (see the note on that prop); reading the selection
   * from it would always come back empty.
   */
  activePriority?: string;
};

export function LeadsKpiRow({ filters, activePriority = "" }: Props) {
  const { data: buckets, isLoading, isError } = usePrioritySummary(filters);

  if (isLoading) return <PrioritySkeleton />;
  if (isError) return <ErrorState message="Failed to load priority summary" />;
  if (!buckets) return null;

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      {buckets.map((bucket) => (
        // "Click to filter": on this page the card narrows the list instead of navigating away.
        <PriorityCard
          key={bucket.priority}
          bucket={bucket}
          action="filter"
          isActive={bucket.priority === activePriority}
        />
      ))}
    </div>
  );
}
