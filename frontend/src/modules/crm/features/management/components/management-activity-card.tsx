"use client";

import Link from "next/link";
import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { ActivityItem } from "@/modules/crm/features/dashboard/components/activity-item";
import { useActivityFeed } from "@/modules/crm/features/dashboard/hooks/use-dashboard-data";

// Number of activities shown in the Admin/SA dashboard widget; "Full history →" opens the
// complete, paginated Activity Logs page.
const FEED_LIMIT = 6;

/**
 * Admin / Super Admin "Recent Activity" — a live, system-wide feed. Reuses the exact same
 * ownership-scoped `GET /activities` query (`useActivityFeed`) and row renderer (`ActivityItem`)
 * as the Activity Logs page and the BD Dashboard widget, so `Activity::scopeVisibleTo` remains
 * the single source of truth (Admin/SA see the whole CRM; a BD would see only their own).
 * No mock data, no separate filtering logic. Newest-first.
 */
export function ManagementActivityCard({ className }: { className?: string }) {
  const { data: activities, isLoading, isError } = useActivityFeed(FEED_LIMIT);

  return (
    <ChartCard
      title="Recent Activity"
      filter={
        <Link href="/zerem/activity-logs" className="text-xs font-semibold text-teal-700 hover:underline">
          Full history →
        </Link>
      }
      className={className}
      isLoading={isLoading}
      isError={isError}
      isEmpty={!!activities && activities.length === 0}
      emptyMessage="No recent activity"
    >
      {/* -mx-4 cancels the card's horizontal padding so ActivityItem's own px-4 rows and their
          full-width dividers align edge-to-edge, matching the Dashboard/Activity Logs look. */}
      <ul className="-mx-4">
        {activities?.map((item) => (
          <ActivityItem key={item.id} item={item} />
        ))}
      </ul>
    </ChartCard>
  );
}
