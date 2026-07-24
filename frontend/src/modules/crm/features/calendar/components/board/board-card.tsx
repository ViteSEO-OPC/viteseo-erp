"use client";

import { useDraggable } from "@dnd-kit/core";
import { CSS } from "@dnd-kit/utilities";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { eventTypeMeta } from "@/modules/crm/features/calendar/lib/event-type-meta";
import { formatBusiness } from "@/modules/crm/lib/timezone";
import { cn } from "@/modules/crm/lib/utils";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

/** Presentational card (no drag wiring) — reused by the draggable card and the DragOverlay. */
export function BoardCardContent({ event, dragging }: { event: PlannerEvent; dragging?: boolean }) {
  // Resolved through the tolerant helper: `eventType` is runtime data, so a type newer than this
  // build must degrade to a neutral tag — never crash the Board.
  const meta = eventTypeMeta(event.eventType);
  const hint = event.startsAt
    ? formatBusiness(event.startsAt, event.allDay ? "MMM d" : "MMM d · h:mm a")
    : null;
  const leadLine = event.lead
    ? [event.lead.contact ?? event.lead.business, event.lead.industry].filter(Boolean).join(" · ")
    : null;

  return (
    <div
      className={cn(
        "rounded-lg border border-border-subtle bg-card p-3 shadow-sm",
        dragging && "shadow-md ring-2 ring-teal-300",
      )}
    >
      <div className="mb-1.5 flex items-center justify-between gap-2">
        <span
          className="rounded px-1.5 py-0.5 text-[11px] font-semibold"
          style={{ backgroundColor: meta.bg, color: meta.text }}
        >
          {meta.label}
        </span>
        {hint && <span className="shrink-0 text-[11px] text-muted-foreground">{hint}</span>}
      </div>
      <p className="text-sm font-semibold leading-snug text-foreground">{event.title}</p>
      {leadLine && <p className="mt-0.5 text-xs text-muted-foreground">{leadLine}</p>}
      {event.owner && (
        <div className="mt-2 flex items-center gap-1.5">
          <AvatarInitials initials={event.owner.name} size="sm" />
          <span className="text-xs text-secondary-foreground">{event.owner.name}</span>
        </div>
      )}
    </div>
  );
}

/** Draggable Board card. A short drag moves the card; a plain click opens the editor. */
export function BoardCard({ event, onEdit }: { event: PlannerEvent; onEdit: () => void }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: event.id,
  });
  const style = transform ? { transform: CSS.Translate.toString(transform) } : undefined;

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      onClick={onEdit}
      role="button"
      tabIndex={0}
      className={cn("cursor-grab active:cursor-grabbing", isDragging && "opacity-40")}
    >
      <BoardCardContent event={event} />
    </div>
  );
}
