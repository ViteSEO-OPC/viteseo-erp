"use client";

import { Avatar } from "@/core/ui";
import { useActivityFeed } from "@/modules/projects/hooks/use-activity-logs";
import { useOpenTask } from "@/modules/projects/hooks/use-task-modal";
import { activityFeedLine } from "@/modules/projects/lib/activity-feed";
import { relativeTime } from "@/modules/projects/lib/notifications";
import {
  SkeletonList,
} from "@/modules/projects/components/shared/skeletons";

export interface ActivityFeedProps {
  /** Scope the feed to one workspace. Omit both ids for the whole platform. */
  workspaceId?: string;
  projectId?: string;
  /** How many rows to show. */
  limit?: number;
  /** Show each row's project name — useful when the feed spans projects. */
  showProject?: boolean;
  className?: string;
}

/**
 * Recent Activity: task and sub-task events, newest first.
 *
 * Every row opens the task it refers to (a sub-task event opens its parent —
 * that is where the sub-task lives), so the feed is a way back into the work and
 * not just a notice board. Scoping and review-state visibility are the server's
 * call; this only renders what came back.
 */
export function ActivityFeed({
  workspaceId,
  projectId,
  limit = 8,
  showProject = false,
  className = "",
}: ActivityFeedProps): React.ReactElement {
  const { data, isLoading, isError } = useActivityFeed({
    workspaceId,
    projectId,
    limit,
  });
  const openTask = useOpenTask();

  const entries = data?.data ?? [];

  if (isLoading) {
    return <SkeletonList rows={4} className={className} />;
  }

  if (isError) {
    return (
      <p className={`text-sm text-ink-soft ${className}`}>
        Couldn&apos;t load recent activity.
      </p>
    );
  }

  if (entries.length === 0) {
    return <p className={`text-sm text-ink-soft ${className}`}>No recent activity.</p>;
  }

  return (
    <div className={`divide-y divide-line ${className}`}>
      {entries.map((entry) => {
        const line = activityFeedLine(entry);
        const taskId = entry.task?.id;

        return (
          <button
            key={entry.id}
            type="button"
            disabled={!taskId}
            onClick={() => taskId && openTask(taskId)}
            className="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors enabled:hover:bg-black/[0.03] disabled:cursor-default"
          >
            <Avatar
              name={line.who}
              src={entry.user?.avatar}
              size={32}
              className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
              <p className="text-sm text-ink">
                <span className="font-bold">{line.who}</span>{" "}
                <span className="text-ink-soft">{line.verb}</span>{" "}
                <span className="font-semibold">{line.subject}</span>
                {line.detail && (
                  <span className="text-ink-soft"> {line.detail}</span>
                )}
              </p>
              <p className="mt-0.5 text-xs text-ink-faint">
                {relativeTime(entry.created_at)}
                {showProject && entry.project && ` · ${entry.project.name}`}
              </p>
            </div>
          </button>
        );
      })}
    </div>
  );
}
