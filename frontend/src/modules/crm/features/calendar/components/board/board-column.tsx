"use client";

import { useDroppable } from "@dnd-kit/core";
import { Plus } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { BoardCard } from "@/modules/crm/features/calendar/components/board/board-card";
import type { PlannerEvent, PlannerEventStatus } from "@/modules/crm/features/calendar/types/calendar-types";

type BoardColumnProps = {
  column: { status: PlannerEventStatus; label: string; dot: string };
  events: PlannerEvent[];
  onEditEvent: (event: PlannerEvent) => void;
  onAddItem: (status: PlannerEventStatus) => void;
};

export function BoardColumn({ column, events, onEditEvent, onAddItem }: BoardColumnProps) {
  // The whole column body is the drop target (id = the column's status).
  const { setNodeRef, isOver } = useDroppable({ id: column.status });

  return (
    <div className="flex min-w-0 flex-col rounded-xl bg-muted p-3">
      <div className="mb-2 flex items-center justify-between px-1">
        <div className="flex items-center gap-2">
          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: column.dot }} aria-hidden />
          <span className="text-sm font-bold text-foreground">{column.label}</span>
        </div>
        <span className="text-xs font-medium text-muted-foreground">{events.length}</span>
      </div>

      <div
        ref={setNodeRef}
        className={cn(
          "flex min-h-[120px] flex-1 flex-col gap-2 rounded-lg p-1 transition-colors",
          isOver && "bg-teal-50 ring-1 ring-inset ring-teal-200",
        )}
      >
        {events.map((event) => (
          <BoardCard key={event.id} event={event} onEdit={() => onEditEvent(event)} />
        ))}
        <button
          type="button"
          onClick={() => onAddItem(column.status)}
          className="mt-1 flex items-center justify-center gap-1 rounded-lg border border-dashed border-border py-2 text-xs font-medium text-muted-foreground transition-colors hover:border-border hover:text-secondary-foreground"
        >
          <Plus className="h-3.5 w-3.5" />
          Add item
        </button>
      </div>
    </div>
  );
}
