"use client";

import { useMemo, useState } from "react";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  useSensor,
  useSensors,
  closestCorners,
  type DragStartEvent,
  type DragEndEvent,
} from "@dnd-kit/core";
import { usePlannerEvents } from "@/modules/crm/features/calendar/hooks/use-planner-events";
import { useUpdatePlannerEvent } from "@/modules/crm/features/calendar/hooks/use-planner-mutations";
import { BoardColumn } from "@/modules/crm/features/calendar/components/board/board-column";
import { BoardCardContent } from "@/modules/crm/features/calendar/components/board/board-card";
import { BOARD_COLUMNS, isPlannerStatus } from "@/modules/crm/features/calendar/lib/event-type-meta";
import type { PlannerEvent, PlannerEventStatus } from "@/modules/crm/features/calendar/types/calendar-types";

type PlannerBoardProps = {
  onEditEvent: (event: PlannerEvent) => void;
  onAddItem: (status: PlannerEventStatus) => void;
};

/**
 * Jira-style Kanban Board. Consumes the SAME shared `usePlannerEvents()` source (grouped by
 * status) and the SAME mutations as the calendar — it introduces no separate state, cache, or
 * endpoint. Dragging a card to another column PATCHes its status through the optimistic mutation,
 * which updates the one shared cache (so the calendar reflects the move too) and auto-rolls back
 * on failure. The `DndContext` + sensor setup here is the reusable foundation a future
 * calendar-drag reschedule will build on.
 */
export function PlannerBoard({ onEditEvent, onAddItem }: PlannerBoardProps) {
  const { data: events = [] } = usePlannerEvents();
  const update = useUpdatePlannerEvent();
  const [activeId, setActiveId] = useState<string | null>(null);

  const grouped = useMemo(() => {
    const g: Record<PlannerEventStatus, PlannerEvent[]> = {
      todo: [],
      scheduled: [],
      in_progress: [],
      completed: [],
    };
    for (const e of events) g[e.status]?.push(e);
    return g;
  }, [events]);

  // A small activation distance lets a plain click through to the card's edit handler while a
  // deliberate drag starts the DnD.
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

  const activeEvent = activeId ? events.find((e) => e.id === activeId) ?? null : null;

  function handleDragStart(e: DragStartEvent) {
    setActiveId(String(e.active.id));
  }

  function handleDragEnd(e: DragEndEvent) {
    setActiveId(null);
    const overId = e.over ? String(e.over.id) : null;
    const id = String(e.active.id);
    if (!overId || !isPlannerStatus(overId)) return;

    const dragged = events.find((x) => x.id === id);
    if (!dragged || dragged.status === overId) return;

    // Optimistic status change via the shared mutation (silent — no toast per drag; rollback on error).
    update.mutate({ id, patch: { status: overId }, silent: true });
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCorners}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {BOARD_COLUMNS.map((col) => (
          <BoardColumn
            key={col.status}
            column={col}
            events={grouped[col.status]}
            onEditEvent={onEditEvent}
            onAddItem={onAddItem}
          />
        ))}
      </div>
      <DragOverlay>{activeEvent ? <BoardCardContent event={activeEvent} dragging /> : null}</DragOverlay>
    </DndContext>
  );
}
