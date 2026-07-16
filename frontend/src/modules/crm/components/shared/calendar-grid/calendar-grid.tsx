"use client";

import dynamic from "next/dynamic";
import type { CalendarApi } from "@fullcalendar/core";

const FullCalendarWrapper = dynamic(() => import("./calendar-grid-inner"), {
  ssr: false,
  loading: () => (
    <div className="h-[520px] w-full animate-pulse rounded-lg bg-muted" />
  ),
});

/** The five Planner calendar presentations. Each maps to a FullCalendar view internally. */
export type PlannerView = "month" | "week" | "day" | "year" | "schedule";

/**
 * Grid event shape — carries real ISO datetimes so time-based views (Week/Day) position events
 * correctly, not just a date. Derived from a `PlannerEvent` by the view-adapter; the grid never
 * fetches or owns data.
 */
export type CalendarEvent = {
  id: string;
  title: string;
  type: "email" | "call" | "meeting" | "task" | "follow-up";
  start: string; // ISO8601
  end?: string | null; // ISO8601
  allDay: boolean;
};

export type DatesSetInfo = { title: string };

type CalendarGridProps = {
  events: CalendarEvent[];
  view: PlannerView;
  /** Receives the FullCalendar API once mounted, so the custom toolbar can drive prev/next/today. */
  onApiReady?: (api: CalendarApi) => void;
  /** Fired when the visible range changes — reports the view's title for the toolbar label. */
  onDatesSet?: (info: DatesSetInfo) => void;
  onEventClick?: (event: CalendarEvent) => void;
};

export function CalendarGrid(props: CalendarGridProps) {
  return <FullCalendarWrapper {...props} />;
}
