/**
 * Shape returned by the Notification Center API (`GET /notifications`), which presents the existing
 * Laravel Database Notifications. `link` is the backend-derived deep-link to the related CRM record
 * (null when the payload has no addressable target).
 */
/**
 * The catalog types a reader can filter History by — the frontend half of the same
 * convention as `ICON_BY_TYPE` in notification-item.tsx: a new notification type is not
 * "done" until it appears in the backend NotificationType enum, has an icon, and is
 * listed here. Values must match the enum's string values exactly; the API validates
 * `type` against that enum, so a drifted value is a 422 rather than a silent empty feed.
 */
export const NOTIFICATION_TYPE_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: "inbound_reply", label: "Inbound replies" },
  { value: "planner_reminder", label: "Reminders" },
  { value: "lead_assigned", label: "Lead assignments" },
];

export type NotificationDto = {
  id: string;
  type: string;
  title: string;
  message: string | null;
  link: string | null;
  data: Record<string, unknown>;
  read: boolean;
  read_at: string | null;
  created_at: string | null;
};
