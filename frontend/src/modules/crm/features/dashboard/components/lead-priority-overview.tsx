import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { PriorityCard } from "@/modules/crm/features/dashboard/components/priority-card";
import { PrioritySkeleton } from "@/modules/crm/features/dashboard/components/priority-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { usePriorityBuckets } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

export function LeadPriorityOverview() {
  const { data: buckets, isLoading, isError } = usePriorityBuckets();

  return (
    <section>
      <SectionHeader title="Lead Priority Overview" />
      {isLoading && <PrioritySkeleton />}
      {isError && <ErrorState message="Failed to load priority data" />}
      {!isLoading && !isError && buckets && buckets.length > 0 && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {buckets.map((bucket) => (
            <PriorityCard key={bucket.websiteStatus} bucket={bucket} />
          ))}
        </div>
      )}
      {!isLoading && !isError && (!buckets || buckets.length === 0) && null}
    </section>
  );
}
