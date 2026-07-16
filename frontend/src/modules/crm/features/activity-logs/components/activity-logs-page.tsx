"use client";

import { ActivityItem } from "@/modules/crm/features/dashboard/components/activity-item";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { Button } from "@/modules/crm/components/ui/button";
import { useActivityLog } from "@/modules/crm/features/activity-logs/hooks/use-activity-log";

/**
 * Activity Logs — the full activity history. Reuses the Dashboard's activity source
 * (GET /activities via useActivityLog), mapping, icons, and row component; only the layout is
 * full-page (timestamp right-aligned per the reference). The page title comes from the topbar
 * (PAGE_TITLES), so no in-page heading is rendered.
 */
export function ActivityLogsPage() {
  const {
    activities,
    isLoading,
    isError,
    hasNextPage,
    isFetchingNextPage,
    fetchNextPage,
    retry,
  } = useActivityLog();

  return (
    <div className="flex flex-col gap-4">
      {isLoading && <ActivitySkeleton />}

      {isError && <ErrorState message="Failed to load activity" onRetry={retry} />}

      {!isLoading && !isError && activities.length > 0 && (
        <>
          <div className="overflow-hidden rounded-lg border border-border bg-card shadow-card">
            <ul className="divide-y divide-border-subtle">
              {activities.map((item) => (
                <ActivityItem key={item.id} item={item} variant="page" />
              ))}
            </ul>
          </div>

          {hasNextPage && (
            <div className="flex justify-center">
              <Button
                variant="outline"
                onClick={() => fetchNextPage()}
                disabled={isFetchingNextPage}
              >
                {isFetchingNextPage ? "Loading…" : "Load more"}
              </Button>
            </div>
          )}
        </>
      )}

      {!isLoading && !isError && activities.length === 0 && (
        <EmptyState message="No activity yet" />
      )}
    </div>
  );
}
