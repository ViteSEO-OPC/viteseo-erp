"use client";

import { useCallback, useState } from "react";
import { CalendarView } from "@/modules/crm/features/calendar/components/calendar-view";
import { UpcomingList } from "@/modules/crm/features/calendar/components/upcoming-list";
import { PlannerModeBar, type PlannerMode } from "@/modules/crm/features/calendar/components/planner-mode-bar";
import { PlannerBoard } from "@/modules/crm/features/calendar/components/board/planner-board";
import { EventFormDialog } from "@/modules/crm/features/calendar/components/event-form-dialog";
import { CopilotWidget } from "@/modules/crm/features/copilot/components/copilot-widget";
import { useCalendarData } from "@/modules/crm/features/calendar/hooks/use-calendar-data";
import type { PlannerEvent, PlannerEventStatus } from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * Planner container. Owns the Calendar/Board mode and the single create/edit dialog, so both
 * presentations trigger the same CRUD against the same shared data source. The Upcoming rail is
 * shown only in Calendar mode (the Board is a full-width surface, matching the design).
 */
export function CalendarLayout() {
  const { upcoming } = useCalendarData();
  const [mode, setMode] = useState<PlannerMode>("calendar");

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<PlannerEvent | null>(null);
  const [defaultStatus, setDefaultStatus] = useState<PlannerEventStatus | undefined>(undefined);

  const openCreate = useCallback((status?: PlannerEventStatus) => {
    setEditing(null);
    setDefaultStatus(status);
    setDialogOpen(true);
  }, []);

  const openEdit = useCallback((event: PlannerEvent) => {
    setEditing(event);
    setDefaultStatus(undefined);
    setDialogOpen(true);
  }, []);

  return (
    // Below md the rail cannot stay a fixed side column — it would squeeze the grid into a few
    // dozen pixels — so the shell stacks and the PAGE scrolls; from md up it is the unchanged
    // two-column shell whose calendar column scrolls on its own. `min-w-0` lets the calendar
    // column shrink below the intrinsic width of the grid table instead of overflowing sideways.
    <div className="flex flex-1 flex-col overflow-y-auto md:flex-row md:overflow-hidden -mx-4 lg:-mx-6 -mt-8">
      <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:overflow-y-auto md:p-6">
        <PlannerModeBar mode={mode} onModeChange={setMode} onNewTask={() => openCreate()} />
        {mode === "calendar" ? (
          <CalendarView onEditEvent={openEdit} />
        ) : (
          <PlannerBoard onEditEvent={openEdit} onAddItem={openCreate} />
        )}
      </div>

      {mode === "calendar" && <UpcomingList items={upcoming} />}
      <CopilotWidget />

      <EventFormDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        event={editing}
        defaultStatus={defaultStatus}
      />
    </div>
  );
}
