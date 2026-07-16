/**
 * Shape returned by the Notification Center API (`GET /notifications`), which presents the existing
 * Laravel Database Notifications. `link` is the backend-derived deep-link to the related CRM record
 * (null when the payload has no addressable target).
 */
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
