"use client";

import { useCallback, useEffect, useRef } from "react";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import multiMonthPlugin from "@fullcalendar/multimonth";
import listPlugin from "@fullcalendar/list";
import luxon3Plugin from "@fullcalendar/luxon3";
import type { EventClickArg, EventContentArg, EventInput } from "@fullcalendar/core";
import { BUSINESS_TIMEZONE } from "@/modules/crm/lib/timezone";
import type {
  CalendarEvent,
  DatesSetInfo,
  PlannerView,
} from "@/modules/crm/components/shared/calendar-grid/calendar-grid";

const TYPE_CONFIG: Record<string, { dot: string; bg: string }> = {
  email: { dot: "#4FD8D7", bg: "#E7FAFA" },
  call: { dot: "#7B9EF9", bg: "#DAEDF9" },
  meeting: { dot: "#F9C74F", bg: "#FEF8EE" },
  task: { dot: "#F08080", bg: "#FFEEEE" },
  "follow-up": { dot: "#A78BFA", bg: "#F1ECFE" },
  consultation: { dot: "#14B8A6", bg: "#E0F7F5" },
};
// Concrete literal (not an index access) so it is never `undefined` — unknown types fall back to
// the task tint.
const FALLBACK: { dot: string; bg: string } = { dot: "#F08080", bg: "#FFEEEE" };

// One FullCalendar view id per Planner view. All plugins are always loaded so switching is a
// client-side changeView() with no refetch or remount.
const VIEW_MAP: Record<PlannerView, string> = {
  month: "dayGridMonth",
  week: "timeGridWeek",
  day: "timeGridDay",
  year: "multiMonthYear",
  schedule: "listMonth",
};

// luxon3Plugin lets FullCalendar render/position events in a named IANA timezone (the business
// timezone) rather than the viewer's local zone — so every viewer sees the same Manila wall time.
const PLUGINS = [dayGridPlugin, timeGridPlugin, multiMonthPlugin, listPlugin, luxon3Plugin];

type InnerProps = {
  events: CalendarEvent[];
  view: PlannerView;
  onApiReady?: (api: import("@fullcalendar/core").CalendarApi) => void;
  onDatesSet?: (info: DatesSetInfo) => void;
  onEventClick?: (event: CalendarEvent) => void;
};

// The Month grid's bespoke pill. Other views use FullCalendar's native rendering (timed blocks
// for Week/Day, agenda rows for Schedule, dots for Year), tinted per event type via the colors
// set on each EventInput below.
function monthPill(arg: EventContentArg) {
  const type = (arg.event.extendedProps.evType as string) || "task";
  const cfg = TYPE_CONFIG[type] ?? FALLBACK;
  return (
    <div className="cal-event-pill" style={{ backgroundColor: cfg.bg }}>
      <span className="cal-event-pill-dot" style={{ backgroundColor: cfg.dot }} />
      <span className="cal-event-pill-label">{arg.event.title}</span>
    </div>
  );
}

export default function CalendarGridInner({
  events,
  view,
  onApiReady,
  onDatesSet,
  onEventClick,
}: InnerProps) {
  const ref = useRef<FullCalendar>(null);

  // Expose the imperative API upward once mounted — the custom toolbar drives prev/next/today
  // across every view without holding its own date state.
  useEffect(() => {
    if (ref.current) onApiReady?.(ref.current.getApi());
  }, [onApiReady]);

  // Switch views imperatively — changing `initialView` after mount is a no-op in FullCalendar.
  useEffect(() => {
    ref.current?.getApi().changeView(VIEW_MAP[view]);
  }, [view]);

  const handleEventClick = useCallback(
    (info: EventClickArg) => {
      const ext = info.event.extendedProps;
      if (ext?.calendarEvent && onEventClick) {
        onEventClick(ext.calendarEvent as CalendarEvent);
      }
    },
    [onEventClick],
  );

  const eventInputs: EventInput[] = events.map((ev) => {
    const cfg = TYPE_CONFIG[ev.type] ?? FALLBACK;
    return {
      id: ev.id,
      title: ev.title,
      start: ev.start,
      end: ev.end ?? undefined,
      allDay: ev.allDay,
      backgroundColor: cfg.bg,
      borderColor: cfg.dot,
      textColor: "#1F2937",
      extendedProps: { calendarEvent: ev, evType: ev.type },
    };
  });

  return (
    <div className="calendar-grid">
      <FullCalendar
        ref={ref}
        plugins={PLUGINS}
        timeZone={BUSINESS_TIMEZONE}
        initialView={VIEW_MAP[view]}
        firstDay={1}
        weekends={true}
        height="auto"
        headerToolbar={false}
        nowIndicator={true}
        dayMaxEvents={3}
        allDaySlot={true}
        slotMinTime="06:00:00"
        slotMaxTime="21:00:00"
        datesSet={(arg) => onDatesSet?.({ title: arg.view.title })}
        dayCellClassNames={(arg) => {
          const classes: string[] = [];
          if (arg.isToday) classes.push("cal-today");
          if (!arg.isCurrentMonth) classes.push("cal-other-month");
          return classes;
        }}
        events={eventInputs}
        eventClick={handleEventClick}
        views={{
          dayGridMonth: {
            dayCellContent: (arg) => (
              <div className="cal-cell-inner">
                <span className="cal-date-num">{arg.dayNumberText.replace(/日/g, "")}</span>
              </div>
            ),
            eventContent: monthPill,
          },
          multiMonthYear: { multiMonthMaxColumns: 4 },
          listMonth: {
            listDayFormat: { weekday: "long", month: "short", day: "numeric" },
            noEventsContent: "No scheduled events",
          },
        }}
      />
    </div>
  );
}
