import type { ApprovalStatus, Priority } from "../../../types";

export type ViewKey = "list" | "kanban" | "calendar";

export interface WorkItem {
  // 'task' = a task card (default). 'subtask' = one of the member's own subtasks,
  // rendered on their "My Tasks" board with the parent task as context.
  kind: "task" | "subtask";
  key: string;
  // The task UUID — opening any card opens this (parent) task's modal.
  id: string;
  // Set on subtask cards: the subtask UUID whose status a drag updates.
  subtaskId?: string;
  // Set on subtask cards: the parent task's title, shown as context.
  parentTitle?: string;
  projectId: string;
  projectColor: string | null;
  title: string;
  priority: Priority;
  statusName: string | null;
  // null = "no status" (e.g. the task's custom status was deleted).
  statusKey: string | null;
  statusColor: string | null;
  assignee?: { name: string; avatar?: string | null } | null;
  assigneeId: number | null;
  groupName?: string | null;
  // The owning group's hex, shown as a swatch beside its name on group tasks.
  groupColor?: string | null;
  deadline: string | null;
  hasMySubtask: boolean;
  // The item is a group task whose group the requesting user leads — the leader
  // owns the parent task, so it stays on their "My Tasks" board.
  leadsMyGroup: boolean;
  // Review state. Anything not "approved" is the viewer's own proposal: only
  // they can see it, and it can't be dragged or advanced until it's approved.
  approvalStatus?: ApprovalStatus;
}

/** A Kanban/List column: the 3 defaults, one per distinct custom status, + No Status. */
export interface StatusColumn {
  colId: string;
  label: string;
  color: string;
}

export const NO_STATUS = "__none__";
export const NO_STATUS_COLOR = "#9ca3af";

// Sentinel member-filter id for "unassigned" (no real user has id 0).
export const UNASSIGNED_ID = 0;

// Batch size for the List view's lazy-loaded status sections.
export const BATCH_SIZE = 10;

/**
 * Column identity for a work item. The 3 protected defaults merge across
 * projects by their `key`; custom statuses (which all share key "custom") merge
 * by lowercased name, so two projects' "Review" statuses share one column while
 * "Review" and "Blocked" stay separate.
 */
export function colIdOf(statusKey: string | null, statusName: string | null): string {
  if (!statusKey) return NO_STATUS;
  if (statusKey === "custom") return `custom:${(statusName ?? "").toLowerCase()}`;
  return statusKey;
}

export const VIEWS: { key: ViewKey; label: string }[] = [
  { key: "list", label: "List" },
  { key: "kanban", label: "Kanban" },
  { key: "calendar", label: "Calendar" },
];

export const STATUS_COLUMN_COLORS: Record<string, string> = {
  todo: "#6b7280",
  in_progress: "#6366f1",
  done: "#10b981",
};

export const STATUS_LABELS: Record<string, string> = {
  todo: "To Do",
  in_progress: "In Progress",
  done: "Done",
};

export function dayKey(iso: string | null): string {
  return iso ? iso.slice(0, 10) : "";
}

export function fmtShort(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? "—"
    : d.toLocaleDateString("en-US", { month: "short", day: "2-digit" });
}

/**
 * Ordered columns for the board: the 3 defaults, then each known custom status,
 * then any custom status only present on items, then "No Status" if needed.
 */
export function buildColumns(
  items: WorkItem[],
  customColumns: StatusColumn[],
): { columns: StatusColumn[]; buckets: Map<string, WorkItem[]> } {
  const columns: StatusColumn[] = [
    { colId: "todo", label: STATUS_LABELS.todo, color: STATUS_COLUMN_COLORS.todo },
    { colId: "in_progress", label: STATUS_LABELS.in_progress, color: STATUS_COLUMN_COLORS.in_progress },
    { colId: "done", label: STATUS_LABELS.done, color: STATUS_COLUMN_COLORS.done },
    ...customColumns,
  ];

  const buckets = new Map<string, WorkItem[]>();
  for (const c of columns) buckets.set(c.colId, []);

  const noneItems: WorkItem[] = [];
  for (const i of items) {
    const cid = colIdOf(i.statusKey, i.statusName);
    if (cid === NO_STATUS) {
      noneItems.push(i);
      continue;
    }
    if (!buckets.has(cid)) {
      buckets.set(cid, []);
      columns.push({
        colId: cid,
        label: i.statusName ?? cid,
        color: i.statusColor ?? NO_STATUS_COLOR,
      });
    }
    buckets.get(cid)!.push(i);
  }

  if (noneItems.length) {
    columns.push({ colId: NO_STATUS, label: "No Status", color: NO_STATUS_COLOR });
    buckets.set(NO_STATUS, noneItems);
  }

  return { columns, buckets };
}
