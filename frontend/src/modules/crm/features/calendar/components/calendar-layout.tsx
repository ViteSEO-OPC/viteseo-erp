"use client";

import { useCallback, useState } from "react";
import { CalendarView } from "@/modules/crm/features/calendar/components/calendar-view";
import { UpcomingList } from "@/modules/crm/features/calendar/components/upcoming-list";
import { PlannerModeBar, type PlannerMode } from "@/modules/crm/features/calendar/components/planner-mode-bar";
import { PlannerBoard } from "@/modules/crm/features/calendar/components/board/planner-board";
import { PlannerHistoryWorkspace } from "@/modules/crm/features/calendar/components/planner-history-workspace";
import { EventFormDialog } from "@/modules/crm/features/calendar/components/event-form-dialog";
import { CopilotWidget } from "@/modules/crm/features/copilot/components/copilot-widget";
import { useCalendarData } from "@/modules/crm/features/calendar/hooks/use-calendar-data";
import type { PlannerEvent, PlannerEventStatus } from "@/modules/crm/features/calendar/types/calendar-types";

/**
 * Planner container. Owns the Calendar/Board/History mode and the single create/edit dialog, so all
 * presentations trigger the same CRUD against the same shared data source. The Upcoming rail is
 * shown only in Calendar mode (the Board and History are full-width surfaces).
 *
 * History reads a different representation of the same endpoint (`?view=history`) through its own
 * read-only query, so the live Planner's shared cache and its optimistic Board drags are untouched.
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

        {mode === "calendar" && <CalendarView onEditEvent={openEdit} />}
        {mode === "board" && <PlannerBoard onEditEvent={openEdit} onAddItem={openCreate} />}
        {/* The same component the standalone /zerem/planner/history route renders — one History
            implementation, two entry points. Only BDs reach this one (the Planner is BD-only), so in
            practice it mounts without the BD scope selector. */}
        {mode === "history" && <PlannerHistoryWorkspace />}
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
