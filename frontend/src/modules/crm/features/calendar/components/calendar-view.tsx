"use client";

import { useCallback, useRef, useState } from "react";
import type { CalendarApi } from "@fullcalendar/core";
import {
  CalendarGrid,
  type PlannerView,
  type CalendarEvent,
} from "@/modules/crm/components/shared/calendar-grid/calendar-grid";
import { CalendarToolbar } from "@/modules/crm/features/calendar/components/calendar-toolbar";
import { useCalendarData } from "@/modules/crm/features/calendar/hooks/use-calendar-data";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

type CalendarViewProps = {
  /** Open the editor for a clicked calendar event. */
  onEditEvent: (event: PlannerEvent) => void;
};

export function CalendarView({ onEditEvent }: CalendarViewProps) {
  const { events } = useCalendarData();
  // The raw list (same shared cache) — resolves a clicked grid event back to its full PlannerEvent.
  const { data: rawEvents = [] } = usePlannerEvents();

  const [view, setView] = useState<PlannerView>("month");
  const [title, setTitle] = useState("");
  const apiRef = useRef<CalendarApi | null>(null);

  const handleApiReady = useCallback((api: CalendarApi) => {
    apiRef.current = api;
    setTitle(api.view.title);
  }, []);

  const handleEventClick = useCallback(
    (ce: CalendarEvent) => {
      const full = rawEvents.find((e) => e.id === ce.id);
      if (full) onEditEvent(full);
    },
    [rawEvents, onEditEvent],
  );

  return (
    <div className="flex flex-col gap-4">
      <CalendarToolbar
        title={title}
        view={view}
        onViewChange={setView}
        onPrev={() => apiRef.current?.prev()}
        onNext={() => apiRef.current?.next()}
        onToday={() => apiRef.current?.today()}
      />
      <CalendarGrid
        events={events}
        view={view}
        onApiReady={handleApiReady}
        onDatesSet={({ title }) => setTitle(title)}
        onEventClick={handleEventClick}
      />
    </div>
  );
}
