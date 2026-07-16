/**
 * Canonical, ORDERED lead-status taxonomy — the single source of truth for status order,
 * display label, and chart color. Any UI that enumerates statuses (currently the Management
 * Dashboard "Leads by Status" card via `useLeadStatusBreakdown`) reads from here, so if the
 * business expands the CRM status workflow later, only this file changes — not the hook or the
 * card. Keep the keys in sync with the backend `status` enum
 * (StoreLeadRequest / UpdateLeadRequest / IndexLeadRequest).
 *
 * Order is significant — bars/lists render top-to-bottom in this order.
 */
export type LeadStatusConfig = {
  value: string;
  label: string;
  color: string;
};

export const LEAD_STATUS_CONFIG = [
  { value: "new", label: "New", color: "#4FD8D7" },
  { value: "interested", label: "Interested", color: "#22C55E" },
  { value: "awaiting-reply", label: "Awaiting Reply", color: "#6B7280" },
  { value: "call-back-req", label: "Call-back Req.", color: "#F59E0B" },
  { value: "declined", label: "Declined", color: "#EF4444" },
] as const satisfies readonly LeadStatusConfig[];

/** Union of the status enum keys, derived from the config above (stays in sync automatically). */
export type LeadStatusKey = (typeof LEAD_STATUS_CONFIG)[number]["value"];
