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
  // The card list is the drop target (id = the column's status). It is also the scroll container
  // from md up; dnd-kit measures a droppable by its visible box and auto-scrolls it during a drag,
  // so the two roles coexist on one node and the drop target stays what the BD can actually see.
  const { setNodeRef, isOver } = useDroppable({ id: column.status });

  return (
    // A BD's To Do column holds hundreds of events. Unbounded, the column grew to its content and
    // grid `align-items: stretch` made the other three match it: 208 cards measured a 25,618px page
    // with an empty 25,422px "Scheduled" panel beside it, and "Add item" — then the list's last
    // child — sat 25,512px down, off every screen.
    //
    // The cap has to come from the viewport because nothing above this component has a definite
    // height: <main> is a scroll container, but CalendarLayout's shell is content-sized, so its
    // `md:overflow-hidden` / `md:overflow-y-auto` are inert and there is no percentage height to
    // inherit. Fixing that upstream would be the tidier bound, but it would also make that
    // `overflow-hidden` live and clip the Upcoming rail, which has no scroller of its own — a new
    // bug in Calendar mode. So the bound is local to the Board and the shell is left alone.
    //
    // 18rem is what sits above and below this column: a 79px topbar (py-3 + `lg:text-page-title`),
    // the 36px mode bar, the shell's p-6 (48) and gap-4 (16), and <main>'s pb-24 (96) — 275px at
    // lg/xl, 257px at md, where the title is smaller. 288px covers the larger case with ~13px to
    // spare, so no viewport is left with a stray page scroll, and the slack falls inside the pb-24
    // strip already reserved for the Copilot FAB, where it costs nothing.
    //
    // `md:min-h-[14rem]` is the floor. A CSS min-height beats a max-height, so on a viewport too
    // short for the column's own furniture (header + the list's 120px + Add item ≈ 222px) the
    // column keeps its shape and the page scrolls, rather than spilling its contents outside the
    // rounded panel. Below md nothing applies: mobile keeps today's page-scrolling stack.
    <div className="flex min-w-0 flex-col rounded-xl bg-muted p-3 md:max-h-[calc(100vh-18rem)] md:min-h-[14rem]">
      <div className="mb-2 flex shrink-0 items-center justify-between px-1">
        <div className="flex items-center gap-2">
          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: column.dot }} aria-hidden />
          <span className="text-sm font-bold text-foreground">{column.label}</span>
        </div>
        <span className="text-xs font-medium text-muted-foreground">{events.length}</span>
      </div>

      <div
        ref={setNodeRef}
        className={cn(
          "flex min-h-[120px] flex-1 flex-col gap-2 rounded-lg p-1 transition-colors md:overflow-y-auto",
          isOver && "bg-teal-50 ring-1 ring-inset ring-teal-200",
        )}
      >
        {events.map((event) => (
          <BoardCard key={event.id} event={event} onEdit={() => onEditEvent(event)} />
        ))}
      </div>

      {/* Pinned below the scroller instead of trailing the cards, so it stays reachable at any
          count. `mx-1 mt-2` reproduces the inset it had as a child of the list's `p-1`: 4px each
          side, and 8px + the list's 4px bottom padding = the same 12px it sat below the last card
          when the list's `gap-2` and its own `mt-1` stacked. */}
      <button
        type="button"
        onClick={() => onAddItem(column.status)}
        className="mx-1 mt-2 flex shrink-0 items-center justify-center gap-1 rounded-lg border border-dashed border-border py-2 text-xs font-medium text-muted-foreground transition-colors hover:border-border hover:text-secondary-foreground"
      >
        <Plus className="h-3.5 w-3.5" />
        Add item
      </button>
    </div>
  );
}
