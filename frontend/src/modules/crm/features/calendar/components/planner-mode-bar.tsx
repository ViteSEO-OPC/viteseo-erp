"use client";

import { CalendarDays, LayoutGrid, Plus, type LucideIcon } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";
import { EVENT_TYPE_META } from "@/modules/crm/features/calendar/lib/event-type-meta";
import type { EventType } from "@/modules/crm/features/calendar/types/calendar-types";

export type PlannerMode = "calendar" | "board";

// Calendar shows the four core types; the Board adds Follow-up (matches the approved design).
const CALENDAR_LEGEND: EventType[] = ["email", "call", "meeting", "task"];
const BOARD_LEGEND: EventType[] = ["email", "call", "meeting", "task", "follow-up"];

type PlannerModeBarProps = {
  mode: PlannerMode;
  onModeChange: (mode: PlannerMode) => void;
  onNewTask: () => void;
};

export function PlannerModeBar({ mode, onModeChange, onNewTask }: PlannerModeBarProps) {
  const legend = mode === "board" ? BOARD_LEGEND : CALENDAR_LEGEND;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-0.5">
        <ModeTab active={mode === "calendar"} onClick={() => onModeChange("calendar")} icon={CalendarDays} label="Calendar" />
        <ModeTab active={mode === "board"} onClick={() => onModeChange("board")} icon={LayoutGrid} label="Board" />
      </div>

      <div className="flex items-center gap-4">
        <div className="hidden items-center gap-3 md:flex">
          {legend.map((t) => (
            <span key={t} className="flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full" style={{ backgroundColor: EVENT_TYPE_META[t].dot }} aria-hidden />
              <span className="text-xs text-secondary-foreground">{EVENT_TYPE_META[t].label}</span>
            </span>
          ))}
        </div>
        <Button variant="default" size="sm" onClick={onNewTask}>
          <Plus className="h-4 w-4" />
          New Task
        </Button>
      </div>
    </div>
  );
}

function ModeTab({
  active,
  onClick,
  icon: Icon,
  label,
}: {
  active: boolean;
  onClick: () => void;
  icon: LucideIcon;
  label: string;
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={cn(
        "flex items-center gap-1.5 rounded-md px-3 py-1 text-[13px] font-medium transition-colors",
        active ? "bg-card text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground",
      )}
    >
      <Icon className="h-4 w-4" />
      {label}
    </button>
  );
}
