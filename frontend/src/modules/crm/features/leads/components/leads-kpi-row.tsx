import { PriorityCard } from "@/modules/crm/features/dashboard/components/priority-card";
import { PrioritySkeleton } from "@/modules/crm/features/dashboard/components/priority-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
// Reuse the Dashboard's live priority-buckets hook (GET /dashboard/priority-buckets):
// real count / avgScore / pipelinePct merged with the shared static title+description.
// No duplicate endpoint or aggregation — same query the Dashboard already uses.
import { usePriorityBuckets } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

export function LeadsKpiRow() {
  const { data: buckets, isLoading, isError } = usePriorityBuckets();

  if (isLoading) return <PrioritySkeleton />;
  if (isError) return <ErrorState message="Failed to load priority summary" />;
  if (!buckets) return null;

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      {buckets.map((bucket) => (
        <PriorityCard key={bucket.websiteStatus} bucket={bucket} />
      ))}
    </div>
  );
}
