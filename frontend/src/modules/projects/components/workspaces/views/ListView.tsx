"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Avatar, PriorityBadge } from "@/core/ui";
import { isOverdue, OverdueBadge } from "../../../overdue";
import {
  BATCH_SIZE,
  buildColumns,
  fmtShort,
  STATUS_LABELS,
  type StatusColumn,
  type WorkItem,
} from "./shared";

export function StatusPill({
  statusKey,
  statusName,
  statusColor,
}: {
  statusKey: string | null;
  statusName: string | null;
  statusColor: string | null;
}) {
  const base =
    "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold";

  // No status (custom status was deleted).
  if (!statusKey) {
    return (
      <span className={`${base} bg-black/5 text-ink-faint`}>No status</span>
    );
  }

  // Custom status → tint from its own color.
  if (statusKey === "custom" && statusColor) {
    return (
      <span
        className={base}
        style={{ backgroundColor: `${statusColor}1f`, color: statusColor }}
      >
        {statusName ?? "Custom"}
      </span>
    );
  }

  const colorMap: Record<string, string> = {
    todo: "bg-ink-soft/10 text-ink-soft",
    in_progress: "bg-indigo-50 text-[#6366f1]",
    done: "bg-emerald-50 text-[#10b981]",
  };
  return (
    <span className={`${base} ${colorMap[statusKey] ?? "bg-black/5 text-ink-soft"}`}>
      {statusName ?? STATUS_LABELS[statusKey] ?? statusKey}
    </span>
  );
}

function StatusSection({
  group,
  projectName,
  projectColor,
  onOpen,
}: {
  group: { label: string; color: string; items: WorkItem[] };
  projectName: Map<string, string>;
  projectColor: Map<string, string>;
  onOpen: (taskId: string) => void;
}) {
  const [visible, setVisible] = useState(BATCH_SIZE);
  const sentinelRef = useRef<HTMLDivElement>(null);
  const total = group.items.length;
  const hasMore = visible < total;

  const loadMore = useCallback(() => {
    setVisible((prev) => Math.min(prev + BATCH_SIZE, total));
  }, [total]);

  useEffect(() => {
    if (!hasMore) return;
    const el = sentinelRef.current;
    if (!el) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) loadMore();
      },
      { root: el.closest("[data-scroll-root]"), threshold: 0.1 },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [hasMore, loadMore]);

  const shown = group.items.slice(0, visible);

  return (
    <div>
      <div
        className="flex items-center gap-2 py-2 mb-2 rounded-md"
        style={{ background: `${group.color}15` }}
      >
        <span
          className="pl-3 text-sm font-bold"
          style={{ color: group.color }}
        >
          {group.label}
        </span>
      </div>

      {/* Desktop table - fixed height, scrolls vertically and (on narrow
          viewports) horizontally so Task Name never collapses. */}
      <div
        data-scroll-root
        className="hidden max-h-[400px] overflow-auto rounded-xl border border-line md:block"
      >
        <div className="sticky top-0 z-5 grid min-w-[720px] grid-cols-[160px_minmax(200px,1fr)_84px_160px_92px] items-center gap-4 border-b border-line bg-[#f9fafb] px-5 py-3 text-xs font-bold uppercase tracking-wide text-ink-soft">
          <span>Project Name</span>
          <span>Task Name</span>
          <span>Priority</span>
          <span>Assignee</span>
          <span>Due date</span>
        </div>
        <div className="divide-y divide-line">
          {shown.map((i) => (
            <button
              key={i.key}
              type="button"
              onClick={() => onOpen(i.id)}
              className="grid w-full min-w-[720px] grid-cols-[160px_minmax(200px,1fr)_84px_160px_92px] items-center gap-4 px-5 py-3.5 text-left transition-colors hover:bg-black/[0.02]"
            >
              <span className="flex items-center">
                <span
                  className="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold"
                  style={{
                    backgroundColor: `${projectColor.get(i.projectId) ?? "#6366f1"}15`,
                    color: projectColor.get(i.projectId) ?? "#6366f1",
                  }}
                >
                  {projectName.get(i.projectId) ?? "—"}
                </span>
              </span>
              <span className="min-w-0 truncate font-medium text-ink">
                {i.title}
                {i.kind === "subtask" && i.parentTitle && (
                  <span className="block truncate text-xs font-normal text-ink-faint">
                    ↳ subtask in {i.parentTitle}
                  </span>
                )}
              </span>
              <span>
                <PriorityBadge priority={i.priority} />
              </span>
              <span className="flex items-center gap-2">
                {i.assignee ? (
                  <>
                    <Avatar
                      name={i.assignee.name}
                      src={i.assignee.avatar}
                      size={28}
                    />
                    <span className="truncate text-sm text-ink">
                      {i.assignee.name}
                    </span>
                  </>
                ) : i.groupName ? (
                  <>
                    {/* Group tasks read by their group's color. */}
                    <span
                      className="h-2.5 w-2.5 shrink-0 rounded-full"
                      style={{ backgroundColor: i.groupColor ?? "#6366f1" }}
                      aria-hidden
                    />
                    <span className="truncate text-sm text-ink-soft">
                      {i.groupName}
                    </span>
                  </>
                ) : i.kind === "subtask" ? (
                  <span className="text-sm text-ink-soft">My subtask</span>
                ) : (
                  <span className="text-sm text-ink-faint">—</span>
                )}
              </span>
              <span className="flex items-center gap-1.5">
                <span
                  className={`text-sm ${
                    isOverdue(i.deadline, i.statusKey)
                      ? "font-bold text-danger"
                      : "text-ink-soft"
                  }`}
                >
                  {fmtShort(i.deadline)}
                </span>
                {isOverdue(i.deadline, i.statusKey) && (
                  <span
                    className="text-danger"
                    title="Overdue"
                    aria-label="Overdue"
                  >
                    ⚠
                  </span>
                )}
              </span>
            </button>
          ))}
          {group.items.length === 0 && (
            <div className="px-5 py-6 text-center text-sm text-ink-faint">
              No tasks
            </div>
          )}
          {/* Sentinel for lazy loading trigger */}
          {hasMore && (
            <div
              ref={sentinelRef}
              className="flex items-center justify-center px-5 py-3 text-sm text-ink-faint"
            >
              Loading more…
            </div>
          )}
        </div>
      </div>

      {/* Mobile cards */}
      <div className="max-h-[400px] space-y-3 overflow-y-auto md:hidden">
        {group.items.map((i) => (
          <button
            key={i.key}
            type="button"
            onClick={() => onOpen(i.id)}
            className="w-full rounded-xl border border-line bg-surface p-4 text-left transition-colors hover:bg-black/[0.02]"
          >
            <div className="flex items-start justify-between gap-3">
              <span
                className="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold"
                style={{
                  backgroundColor: `${projectColor.get(i.projectId) ?? "#6366f1"}15`,
                  color: projectColor.get(i.projectId) ?? "#6366f1",
                }}
              >
                {projectName.get(i.projectId) ?? "—"}
              </span>
              <PriorityBadge priority={i.priority} />
            </div>
            <p className="mt-2 font-medium text-ink">{i.title}</p>
            {i.kind === "subtask" && i.parentTitle && (
              <p className="text-xs text-ink-faint">↳ subtask in {i.parentTitle}</p>
            )}
            <div className="mt-3 flex flex-wrap items-center gap-3">
              {i.assignee ? (
                <span className="flex items-center gap-2">
                  <Avatar
                    name={i.assignee.name}
                    src={i.assignee.avatar}
                    size={24}
                  />
                  <span className="text-sm text-ink">
                    {i.assignee.name}
                  </span>
                </span>
              ) : i.groupName ? (
                <span className="flex min-w-0 items-center gap-2">
                  {/* Group tasks read by their group's color. */}
                  <span
                    className="h-2.5 w-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: i.groupColor ?? "#6366f1" }}
                    aria-hidden
                  />
                  <span className="truncate text-sm text-ink-soft">
                    {i.groupName}
                  </span>
                </span>
              ) : null}
              <span
                className={`text-xs ${
                  isOverdue(i.deadline, i.statusKey)
                    ? "font-bold text-danger"
                    : "text-ink-faint"
                }`}
              >
                {fmtShort(i.deadline)}
              </span>
              {isOverdue(i.deadline, i.statusKey) && <OverdueBadge />}
            </div>
          </button>
        ))}
      </div>
    </div>
  );
}

export function ListView({
  items,
  customColumns,
  projectName,
  projectColor,
  onOpen,
}: {
  items: WorkItem[];
  customColumns: StatusColumn[];
  projectName: Map<string, string>;
  projectColor: Map<string, string>;
  onOpen: (taskId: string) => void;
}): React.ReactElement {
  const { columns, buckets } = buildColumns(items, customColumns);
  // Show every known status section — the 3 defaults + each custom status —
  // even when empty, so a newly-added custom status is visible without any task.
  // (buildColumns only adds "No Status" when it actually has items.)

  return (
    <div className="space-y-4">
      {columns.map((c) => (
        <StatusSection
          key={`${c.colId}-${buckets.get(c.colId)!.length}`}
          group={{ label: c.label, color: c.color, items: buckets.get(c.colId)! }}
          projectName={projectName}
          projectColor={projectColor}
          onOpen={onOpen}
        />
      ))}
    </div>
  );
}
