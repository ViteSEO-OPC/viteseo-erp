"use client";

import { Card, CardContent } from "@/modules/crm/components/ui/card";
import { ActivityItem } from "@/modules/crm/features/dashboard/components/activity-item";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { useActivityFeed } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";
import {
  scopeCopy,
  usePerformanceScope,
} from "@/modules/crm/features/performance/lib/performance-scope";

/**
 * Recent Activity — LIVE. Reuses the ownership-scoped `GET /activities` feed (useActivityFeed, the same
 * query + `Activity::scopeVisibleTo` the Dashboard and Activity Logs use), so there is no new endpoint
 * or client-side filtering. Under a scope provider (Reports & Performance) it scopes to the selected BD;
 * with no provider (My Performance) it is the acting BD's own feed. Copy adapts to the scope.
 */
export function MyRecentActivityCard() {
  const scope = usePerformanceScope();
  const copy = scopeCopy(scope);
  // Fetch the full first page so there is something to scroll — the list caps at ~5 visible rows and
  // scrolls for the rest, keeping the card height stable regardless of how much activity there is.
  const { data, isLoading, isError } = useActivityFeed(
    15,
    scope.mode === "bd" ? scope.userId : undefined,
  );

  return (
    <Card className="border-border shadow-card">
      <CardContent className="flex flex-col gap-3 pt-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-section font-bold text-card-foreground">{copy.recentTitle}</h2>
          <span className="text-xs text-muted-foreground">{copy.recentSubtitle}</span>
        </div>

        {isLoading && <ActivitySkeleton />}
        {isError && <ErrorState message="Failed to load activity" />}
        {!isLoading && !isError && data && data.length > 0 && (
          // Cap at ~5 visible rows; the list scrolls beyond that while the card header stays fixed.
          <ul className="max-h-80 divide-y divide-border-subtle overflow-y-auto overscroll-contain">
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
