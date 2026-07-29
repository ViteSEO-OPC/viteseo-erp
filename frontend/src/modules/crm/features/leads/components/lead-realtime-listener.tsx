"use client";

import { useAssignedLeadToasts } from "@/modules/crm/features/leads/hooks/use-lead-realtime";

/**
 * Renders nothing; exists so the "a lead was assigned to me" listener is mounted
 * for the whole CRM rather than only on the Leads screens — a BD should be told
 * wherever they happen to be.
 *
 * It is a component rather than a hook call in the CRM layout so that it sits
 * INSIDE <RealtimeProvider>. That guarantees the socket is already established
 * when the listener attaches, and that the listener never runs on the layout's
 * pre-auth / role-redirect renders, which mount no realtime layer at all.
 */
export function LeadRealtimeListener(): null {
  useAssignedLeadToasts();
  return null;
}
