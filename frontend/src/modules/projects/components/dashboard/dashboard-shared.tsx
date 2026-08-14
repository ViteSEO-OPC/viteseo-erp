"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { User } from "@/core/types";
import { AvatarStack, Card, PriorityBadge } from "@/core/ui";
import {
  TABLE_BODY,
  TABLE_FRAME,
  TABLE_GRID_ROW,
  TABLE_HEAD_BAR,
} from "@/modules/projects/components/shared/data-table";
import { ProjectPill } from "@/modules/projects/components/shared/pills";
import { isOverdue, OverdueBadge } from "@/modules/projects/lib/overdue";
import { useProjects } from "@/modules/projects/hooks/use-projects";
import { useWorkspaceTasks } from "@/modules/projects/hooks/use-tasks";
import { useUsers } from "@/modules/projects/hooks/use-users";
import type { Project } from "@/modules/projects/types/project";
import type { Task } from "@/modules/projects/types/task";
import {
  ProjectStatusBadge,
} from "@/modules/projects/components/projects/project-status-badge";
import {
  STATUS_COLORS,
  StatusDonut,
  type StatusCounts,
} from "@/modules/projects/components/shared/status-donut";
import { StatusPill } from "@/modules/projects/components/board/list-view";
import {
  BATCH_SIZE,
  fmtShort,
} from "@/modules/projects/components/board/workspace-view-shared";
import { WorkspaceViews } from "@/modules/projects/components/board/workspace-views";

const EMPTY_COUNTS: StatusCounts = { todo: 0, in_progress: 0, done: 0 };

export function formatDate(value: string | null): string {
  if (!value) return "";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString("en-US", { month: "short", day: "2-digit" });
}

/**
 * Shared dashboard data — projects (scoped by role on the backend), the tasks
 * across them, users, and the per-project status breakdown for the donut cards.
 */
export function useDashboardData() {
  const projectsQuery = useProjects();
  const usersQuery = useUsers();

  // Done projects (and their tasks) drop out of the dashboard entirely — cards,
  // stats, and the My/All Tasks tab (which receives `projects`).
  const projects: Project[] = useMemo(
    () => (projectsQuery.data?.data ?? []).filter((p) => p.status !== "done"),
    [projectsQuery.data],
  );
  const projectIds = useMemo(() => projects.map((p) => p.id), [projects]);
  const { tasks, isLoading: tasksLoading } = useWorkspaceTasks(projectIds);
  const users = usersQuery.data ?? [];

  const countsByProject = useMemo(() => {
    const map = new Map<string, StatusCounts>();
    for (const p of projects)
      map.set(p.id, { todo: 0, in_progress: 0, done: 0 });
    for (const t of tasks) {
      const c = map.get(t.project_id);
      if (!c) continue;
      const key = t.status?.key;
      if (key === "done") c.done += 1;
      else if (key === "in_progress") c.in_progress += 1;
      else c.todo += 1;
    }
    return map;
  }, [projects, tasks]);

  return {
    projects,
    tasks,
    users,
    countsByProject,
    countsFor: (id: string) => countsByProject.get(id) ?? EMPTY_COUNTS,
    loading: projectsQuery.isLoading || tasksLoading,
    usersLoading: usersQuery.isLoading,
    activeTasks: tasks.filter((t) => t.status?.key !== "done").length,
    completedTasks: tasks.filter((t) => t.status?.key === "done").length,
  };
}

/** A project summary card with the Figma status donut. */
export function DashboardProjectCard({
  project,
  counts,
}: {
  project: Project;
  counts: StatusCounts;
}): React.ReactElement {
  const total = counts.todo + counts.in_progress + counts.done;
  const pct = (n: number) => (total === 0 ? 0 : Math.round((n / total) * 100));
  const donePct = pct(counts.done);
  const people = project.members ?? [];

  return (
    <Link href={`/sprintly/projects/${project.id}`} className="block">
      <Card className="h-full p-6 transition-shadow hover:shadow-md">
        <div className="flex items-start justify-between">
          <ProjectStatusBadge project={project} counts={counts} />
          <span className="text-xs text-ink-faint">
            {formatDate(project.created_at)}
          </span>
        </div>
        <h3 className="mt-4 line-clamp-2 text-lg font-bold tracking-heading text-ink">
          {project.name}
        </h3>
        <div className="mt-4 flex items-center gap-4">
          <StatusDonut counts={counts} size={96} stroke={13} />
          <div className="space-y-1.5 text-xs">
            <DashLegend
              color={STATUS_COLORS.done}
              label="Done"
              pct={pct(counts.done)}
            />
            <DashLegend
              color={STATUS_COLORS.in_progress}
              label="In Progress"
              pct={pct(counts.in_progress)}
            />
            <DashLegend
              color={STATUS_COLORS.todo}
              label="To Do"
              pct={pct(counts.todo)}
            />
          </div>
        </div>
        <div className="mt-4">
          <div className="h-2 w-full overflow-hidden rounded-full bg-black/10">
            <div
              className="h-full rounded-full bg-brand"
              style={{ width: `${donePct}%` }}
            />
          </div>
          <p className="mt-2 text-xs text-ink-soft">
            {donePct}% Complete
            <span className="text-ink-faint"> · {total} tasks</span>
          </p>
        </div>
        {people.length > 0 && (
          <div className="mt-4">
            <AvatarStack
              people={people
                .slice(0, 4)
                .map((p) => ({ name: p.name, src: p.avatar }))}
              size={28}
            />
          </div>
        )}
      </Card>
    </Link>
  );
}

function DashLegend({
  color,
  label,
  pct,
}: {
  color: string;
  label: string;
  pct: number;
}) {
  return (
    <div className="flex items-center gap-1.5">
      <span
        className="h-2 w-2 rounded-full"
        style={{ backgroundColor: color }}
      />
      <span className="text-ink-soft">{label}</span>
      <span className="font-bold text-ink">{pct}%</span>
    </div>
  );
}

/**
 * Tasks tab — List / Kanban / Calendar views (reuses WorkspaceViews). When
 * `userId` is given it locks to that user's assigned tasks (member "My Tasks");
 * omit it to show every task (PM/Admin "All Tasks").
 */
export function MyTasksTab({
  projects,
  users,
  userId,
}: {
  projects: Project[];
  users: User[];
  userId?: number;
}): React.ReactElement {
  return (
    <WorkspaceViews
      projects={projects}
      members={users}
      lockedAssigneeId={userId}
    />
  );
}

/**
 * A shared list-view-style task table for the dashboards (unassigned tasks,
 * upcoming deadlines, …). Rows are clickable and open the task modal via
 * `onOpen`. Tasks are lazily revealed in BATCH_SIZE chunks as the fixed-height
 * container is scrolled, so every dashboard table reads and behaves identically.
 */
/**
 * The only fields this table reads. Declared structurally rather than as `Task`
 * so anything task-shaped can use it — the open-task pool passes ClaimableTask,
 * which carries the same handful of fields under a slightly different shape.
 * `Task[]` still satisfies it, so existing callers are unaffected.
 */
export interface DashboardTaskRow {
  id: string;
  project_id: string;
  title: string;
  priority: Task["priority"];
  status?: { key: string; name: string; color: string | null } | null;
  deadline: string | null;
}

export function DashboardTaskTable<T extends DashboardTaskRow>({
  items,
  projectName,
  projectColor,
  onOpen,
  action,
  actionHeader = "",
}: {
  items: T[];
  projectName: Map<string, string>;
  projectColor: Map<string, string | null>;
  onOpen: (taskId: string) => void;
  /** Optional trailing column — e.g. the open-task pool's Claim button. */
  action?: (row: T) => React.ReactNode;
  actionHeader?: string;
}): React.ReactElement {
  // One template for header and rows, widened when an action column is present
  // so the two can never drift apart.
  const cols = action
    ? "grid-cols-[180px_1fr_100px_140px_100px_110px]"
    : "grid-cols-[180px_1fr_100px_140px_100px]";
  const [visible, setVisible] = useState(BATCH_SIZE);
  // Separate sentinels: desktop table and mobile card list are both mounted
  // (one is display:none per breakpoint), so each needs its own observed node.
  const desktopSentinelRef = useRef<HTMLDivElement>(null);
  const mobileSentinelRef = useRef<HTMLDivElement>(null);
  const total = items.length;
  const hasMore = visible < total;

  const loadMore = useCallback(() => {
    setVisible((prev) => Math.min(prev + BATCH_SIZE, total));
  }, [total]);

  useEffect(() => {
    if (!hasMore) return;
    const els = [desktopSentinelRef.current, mobileSentinelRef.current].filter(
      (el): el is HTMLDivElement => el !== null,
    );
    if (els.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) loadMore();
      },
      { threshold: 0.1 },
    );
    els.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [hasMore, loadMore]);

  const shown = items.slice(0, visible);

  return (
    <>
      {/* Desktop: single list-view table — fixed height, scrollable */}
      <div
        data-scroll-root
        className={`mt-4 hidden max-h-[400px] overflow-y-auto md:block ${TABLE_FRAME}`}
      >
        <div
          className={`sticky top-0 z-5 grid ${cols} items-center gap-4 ${TABLE_HEAD_BAR}`}
        >
          <span>Project Name</span>
          <span>Task Name</span>
          <span>Priority</span>
          <span>Status</span>
          <span>Due date</span>
          {action && <span>{actionHeader}</span>}
        </div>
        <div className={TABLE_BODY}>
          {shown.map((t) => (
            <div
              key={t.id}
              className={`relative grid ${cols} w-full items-center gap-4 ${TABLE_GRID_ROW}`}
            >
              {/* Whole-row click target. An overlay rather than wrapping the row
                  in a <button>, because the action column holds a button of its
                  own and buttons cannot nest. */}
              <button
                type="button"
                onClick={() => onOpen(t.id)}
                aria-label={`Open ${t.title}`}
                className="absolute inset-0 cursor-pointer rounded-none focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
              />
              <span className="pointer-events-none relative flex min-w-0 items-center">
                <ProjectPill
                  name={projectName.get(t.project_id) ?? "—"}
                  color={projectColor.get(t.project_id)}
                />
              </span>
              <span className="pointer-events-none relative min-w-0 truncate font-medium text-ink">
                {t.title}
              </span>
              <span className="pointer-events-none relative">
                <PriorityBadge priority={t.priority} />
              </span>
              <span className="pointer-events-none relative">
                <StatusPill
                  statusKey={t.status?.key ?? null}
                  statusName={t.status?.name ?? null}
                  statusColor={t.status?.color ?? null}
                />
              </span>
              <span className="pointer-events-none relative flex items-center gap-1.5">
                <span
                  className={`text-sm ${
                    isOverdue(t.deadline, t.status?.key)
                      ? "font-bold text-danger"
                      : "text-ink-soft"
                  }`}
                >
                  {fmtShort(t.deadline)}
                </span>
                {isOverdue(t.deadline, t.status?.key) && (
                  <span
                    className="text-danger"
                    title="Overdue"
                    aria-label="Overdue"
                  >
                    ⚠
                  </span>
                )}
              </span>
              {/* Sits above the overlay so it stays clickable. */}
              {action && (
                <span className="relative z-10 flex justify-end">
                  {action(t)}
                </span>
              )}
            </div>
          ))}
          {/* Sentinel for lazy loading trigger */}
          {hasMore && (
            <div
              ref={desktopSentinelRef}
              className="flex items-center justify-center px-5 py-3 text-sm text-ink-faint"
            >
              Loading more…
            </div>
          )}
        </div>
      </div>

      {/* Mobile: cards — fixed height, scrollable */}
      <div
        data-scroll-root
        className="mt-4 max-h-[400px] space-y-3 overflow-y-auto md:hidden"
      >
        {shown.map((t) => (
          <div
            key={t.id}
            className="relative w-full rounded-xl border border-line bg-surface p-4 text-left transition-colors hover:bg-black/[0.02]"
          >
            <button
              type="button"
              onClick={() => onOpen(t.id)}
              aria-label={`Open ${t.title}`}
              className="absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
            />
            <div className="pointer-events-none relative flex items-start justify-between gap-3">
              <ProjectPill
                name={projectName.get(t.project_id) ?? "—"}
                color={projectColor.get(t.project_id)}
              />
              <span className="shrink-0">
                <PriorityBadge priority={t.priority} />
              </span>
            </div>
            <p className="pointer-events-none relative mt-2 font-medium text-ink">
              {t.title}
            </p>
            <div className="pointer-events-none relative mt-3 flex flex-wrap items-center gap-3">
              <StatusPill
                statusKey={t.status?.key ?? null}
                statusName={t.status?.name ?? null}
                statusColor={t.status?.color ?? null}
              />
              <span
                className={`text-xs ${
                  isOverdue(t.deadline, t.status?.key)
                    ? "font-bold text-danger"
                    : "text-ink-faint"
                }`}
              >
                {fmtShort(t.deadline)}
              </span>
              {isOverdue(t.deadline, t.status?.key) && <OverdueBadge />}
            </div>
            {action && (
              <div className="relative z-10 mt-3 flex justify-end">
                {action(t)}
              </div>
            )}
          </div>
        ))}
        {hasMore && (
          <div
            ref={mobileSentinelRef}
            className="flex items-center justify-center py-3 text-sm text-ink-faint"
          >
            Loading more…
          </div>
        )}
      </div>
    </>
  );
}

export type { Task };
