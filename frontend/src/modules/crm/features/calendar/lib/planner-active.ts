import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * An ACTIONABLE Planner item: dated and not finished.
 *
 * The Planner sidebar badge's definition, named and given a home rather than living as an anonymous
 * inline filter in the badge hook. Extracting it changes no behaviour; it makes the rule reviewable
 * and gives the next reader somewhere to find out what the badge actually counts.
 *
 * DELIBERATELY BROADER than the CRM's other two pending sets, and the differences are the point:
 *
 *  - vs. the server's pending follow-up work (`DashboardController::pendingFollowUps()`, behind the
 *    "Follow-ups due" KPI and the Reports card) — that one is restricted to the four follow-up-work
 *    channels, so it excludes `task` and `consultation`. This includes them: an overdue task is
 *    still something the board is asking the BD to do.
 *  - vs. {@link ../../follow-ups/lib/outreach-pending} `isPendingOutreachEvent` — that one is the
 *    Outreach inbox's contents (follow-up + email only) and ignores dates entirely, because an
 *    undated email task still belongs in an inbox. This requires a date, because an undated
 *    backlog item is neither overdue nor upcoming and so is not what a "you have N waiting" badge
 *    is claiming.
 *
 * The three are not duplicates of one another and must not be collapsed; each answers a different
 * question for a different surface.
 *
 * `status !== "completed"` rather than a list of the statuses that currently mean it — see the note
 * in `outreach-pending.ts` on why the enumerated form is a drift hazard.
 */
export function isActivePlannerEvent(event: PlannerEvent): boolean {
  return event.startsAt !== null && event.status !== "completed";
}
