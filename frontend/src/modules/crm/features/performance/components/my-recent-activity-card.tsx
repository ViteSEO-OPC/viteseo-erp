"use client";

import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { ActivityItem } from "@/modules/crm/features/dashboard/components/activity-item";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { useActivityFeed } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

/**
 * My Recent Activity — LIVE. Reuses the ownership-scoped `GET /activities` feed (useActivityFeed,
 * the same query + `Activity::scopeVisibleTo` the Dashboard and Activity Logs use), so a BD sees
 * only their own actions and there is no new endpoint or client-side filtering. Rows render via
 * the shared ActivityItem for one consistent activity presentation across the app.
 */
export function MyRecentActivityCard() {
  const { data, isLoading, isError } = useActivityFeed(6);

  return (
    <Card className="border-border shadow-card">
      <CardContent className="flex flex-col gap-3 pt-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-section font-bold text-card-foreground">My Recent Activity</h2>
          <span className="text-xs text-muted-foreground">Your latest actions</span>
        </div>

        {isLoading && <ActivitySkeleton />}
        {isError && <ErrorState message="Failed to load activity" />}
        {!isLoading && !isError && data && data.length > 0 && (
          <ul className="divide-y divide-border-subtle">
            {data.map((item) => (
              <ActivityItem key={item.id} item={item} />
            ))}
          </ul>
        )}
        {!isLoading && !isError && (!data || data.length === 0) && (
          <EmptyState message="No recent activity" />
        )}
      </CardContent>
    </Card>
  );
}
