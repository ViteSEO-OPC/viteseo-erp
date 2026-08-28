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

/**
 * The statuses a user may SET, in the same order as the taxonomy above — the full enum minus the
 * create-only "new", which a lead leaves on first contact and can never be returned to.
 *
 * Derived from LEAD_STATUS_CONFIG rather than retyped, so a change to the taxonomy cannot leave the
 * two disagreeing. This list moved here from quick-actions-card.tsx when the Leads bulk bar needed
 * the same options: a component is the wrong home for a vocabulary two surfaces share, and this
 * file already declares itself the single source of truth for status order and labels.
 */
export const EDITABLE_STATUS_OPTIONS = LEAD_STATUS_CONFIG.filter(
  (status) => status.value !== "new",
).map(({ value, label }) => ({ value, label }));
