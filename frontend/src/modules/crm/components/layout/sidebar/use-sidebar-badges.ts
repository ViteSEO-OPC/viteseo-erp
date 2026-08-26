"use client";

import { useMemo } from "react";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import { isActivePlannerEvent } from "@/modules/crm/features/calendar/lib/planner-active";
import { isPendingOutreachEvent } from "@/modules/crm/features/follow-ups/lib/outreach-pending";

type SidebarBadges = {
  outreach: number | undefined;
  planner: number | undefined;
};

/**
 * Sidebar Badge Standard (FROZEN) — badges must be real, actionable backend data from each module's
 * canonical definition; never hardcoded / placeholder / estimated / duplicated logic; hidden at zero.
 * New modules reuse their existing business logic here. Full standard: CRM-Enterprise docs →
 * Engineering-Note-Sidebar-Badges (+ Frontend-Architecture-Guide §4 "Sidebar Badges").
 *
 * Real, backend-derived sidebar badge counts. Both read the SINGLE shared Planner query
 * (`usePlannerEvents`) — server-scoped to the current user's own events (BD sees their own; Admin/SA
 * see all), and the same cached query the Outreach / Planner / Calendar pages already use. This adds
 * no new endpoint and no new business logic; it only counts existing records.
 *
 * - `outreach`: pending communication tasks the Outreach inbox surfaces — the exact
 *   `isPendingOutreachEvent` definition the Follow-ups Pending list uses (follow-up + email events
 *   that are not yet completed).
 * - `planner`: actionable Planner items — the exact {@link isActivePlannerEvent} definition, which
 *   now lives with the Planner feature instead of inline here. Undated Board-only backlog items are
 *   neither overdue nor upcoming, so they are excluded.
 *
 * Each value is `undefined` while loading OR when the count is zero, so the badge is simply not
 * rendered unless there is a real, non-zero count — never a placeholder zero.
 */
export function useSidebarBadges(): SidebarBadges {
  const { data: events = [], isLoading } = usePlannerEvents();

  return useMemo(() => {
    if (isLoading) {
      return { outreach: undefined, planner: undefined };
    }

    const outreach = events.filter(isPendingOutreachEvent).length;
    const planner = events.filter(isActivePlannerEvent).length;

    return {
      outreach: outreach > 0 ? outreach : undefined,
      planner: planner > 0 ? planner : undefined,
    };
  }, [events, isLoading]);
}
