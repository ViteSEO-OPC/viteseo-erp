export const SIDEBAR_WIDTH = 217;

export const NAV_ITEMS = [
  { label: "Dashboard", icon: "layout-dashboard", href: "/zerem/dashboard" },
  { label: "Leads", icon: "users", href: "/zerem/leads", badgeCount: 248 },
  { label: "Follow-ups", icon: "clock", href: "/zerem/follow-ups", badgeCount: 12 },
  // { label: "Email Drafts", icon: "mail", href: "/zerem/email-drafts" },
  { label: "Planner", icon: "calendar-check", href: "/zerem/planner" },
  { label: "Reports", icon: "bar-chart-3", href: "/zerem/reports" },
  { label: "Activity Logs", icon: "history", href: "/zerem/activity-logs" },
  { label: "Settings", icon: "settings", href: "/zerem/settings" },
] as const;

export const PRIORITY_ORDER = ["high", "medium", "low"] as const;

export const STATUS_VARIANTS = {
  interested: { dot: "bg-teal-500", text: "text-teal-900" },
  "awaiting-reply": { dot: "bg-gray-500", text: "text-gray-600" },
  declined: { dot: "bg-red-500", text: "text-red-800" },
  "call-back-req": { dot: "bg-teal-500", text: "text-teal-900" },
} as const;
