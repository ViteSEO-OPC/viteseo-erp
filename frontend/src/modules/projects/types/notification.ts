import type { User } from "@/core/types";

/**
 * A notification as rendered in the UI (bell dropdown + notifications page).
 * Real data comes from the API (see `useNotifications`) and is mapped from the
 * backend `NotificationResource` via `fromResource`. `SAMPLE_NOTIFICATIONS` is
 * only a dev/empty-state fallback now.
 */
export interface AppNotification {
  id: string;
  actor: string;
  /** The action text shown after the bolded actor name. */
  message: string;
  time: string;
  unread: boolean;
  /** Grouping bucket for the full notifications page. */
  group: "Today" | "Yesterday" | "Earlier";
  /** ISO timestamp used to derive `time` + `group` (and to sort). */
  created_at: string;
  /** Parent task uuid — lets a notification open the task modal on click. */
  taskId?: string;
}

/** The backend NotificationResource shape. */
export interface NotificationDto {
  id: string;
  type: string;
  data: {
    title?: string;
    snippet?: string;
    status?: string;
    /** Parent task's title on a sub-task review request. */
    task_title?: string;
    /** Reviewer's rejection reason on a task/sub-task rejection. */
    reason?: string;
  } | null;
  read: boolean;
  read_at: string | null;
  created_at: string;
  actor: User | null;
  project_id?: string;
  task_id?: string;
}
