import { cn } from "@/modules/crm/lib/utils";
import { formatActivityTimestamp } from "@/modules/crm/lib/formatters";
import { ActivityIconTile } from "@/modules/crm/features/dashboard/components/activity-icon-tile";
import { ActivitySkeleton } from "@/modules/crm/features/dashboard/components/activity-skeleton";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import {
  formatCallDuration,
  OUTCOME_META,
  OUTCOME_TONE_CLASS,
  type Call,
} from "@/modules/crm/features/leads/types/call-types";

const BADGE = "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold";

function OutcomeBadge({ call }: { call: Call }) {
  // An in-progress attempt (the live session shown in the Active Call panel) may also appear here — it
  // has no outcome yet, so it reads as "In progress" rather than a disposition.
  if (call.status === "in_progress" || !call.outcome) {
    return <span className={cn(BADGE, "bg-[var(--chip-teal-bg)] text-teal-800")}>In progress</span>;
  }
  const meta = OUTCOME_META[call.outcome];
  return <span className={cn(BADGE, OUTCOME_TONE_CLASS[meta.tone])}>{meta.label}</span>;
}

type Props = {
  calls?: Call[];
  isLoading?: boolean;
  isError?: boolean;
};

/**
 * Call History body — every attempt for a lead, newest-first, rendered as disposition + duration +
 * agent + timestamp (+ notes when present). Presentation only: it receives the fetched calls and the
 * three states; all data-loading lives in the hook. Mirrors ActivityTimelineList's state handling.
 */
export function CallHistoryList({ calls, isLoading, isError }: Props) {
  if (isLoading) return <ActivitySkeleton />;
  if (isError) return <ErrorState message="Failed to load calls" />;
  if (!calls || calls.length === 0) return <EmptyState message="No calls logged yet" />;

  return (
    <div className="max-h-[300px] overflow-y-auto pr-1 [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-teal-400 [&::-webkit-scrollbar-track]:bg-transparent">
      <ul className="flex flex-col divide-y divide-border-subtle">
        {calls.map((call) => (
          <li key={call.id} className="flex gap-3 py-3 first:pt-0 last:pb-0">
            <ActivityIconTile type="call" />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <OutcomeBadge call={call} />
                {call.durationSeconds ? (
                  <span className="text-xs font-medium text-muted-foreground">
                    {formatCallDuration(call.durationSeconds)}
                  </span>
                ) : null}
              </div>
              {call.notes ? (
                <p className="mt-1 line-clamp-3 text-sm text-card-foreground">{call.notes}</p>
              ) : null}
              <p className="mt-0.5 text-xs text-muted-foreground">
                {call.agent?.name ? `${call.agent.name} · ` : ""}
                {formatActivityTimestamp(call.startedAt ?? call.createdAt)}
              </p>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
