import type { User } from "@/core/types";

export interface ActivityLog {
  id: string;
  task_id: string;
  subtask_id: string | null;
  action: string;
  field_changed: string | null;
  old_value: string | null;
  new_value: string | null;
  user?: User | null;
  created_at: string;
}

/**
 * One row of the Recent Activity feed (cross-project, task + sub-task events).
 *
 * Distinct from {@link ActivityLog}, which powers a single task's History panel
 * where the task is already on screen: a feed row has to name the work it refers
 * to, and carries status *names* because the log itself only stores status ids.
 */
export interface ActivityFeedEntry {
  id: string;
  action: string;
  user: User | null;
  /** Always present — a feed row opens this task. */
  task: { id: string; title: string } | null;
  /** Set on sub-task events; the row still opens the parent task. */
  subtask: { id: string; name: string } | null;
  project: { id: string; name: string } | null;
  /** Resolved status names, on status changes only. */
  old_status: string | null;
  new_status: string | null;
  created_at: string;
}
