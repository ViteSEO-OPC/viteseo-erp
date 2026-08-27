"use client";

import { useMemo } from "react";
import { compareAsc, parseISO } from "date-fns";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import { isActivePlannerEvent } from "@/modules/crm/features/calendar/lib/planner-active";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { businessDateKey, formatBusiness, startOfBusinessTodayUtc } from "@/modules/crm/lib/timezone";
import type {
  CopilotContext,
  CopilotLeadStats,
  CopilotSchedule,
  CopilotScheduleEvent,
} from "@/modules/crm/features/copilot/engine/types";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * The single frontend data source the Copilot reads for any dynamic CRM figure or record.
 *
 * WHAT IS LIVE, AND WHAT IS STILL EMPTY. The `schedule` block is real: it is the signed-in BD's own
 * Planner. Everything else — leads, drafts, summary, scoring — is still deliberately empty, because
 * no ownership-scoped frontend source is wired for those yet. The engine treats an empty collection
 * as "no data" and falls back to generic guidance, so nothing is fabricated either way.
 *
 * NO NEW ENDPOINT AND NO SECOND DEFINITION. The schedule is derived from `usePlannerEvents()` — the
 * exact query the Planner's five views, its Board and the sidebar badge already share, so React
 * Query dedupes it to the same cached request and the Copilot cannot disagree with the Planner about
 * what exists. Visibility stays server-side (`PlannerEvent::scopeVisibleTo`); this hook never
 * filters by owner. "Still open work" is the already-extracted {@link isActivePlannerEvent}, and the
 * day boundary is `startOfBusinessTodayUtc()` — the CRM's one Manila implementation. Bucketing here
 * is arithmetic over those two, not a new rule.
 *
 * BD ONLY, ON PURPOSE. `/zerem/planner` is `allowedRoles: ["crm_bd"]` — a personal working surface
 * the product bars Admin/SA from at the route level. The Copilot renders on every page for every
 * role, so without this gate it would hand Admin/SA a personal schedule the CRM deliberately
 * withholds. For them `available` is false and every bucket is empty; the handler answers with the
 * management redirect the CRM already gives elsewhere.
 */

/** Events the BD still owes, dated, in Manila-day order. The one list every bucket is cut from. */
function openDatedEvents(events: PlannerEvent[]): (PlannerEvent & { startsAt: string })[] {
  return events
    .filter((e): e is PlannerEvent & { startsAt: string } => Boolean(e.startsAt))
    .filter(isActivePlannerEvent)
    .sort((a, b) => compareAsc(parseISO(a.startsAt), parseISO(b.startsAt)));
}

function toScheduleEvent(e: PlannerEvent & { startsAt: string }): CopilotScheduleEvent {
  return {
    id: e.id,
    title: e.title,
    eventType: e.eventType,
    startsAt: e.startsAt,
    // Formatted at the source so the engine never touches a timezone.
    timeLabel: e.allDay ? "All day" : formatBusiness(e.startsAt, "h:mm a"),
    leadName: e.lead?.business ?? null,
  };
}

const NO_SCHEDULE: CopilotSchedule = {
  available: false,
  today: [],
  week: [],
  upcoming: [],
  overdue: [],
};

function buildSchedule(events: PlannerEvent[], isBd: boolean): CopilotSchedule {
  if (!isBd) return NO_SCHEDULE;

  const open = openDatedEvents(events);
  const todayStart = startOfBusinessTodayUtc();
  const todayKey = businessDateKey(new Date());

  // "This week" = the next 7 Manila days INCLUDING today, which is what "may meeting ba ako this
  // week" asks in practice. Deliberately not an ISO calendar week: on a Friday, the useful answer is
  // the days ahead, not the two remaining before a Sunday reset.
  const weekEnd = new Date(todayStart);
  weekEnd.setUTCDate(weekEnd.getUTCDate() + 7);

  const today = open.filter((e) => businessDateKey(e.startsAt) === todayKey);
  const overdue = open.filter((e) => parseISO(e.startsAt) < todayStart);
  const fromToday = open.filter((e) => parseISO(e.startsAt) >= todayStart);

  return {
    available: true,
    today: today.map(toScheduleEvent),
    week: fromToday.filter((e) => parseISO(e.startsAt) < weekEnd).map(toScheduleEvent),
    // Everything still ahead, today included — the same "from today onward" rule the Planner's
    // Upcoming rail uses over this same cache.
    upcoming: fromToday.map(toScheduleEvent),
    overdue: overdue.map(toScheduleEvent),
  };
}

/**
 * Portfolio counts from the dashboard KPI payload.
 *
 * The SAME `queryKeys.dashboard.kpis()` query the BD dashboard, the Leads-by-Status card and the
 * Management KPI row already run, so React Query dedupes it to one request and the Copilot quotes
 * the identical number rendered beside it. Nothing is recomputed here.
 *
 * Ownership scoping is the endpoint's (`visibleTo`): a BD gets their own portfolio, Admin/SA the
 * whole CRM. That is the existing lead permission model, so no role gate is applied — unlike the
 * Planner, lead totals are legitimately management-visible.
 */
type KpiPayload = {
  totalLeads: number;
  highPriority: number;
  interested: number;
  followUpsDue: number;
  statusCounts: Record<string, number>;
};

function useLeadStats(): CopilotLeadStats {
  const { data } = useQuery<KpiPayload>({
    queryKey: queryKeys.dashboard.kpis(),
    queryFn: () => apiClient.get("/dashboard/kpis").then((r) => r.data.data),
  });

  // `available` false until the query resolves — the engine then says it has no figure rather than
  // quoting a zero that would read as "you have no leads".
  if (!data) {
    return { available: false, totalLeads: 0, highPriority: 0, interested: 0, followUpsDue: 0, statusCounts: {} };
  }

  return {
    available: true,
    totalLeads: data.totalLeads,
    highPriority: data.highPriority,
    interested: data.interested,
    followUpsDue: data.followUpsDue,
    statusCounts: data.statusCounts ?? {},
  };
}

/** Open Planner work grouped by lead, from the same events and the same BD gate as the schedule. */
function buildPlannerByLead(events: PlannerEvent[], isBd: boolean): Record<string, CopilotScheduleEvent[]> {
  if (!isBd) return {};

  const byLead: Record<string, CopilotScheduleEvent[]> = {};
  for (const e of openDatedEvents(events)) {
    if (!e.leadId) continue;
    (byLead[e.leadId] ??= []).push(toScheduleEvent(e));
  }

  return byLead;
}

export function useCopilotContext(): CopilotContext {
  const { data: plannerEvents = [] } = usePlannerEvents();
  const { hasRole } = usePermissions();
  const leadStats = useLeadStats();

  const isBd = hasRole("crm_bd");

  return useMemo(
    () => ({
      identity: { isBd },
      schedule: buildSchedule(plannerEvents, isBd),
      leadStats,
      plannerByLead: buildPlannerByLead(plannerEvents, isBd),
      // Unchanged and still empty — no frontend source is wired for these yet, and the Copilot must
      // never invent leads, companies, scores or counts.
      metrics: { hotLeads: 0, followUps: 0, drafts: 0, todayCalls: 0, newLeads: 0, assignedLeads: 0 },
      topLeads: [],
      followUpsList: [],
      draftEmail: { to: "", subject: "", body: "" },
    }),
    [plannerEvents, isBd, leadStats],
  );
}
