import type { ActivityFeedEntry } from "@/modules/projects/types/activity-log";

/** A feed row, split so the actor and the work item can be emphasised. */
export interface ActivityFeedLine {
  who: string;
  /** The phrase between the actor and the work item. */
  verb: string;
  /** The task or sub-task the entry is about. */
  subject: string;
  /** Trailing context — the status a move landed in, when there is one. */
  detail: string | null;
}

/**
 * Every action {@link ActivityFeedService::FEED_ACTIONS} carries, mapped to the
 * phrase that precedes the work item's name. The verbs are deliberately plain:
 * a feed row is scanned, not read.
 */
const VERBS: Record<string, string> = {
  "task.created": "created",
  "task.status_changed": "moved",
  "task.approved": "approved",
  "task.rejected": "sent back",
  "task.resubmitted": "resubmitted",
  "subtask.created": "added the sub-task",
  "subtask.status_changed": "moved the sub-task",
  "subtask.approved": "approved the sub-task",
  "subtask.rejected": "sent back the sub-task",
  "subtask.resubmitted": "resubmitted the sub-task",
};

export function activityFeedLine(entry: ActivityFeedEntry): ActivityFeedLine {
  const isSubtask = entry.action.startsWith("subtask.");

  return {
    who: entry.user?.name ?? "Someone",
    // An action this build doesn't know (an older row, or a newer server) still
    // reads as a sentence rather than leaking "subtask.archived" at the user.
    verb: VERBS[entry.action] ?? (isSubtask ? "updated the sub-task" : "updated"),
    subject:
      (isSubtask ? entry.subtask?.name : entry.task?.title) ??
      entry.task?.title ??
      "a task",
    // Only a move has somewhere to land. `old_status` is deliberately unused:
    // where it went is the news, where it came from is noise.
    detail: entry.action.endsWith(".status_changed")
      ? `to ${entry.new_status ?? "a new status"}`
      : null,
  };
}
