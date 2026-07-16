"use client";

import { useRef } from "react";
import { Avatar, Card, PriorityBadge } from "@/core/ui";
import { isOverdue, OverdueBadge } from "../../overdue";
import {
  buildColumns,
  colIdOf,
  fmtShort,
  NO_STATUS,
  type StatusColumn,
  type WorkItem,
} from "./shared";

interface KanbanViewProps {
  items: WorkItem[];
  customColumns: StatusColumn[];
  projectName: Map<string, string>;
  onOpen: (taskId: string) => void;
  draggingKey: string | null;
  overCol: string | null;
  onDragStart: (item: WorkItem) => void;
  onDragEnd: () => void;
  onDragOverCol: (colKey: string) => void;
  onDropCol: (colKey: string) => void;
}

function KanbanCard({
  item,
  projectName,
  onOpen,
  onDragStart,
  onDragEnd,
  dragging,
}: {
  item: WorkItem;
  projectName: Map<string, string>;
  onOpen: (taskId: string) => void;
  onDragStart: (item: WorkItem) => void;
  onDragEnd: () => void;
  dragging: boolean;
}): React.ReactElement {
  const justDragged = useRef(false);
  return (
    <div
      draggable
      role="button"
      tabIndex={0}
      onDragStart={(e) => {
        e.dataTransfer.effectAllowed = "move";
        justDragged.current = true;
        onDragStart(item);
      }}
      onDragEnd={() => {
        onDragEnd();
        setTimeout(() => {
          justDragged.current = false;
        }, 60);
      }}
      onClick={() => {
        if (justDragged.current) return;
        onOpen(item.id);
      }}
      className={`block w-full cursor-grab text-left active:cursor-grabbing ${dragging ? "opacity-40" : ""}`}
    >
      <Card className=" p-4 transition-shadow hover:shadow-md">
        {/* Project pill */}
        <div className="flex items-center gap-2">
          <span
            className="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold"
            style={{
              backgroundColor: `${item.projectColor ?? "#6366f1"}15`,
              color: item.projectColor ?? "#6366f1",
            }}
          >
            {projectName.get(item.projectId) ?? ""}
          </span>
          {item.kind === "subtask" && (
            <span className="inline-flex items-center rounded-md bg-black/5 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-ink-soft">
              Subtask
            </span>
          )}
        </div>

        {/* Parent-task context for a subtask card */}
        {item.kind === "subtask" && item.parentTitle && (
          <span className="block truncate text-xs text-ink-faint">
            ↳ in {item.parentTitle}
          </span>
        )}

        <span className="block font-semibold text-ink leading-snug">
          {item.title}
        </span>

        <div className="flex items-center gap-2">
          <PriorityBadge priority={item.priority} />
          {isOverdue(item.deadline, item.statusKey) && <OverdueBadge />}
        </div>

        <div className="border-t border-line" />

        <div className="flex items-center justify-between">
          {item.assignee ? (
            <Avatar
              name={item.assignee.name}
              src={item.assignee.avatar}
              size={28}
            />
          ) : (
            <span className="text-xs text-ink-faint">
              {item.kind === "subtask"
                ? "My subtask"
                : (item.groupName ?? "Unassigned")}
            </span>
          )}
          <span
            className={`text-xs ${
              isOverdue(item.deadline, item.statusKey)
                ? "font-bold text-danger"
                : "text-ink-soft"
            }`}
          >
            {fmtShort(item.deadline)}
          </span>
        </div>
      </Card>
    </div>
  );
}

export function KanbanView({
  items,
  customColumns,
  projectName,
  onOpen,
  draggingKey,
  overCol,
  onDragStart,
  onDragEnd,
  onDragOverCol,
  onDropCol,
}: KanbanViewProps): React.ReactElement {
  // Kanban shows the 3 default columns + every known custom status (even when
  // empty, so a newly-added status is immediately visible) + No Status if used.
  const { columns, buckets } = buildColumns(items, customColumns);

  // The column the currently-dragged card lives in — dropping it back there is a
  // no-op, so that column is never highlighted as a valid target.
  const draggingItem = draggingKey
    ? items.find((i) => i.key === draggingKey) ?? null
    : null;
  const sourceColId = draggingItem
    ? colIdOf(draggingItem.statusKey, draggingItem.statusName)
    : null;

  return (
    <div className="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2 sm:snap-none sm:gap-5">
      {columns.map((col) => {
        const colItems = buckets.get(col.colId) ?? [];
        const droppable = col.colId !== NO_STATUS;
        // A valid drop target: droppable, a drag is in progress, and it's not the
        // card's own column. Every such column gets a standing hint; the one the
        // cursor is over gets the stronger "release to drop" treatment.
        const isValidTarget =
          droppable && draggingItem !== null && col.colId !== sourceColId;
        const isOver = isValidTarget && overCol === col.colId;
        return (
          <div
            key={col.colId}
            onDragOver={(e) => {
              if (!droppable) return;
              e.preventDefault();
              e.dataTransfer.dropEffect = "move";
              onDragOverCol(col.colId);
            }}
            onDrop={(e) => {
              if (!droppable) return;
              e.preventDefault();
              onDropCol(col.colId);
            }}
            className={`flex w-[85vw] max-w-[340px] shrink-0 snap-start flex-col overflow-hidden rounded-xl -outline-offset-2 transition-all sm:w-[300px] lg:w-auto lg:flex-1 lg:min-w-[280px] ${
              isOver
                ? "outline-2 outline-solid outline-brand"
                : isValidTarget
                  ? "outline-2 outline-dashed outline-brand/40"
                  : "outline-none"
            }`}
            style={{
              background: `${col.color}${isOver ? "2e" : "15"}`,
            }}
          >
            {/* Column header */}
            <div className="flex items-center gap-2 px-4 py-3">
              <span
                className="pl-2 text-sm font-bold"
                style={{ color: col.color }}
              >
                {col.label}
              </span>
              <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-surface px-1.5 text-xs font-bold text-ink-soft">
                {colItems.length}
              </span>
            </div>
            <div className="min-h-16 flex-1 space-y-3 p-3">
              {colItems.map((i) => (
                <KanbanCard
                  key={i.key}
                  item={i}
                  projectName={projectName}
                  onOpen={onOpen}
                  onDragStart={onDragStart}
                  onDragEnd={onDragEnd}
                  dragging={draggingKey === i.key}
                />
              ))}
              {/* Drop placeholder in a non-empty column the cursor is over. */}
              {isOver && colItems.length > 0 && (
                <p className="rounded-xl border-2 border-dashed border-brand bg-brand-soft/20 px-3 py-4 text-center text-xs font-semibold text-brand">
                  Drop to move here
                </p>
              )}
              {colItems.length === 0 && (
                <p
                  className={`rounded-xl border border-dashed px-3 py-10 text-center text-xs transition-colors ${
                    isOver
                      ? "border-brand bg-brand-soft/20 font-semibold text-brand"
                      : isValidTarget
                        ? "border-brand/40 text-brand/70"
                        : "border-line text-ink-faint"
                  }`}
                >
                  {isOver
                    ? "Drop to move here"
                    : isValidTarget
                      ? "Drop here"
                      : droppable
                        ? "Empty"
                        : "No status"}
                </p>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
