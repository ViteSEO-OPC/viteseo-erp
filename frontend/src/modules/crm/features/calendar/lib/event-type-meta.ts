import type { EventType, PlannerEventStatus } from "@/modules/crm/features/calendar/types/calendar-types";

/** Shared per-type presentation (legend, Board card tags). One source for type colors/labels. */
export const EVENT_TYPE_META: Record<EventType, { label: string; dot: string; bg: string; text: string }> = {
  email: { label: "Email", dot: "#4FD8D7", bg: "#E7FAFA", text: "#0F6F6E" },
  call: { label: "Call", dot: "#7B9EF9", bg: "#DAEDF9", text: "#2C4A7C" },
  meeting: { label: "Meeting", dot: "#F9C74F", bg: "#FEF8EE", text: "#8A6D1F" },
  task: { label: "Task", dot: "#F08080", bg: "#FFEEEE", text: "#9B3B3B" },
  "follow-up": { label: "Follow-up", dot: "#A78BFA", bg: "#F1ECFE", text: "#5B3FA6" },
};

/** The four Board columns, in order — the `PlannerEvent.status` value each one groups. */
export const BOARD_COLUMNS: { status: PlannerEventStatus; label: string; dot: string }[] = [
  { status: "todo", label: "To Do", dot: "#9CA3AF" },
  { status: "scheduled", label: "Scheduled", dot: "#7B9EF9" },
  { status: "in_progress", label: "In Progress", dot: "#F9C74F" },
  { status: "completed", label: "Completed", dot: "#4FD8D7" },
];

export function isPlannerStatus(s: string): s is PlannerEventStatus {
  return s === "todo" || s === "scheduled" || s === "in_progress" || s === "completed";
}
