"use client";

import { useState } from "react";
import { useQueryClient, type QueryClient } from "@tanstack/react-query";
import { useRealtimeEvent } from "@/core/realtime/use-realtime";
import { notify as toast } from "@/core/lib/notify";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { LeadApiDetail } from "@/modules/crm/features/leads/hooks/use-lead-detail";

/**
 * Realtime consumers for the backend's `LeadAssigned` broadcast (Slice 3).
 *
 * The server publishes one event on two private channels, because two audiences
 * care about it, and this module mirrors that split exactly:
 *
 *   user.{myId}        "a lead was assigned to me"  → useAssignedLeadToasts()
 *   crm.lead.{leadId}  "this lead changed owner"    → useLeadChannel(leadId)
 *
 * Both reuse the socket the CRM shell already opened (core/realtime/echo); neither
 * creates realtime infrastructure of its own.
 */

// Laravel's broadcastAs() returns a custom name, so Echo needs the leading dot —
// without it the client would listen for the fully-qualified class name instead.
const LEAD_ASSIGNED = ".LeadAssigned";

/**
 * Wire payload of `LeadAssigned::broadcastWith()`. Ids are strings to match
 * LeadResource, so they compare directly against cached REST data — and `name`
 * is non-nullable for the same reason: it must satisfy the same contract as the
 * cached `assignedTo` it replaces. The event can only fire for a lead that was
 * just assigned to a validated BD, so the owner relation always resolves.
 *
 * Deliberately narrow: the server withholds contact details, score and pipeline
 * value because a BD who has just lost the lead stays subscribed until their
 * client resubscribes. Do not widen this without revisiting that decision.
 */
export type LeadAssignedPayload = {
  leadId: string;
  business: string | null;
  assignedTo: { id: string; name: string } | null;
  assignedBy: { id: string; name: string } | null;
  at: string;
};

/**
 * Everything an ownership change invalidates — deliberately the SAME set
 * useAssignLead().onSuccess uses, so a lead reassigned in another tab leaves this
 * client in exactly the state it would have reached by doing the assignment here.
 */
function invalidateForAssignment(qc: QueryClient, leadId: string): void {
  qc.invalidateQueries({ queryKey: queryKeys.leads.lists() });
  qc.invalidateQueries({ queryKey: queryKeys.leads.detail(leadId) });
  // Ownership feeds the per-BD "assigned" counts and the ownership-scoped
  // performance aggregates, and the assignment writes a `lead.assigned` activity.
  qc.invalidateQueries({ queryKey: queryKeys.dashboard.all });
  qc.invalidateQueries({ queryKey: queryKeys.performance.all });
  qc.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
}

/**
 * Toast the BD a lead has just been handed to, anywhere in the CRM.
 *
 * The server only publishes to `user.{newOwnerId}`, so an event arriving on our own
 * channel always means "assigned to me" — no filtering needed. The assigning admin
 * never sees it: the broadcast is made with ->toOthers() and, because /assign is
 * Admin-only while the new owner must be a BD, the actor and the recipient can
 * never be the same account.
 *
 * CHANNEL OWNERSHIP. `user.{id}` is shared — CRM notifications and Projects
 * notifications ride the same channel. This hook registers a callback and nothing
 * else: joining and leaving the channel belong to the realtime manager, so there is
 * no way for this hook's unmount to remove anyone else's handlers.
 */
export function useAssignedLeadToasts(): void {
  const { user } = useAuth();
  const qc = useQueryClient();
  const userId = user?.id;

  useRealtimeEvent<LeadAssignedPayload>(
    userId == null ? null : `user.${userId}`,
    LEAD_ASSIGNED,
    (payload) => {
      toast.success(
        "New lead assigned to you",
        `"${payload.business ?? "Lead"}" — assigned by ${payload.assignedBy?.name ?? "an admin"}`,
      );
      invalidateForAssignment(qc, payload.leadId);
    },
  );
}

/**
 * Keep an open Lead Details view live while someone else reassigns the lead.
 *
 * Subscribes the lead's own channel, patches the cached detail in place so the
 * owner updates with no refetch, then invalidates the wider set to reconcile.
 *
 * LOSING ACCESS. Channel authorization is subscribe-time only: a BD who is
 * already subscribed keeps receiving until their client resubscribes. When the
 * lead moves to someone else and the viewer is a BD, they have just lost access,
 * so we unsubscribe immediately rather than hold a channel we can no longer read.
 * Admin/Super Admin see every lead, so they stay subscribed.
 *
 * Returns `lostAccess` for exactly that case. The invalidation also refetches the
 * detail and the API correctly answers 403, but a raw failed request tells the
 * user only that something broke; this flag lets the page say what actually
 * happened instead. Reported from here because this is the only place that knows
 * the difference between "reassigned away from you" and a genuine load failure.
 */
export function useLeadChannel(leadId: string | undefined): { lostAccess: boolean } {
  const qc = useQueryClient();
  const { user } = useAuth();
  const { hasMinRole } = usePermissions();

  const viewerId = user?.id;
  const seesEveryLead = hasMinRole("crm_admin");

  const [lostAccess, setLostAccess] = useState(false);
  // Clear the flag when the view moves to a different lead — "adjust state during
  // render", the same pattern useLeads() uses to reset its page on filter change,
  // and the reason this is not a setState inside an effect.
  const [trackedLeadId, setTrackedLeadId] = useState(leadId);
  if (trackedLeadId !== leadId) {
    setTrackedLeadId(leadId);
    setLostAccess(false);
  }

  // Passing null unsubscribes. That is the whole mechanism for "we just lost access":
  // the hook stops wanting the channel, and the manager leaves it once no listener
  // remains — which here is immediately, since this hook is its only subscriber. The
  // previous version called echo.leave() from inside the handler and carried a flag
  // so cleanup would not repeat it; expressing it as intent removes both.
  //
  // Unlike `user.{id}` this channel usually has one subscriber, but "usually" is not
  // a guarantee worth relying on — two Lead Details for the same lead would share it,
  // and refcounting means the first to unmount no longer cuts off the second.
  useRealtimeEvent<LeadAssignedPayload>(
    leadId && !lostAccess ? `crm.lead.${leadId}` : null,
    LEAD_ASSIGNED,
    (payload) => {
      if (!leadId) return;
      const newOwnerId = payload.assignedTo?.id ?? null;

      // Patch the open detail first: the header shows the new owner immediately,
      // with no request in between. Only the ownership fields are touched — the
      // payload carries nothing else, so nothing else may be claimed as fresh.
      qc.setQueryData<{ data: LeadApiDetail }>(
        queryKeys.leads.detail(leadId),
        (prev) =>
          prev
            ? {
                ...prev,
                data: { ...prev.data, assignedToId: newOwnerId, assignedTo: payload.assignedTo },
              }
            : prev,
      );

      invalidateForAssignment(qc, leadId);

      if (!seesEveryLead && viewerId != null && newOwnerId !== String(viewerId)) {
        setLostAccess(true);
      }
    },
  );

  return { lostAccess };
}
