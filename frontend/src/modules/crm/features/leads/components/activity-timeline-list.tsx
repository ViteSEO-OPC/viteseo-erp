import { cn } from "@/modules/crm/lib/utils";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { ActivityIconTile } from "@/modules/crm/features/dashboard/components/activity-icon-tile";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import type { TimelineEvent } from "@/modules/crm/features/leads/types/lead-detail-types";

function TimelineItem({ event, isLast }: { event: TimelineEvent; isLast: boolean }) {
  return (
    <li className="flex gap-3">
      <div className="flex flex-col items-center">
        {/* Reuses the shared activity icon/color mapping (event → glyph + tint). */}
        <ActivityIconTile type={event.type} />
        {/* Rail connecting this node to the next event. */}
        {!isLast ? <span className="mt-1 w-px flex-1 bg-border-subtle" aria-hidden /> : null}
      </div>
      <div className={cn("pt-1", !isLast && "pb-5")}>
        <p className="text-sm font-medium text-card-foreground">{event.description}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {formatActivityTimestamp(event.timestamp)}
        </p>
      </div>
    </li>
  );
}

type Props = {
  events?: TimelineEvent[];
  isLoading?: boolean;
  isError?: boolean;
};

/**
 * Headerless Activity Timeline body — the loading / error / empty states and the internally-scrolling
 * event rail, WITHOUT the card chrome. Shared by the standalone {@link ActivityTimeline} card and the
 * tabbed LeadTimelinePanel so the feed renders identically in both, with no duplicated card.
 */
export function ActivityTimelineList({ events, isLoading, isError }: Props) {
  if (isLoading) return <ActivitySkeleton />;
  if (isError) return <ErrorState message="Failed to load activity" />;
  if (!events || events.length === 0) return <EmptyState message="No activity yet" />;

  return (
    // Scrolls internally past ~4 rows so the card stays compact instead of the scrollbar running nearly
    // the full card height. `max-h` (not a fixed height) lets short feeds shrink to fit. Subtle
    // scrollbar reuses the project's existing style; `pr-1` keeps rows clear of the scrollbar track.
    <div className="max-h-[300px] overflow-y-auto pr-1 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent">
      <ol className="flex flex-col">
        {events.map((event, index) => (
          <TimelineItem key={event.id} event={event} isLast={index === events.length - 1} />
        ))}
      </ol>
    </div>
  );
}
