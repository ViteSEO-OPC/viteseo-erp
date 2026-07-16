import { cn } from "@/modules/crm/lib/utils";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { ActivityIconTile } from "@/modules/crm/features/dashboard/components/activity-icon-tile";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import type { TimelineEvent } from "@/modules/crm/features/leads/types/lead-detail-types";

type TimelineItemProps = {
  event: TimelineEvent;
  isLast: boolean;
};

function TimelineItem({ event, isLast }: TimelineItemProps) {
  return (
    <li className="flex gap-3">
      <div className="flex flex-col items-center">
        {/* Reuses the shared activity icon/color mapping (event → glyph + tint). */}
        <ActivityIconTile type={event.type} />
        {/* Rail connecting this node to the next event. */}
        {!isLast ? (
          <span className="mt-1 w-px flex-1 bg-border-subtle" aria-hidden />
        ) : null}
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

export function ActivityTimeline({ events, isLoading, isError }: Props) {
  return (
    <div className="rounded-xl border border-border bg-card p-5 shadow-card">
      <h3 className="mb-5 text-lg font-bold text-card-foreground">
        Activity Timeline
      </h3>
      {isLoading ? (
        <ActivitySkeleton />
      ) : isError ? (
        <ErrorState message="Failed to load activity" />
      ) : !events || events.length === 0 ? (
        <EmptyState message="No activity yet" />
      ) : (
        // Scrolls internally past ~10 rows (~62px each) so the card — and the page — stops
        // growing with an ever-lengthening feed. `max-h` (not a fixed height) lets short feeds
        // shrink to fit. Only this list scrolls; the header above stays put. Subtle scrollbar
        // reuses the project's existing style. `pr-1` keeps rows clear of the scrollbar track.
        <div className="max-h-[600px] overflow-y-auto pr-1 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent">
          <ol className="flex flex-col">
            {events.map((event, index) => (
              <TimelineItem
                key={event.id}
                event={event}
                isLast={index === events.length - 1}
              />
            ))}
          </ol>
        </div>
      )}
    </div>
  );
}
