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
  data: { title?: string; snippet?: string; status?: string } | null;
  read: boolean;
  read_at: string | null;
  created_at: string;
  actor: User | null;
  project_id?: string;
  task_id?: string;
}

/** Compact relative timestamp ("2 min ago", "3 hours ago", "Yesterday", date). */
export function relativeTime(iso: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const diff = Date.now() - then;
  const min = Math.floor(diff / 60000);
  if (min < 1) return "Just now";
  if (min < 60) return `${min} min ago`;
  const hrs = Math.floor(min / 60);
  if (hrs < 24) return `${hrs} hour${hrs === 1 ? "" : "s"} ago`;
  const days = Math.floor(hrs / 24);
  if (days === 1) return "Yesterday";
  return new Date(iso).toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

/** Today / Yesterday / Earlier bucket for the notifications page. */
export function bucketOf(iso: string): AppNotification["group"] {
  const d = new Date(iso);
  const now = new Date();
  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const t = d.getTime();
  if (t >= startOfToday) return "Today";
  if (t >= startOfToday - 86_400_000) return "Yesterday";
  return "Earlier";
}

/** Human message for a notification, shown after the bolded actor name. */
function messageFor(dto: NotificationDto): string {
  const title = dto.data?.title ?? "a task";
  switch (dto.type) {
    case "task.assigned":
      return `assigned you the task “${title}”`;
    case "comment.created":
      return dto.data?.snippet
        ? `commented on “${title}”: ${dto.data.snippet}`
        : `commented on “${title}”`;
    case "task.status_changed":
      return dto.data?.status
        ? `moved “${title}” to ${dto.data.status}`
        : `updated the status of “${title}”`;
    default:
      return "sent you a notification";
  }
}

/** Map a backend NotificationResource into the UI notification shape. */
export function fromResource(dto: NotificationDto): AppNotification {
  return {
    id: dto.id,
    actor: dto.actor?.name ?? "System",
    message: messageFor(dto),
    time: relativeTime(dto.created_at),
    unread: !dto.read,
    group: bucketOf(dto.created_at),
    created_at: dto.created_at,
    taskId: dto.task_id,
  };
}

export const NOTIFICATION_GROUPS: AppNotification["group"][] = [
  "Today",
  "Yesterday",
  "Earlier",
];

/** Dev/empty-state fallback only — real notifications come from the API. */
export const SAMPLE_NOTIFICATIONS: AppNotification[] = [];
