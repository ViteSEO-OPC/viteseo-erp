"use client";

import { useMemo } from "react";
import { parseISO, compareAsc } from "date-fns";
import {
  formatBusiness,
  businessDateKey,
  startOfBusinessTodayUtc,
} from "@/modules/crm/lib/timezone";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import type { CalendarEvent } from "@/modules/crm/components/shared/calendar-grid/calendar-grid";
import type { UpcomingItem, PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

const UPCOMING_LIMIT = 5;

/**
 * View-adapter over the shared Planner data source (`usePlannerEvents`) — the ONLY source of
 * truth. Derives the grid events (all five views) and the "Upcoming" rail from the same live
 * PlannerEvent collection, so every presentation stays in sync automatically. No mock data, no
 * per-view state, no separate cache.
 */
export function useCalendarData() {
  const { data: plannerEvents = [], isLoading, isError } = usePlannerEvents();

  // Grid events — only DATED events appear on the calendar; a Board-only backlog item (no
  // `startsAt`) is excluded here and surfaces only on the Board.
  const events = useMemo<CalendarEvent[]>(
    () =>
      plannerEvents
        .filter((e): e is PlannerEvent & { startsAt: string } => Boolean(e.startsAt))
        .map(toGridEvent),
    [plannerEvents],
  );

  // "Upcoming" rail — the next few dated events from today (business timezone) onward, soonest
  // first.
  const upcoming = useMemo<UpcomingItem[]>(() => {
    const todayStart = startOfBusinessTodayUtc();
    return plannerEvents
      .filter((e): e is PlannerEvent & { startsAt: string } => Boolean(e.startsAt))
      .filter((e) => parseISO(e.startsAt) >= todayStart)
      .sort((a, b) => compareAsc(parseISO(a.startsAt), parseISO(b.startsAt)))
      .slice(0, UPCOMING_LIMIT)
      .map(toUpcomingItem);
  }, [plannerEvents]);

  return { events, upcoming, isLoading, isError };
}

function toGridEvent(e: PlannerEvent & { startsAt: string }): CalendarEvent {
  return {
    id: e.id,
    title: e.title,
    type: e.eventType,
    start: e.startsAt,
    end: e.endsAt,
    allDay: e.allDay,
  };
}

function toUpcomingItem(e: PlannerEvent & { startsAt: string }): UpcomingItem {
  const todayKey = businessDateKey(new Date());
  return {
    id: e.id,
    date: businessDateKey(e.startsAt),
    weekday: formatBusiness(e.startsAt, "EEE"),
    day: Number(formatBusiness(e.startsAt, "d")),
    type: e.eventType,
    title: e.title,
    time: e.allDay ? "All day" : formatBusiness(e.startsAt, "h:mm a"),
    isToday: businessDateKey(e.startsAt) === todayKey,
  };
}
