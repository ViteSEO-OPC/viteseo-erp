import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { ActivityItem } from "@/modules/crm/features/dashboard/components/activity-item";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { useRecentActivity } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

export function RecentActivityPanel() {
  const { data: activities, isLoading, isError } = useRecentActivity();

  return (
    <div>
      <SectionHeader
        title="Recent Activity"
        linkText="Full history"
        linkHref="/zerem/activity-logs"
      />
      {isLoading && <ActivitySkeleton />}
      {isError && <ErrorState message="Failed to load activity" />}
      {!isLoading && !isError && activities && activities.length > 0 && (
        <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
          <ul className="divide-y divide-border-subtle">
            {activities.map((item) => (
              <ActivityItem key={item.id} item={item} />
            ))}
          </ul>
        </div>
      )}
      {!isLoading && !isError && (!activities || activities.length === 0) && (
        <EmptyState message="No recent activity" />
      )}
    </div>
  );
}
