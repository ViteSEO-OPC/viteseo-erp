export interface NotificationItem {
  id: string;
  title: string;
  subtitle: string;
  href: string;
  at: string;
}

export interface NotificationsPayload {
  total: number;
  items: NotificationItem[];
  /** Sidebar href → badge count (only where relevant). */
  nav: Record<string, number>;
  /** Whether more items exist beyond this page. */
  has_more: boolean;
}
