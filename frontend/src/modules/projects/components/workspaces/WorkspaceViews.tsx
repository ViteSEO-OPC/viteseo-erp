"use client";

import { Suspense, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth, useIsManager } from "@/core/auth/AuthProvider";
import { usePersistentState } from "@/core/use-persistent-state";
import { celebrateTask } from "@/core/lib/confetti";
import type { User } from "@/core/types";
import {
  Avatar,
  Button,
  Card,
  ChevronDownIcon,
  Field,
  FilterIcon,
  PlusIcon,
  SegmentedControl,
  SegmentedItem,
  Select,
} from "@/core/ui";
import { CountBubble } from "../shared/Pills";
import { projectsApi } from "../../api";
import type { NoteTarget } from "../../notes/types";
import {
  NotesTaskPanel,
  NotesToggleButton,
} from "../notes/NotesTaskPanel";
import {
  projectKeys,
  useStatuses,
  useWorkspaceStatuses,
  useWorkspaceTasks,
} from "../../hooks";
import type { Project } from "../../types";
import { ProjectChannel } from "../../useProjectChannel";
import { StatusManagerModal } from "../status/StatusManagerModal";
import { TaskModal } from "../tasks/TaskModal";
import { CalendarView } from "./views/CalendarView";
import { KanbanView } from "./views/KanbanView";
import { ListView } from "./views/ListView";
import {
  colIdOf,
  NO_STATUS,
  UNASSIGNED_ID,
  VIEWS,
  type StatusColumn,
  type ViewKey,
  type WorkItem,
} from "./views/shared";

export interface WorkspaceViewsProps {
  projects: Project[];
  members: User[];
  lockedAssigneeId?: number;
}

/** Deadline ordering applied to the board; null keeps the source order. */
type DeadlineSort = "asc" | "desc" | null;

/**
 * The board's notes panel shows the user-scoped note — the same document as the
 * dashboard card, sharing one React Query entry, so an edit on either surface
 * is immediately reflected on the other.
 */
const USER_NOTE: NoteTarget = { scope: "user" };

function WorkspaceViewsInner({
  projects: allProjects,
  members,
  lockedAssigneeId,
}: WorkspaceViewsProps): React.ReactElement {
  // Done projects (and their tasks) drop out of every board view.
  const projects = allProjects.filter((p) => p.status !== "done");
  const isManager = useIsManager();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { user } = useAuth();
  // Remember each user's last-picked board view (List / Kanban / Calendar).
  const [storedView, setView] = usePersistentState<ViewKey>(
    user ? `pm:board-view:${user.id}` : null,
    "list",
  );
  // Guard against a stale/unknown persisted key so a bad value can't blank the board.
  const view: ViewKey = VIEWS.some((v) => v.key === storedView)
    ? storedView
    : "list";
  const [projectIds, setProjectIds] = useState<string[]>([]);
  const [memberIds, setMemberIds] = useState<number[]>([]);
  const [deadlineSort, setDeadlineSort] = useState<DeadlineSort>(null);
  const [statusModalOpen, setStatusModalOpen] = useState(false);
  // Closed by default; the choice is remembered per user (§7.1).
  const [notesOpen, setNotesOpen] = usePersistentState<boolean>(
    user ? `pmnote:ui:${user.id}:taskpanel` : null,
    false,
  );

  const allProjectIds = projects.map((p) => p.id);
  const activeProjectIds = projectIds.length ? projectIds : allProjectIds;
  const projectName = new Map(projects.map((p) => [p.id, p.name]));
  const projectColor = new Map(
    projects.map((p) => [p.id, p.color ?? "#6366f1"]),
  );

  const qc = useQueryClient();
  const statusesByProject = useWorkspaceStatuses(activeProjectIds);

  const { tasks, isLoading: tasksLoading } =
    useWorkspaceTasks(activeProjectIds);
  const isLoading = tasksLoading;

  const [dragOverride, setDragOverride] = useState<
    Record<string, { id: string; key: string; name: string; color: string | null }>
  >({});
  const ov = (
    key: string,
    statusKey: string | null,
    statusName: string | null,
    statusColor: string | null,
  ) => {
    const o = dragOverride[key];
    return o
      ? { statusKey: o.key, statusName: o.name, statusColor: o.color }
      : { statusKey, statusName, statusColor };
  };

  const items: WorkItem[] = tasks.flatMap<WorkItem>((t) => {
    const mySubs = t.my_subtasks ?? [];
    const leadsMyGroup = t.leads_my_group ?? false;
    const cards: WorkItem[] = [];

    // On the member "My Tasks" board, if the member has subtask(s) under this
    // task, surface those subtasks as their own cards (with the parent task as
    // context) — and dragging them moves the subtask, which the member is
    // allowed to do.
    if (lockedAssigneeId != null && mySubs.length > 0) {
      cards.push(
        ...mySubs.map<WorkItem>((s) => {
          const o = ov(
            `subtask-${s.id}`,
            s.status?.key ?? null,
            s.status?.name ?? null,
            s.status?.color ?? null,
          );
          return {
            kind: "subtask",
            key: `subtask-${s.id}`,
            id: t.id,
            subtaskId: s.id,
            parentTitle: t.title,
            projectId: t.project_id,
            projectColor: projectColor.get(t.project_id) ?? "#6366f1",
            title: s.name,
            priority: s.priority,
            statusName: o.statusName,
            statusKey: o.statusKey,
            statusColor: o.statusColor,
            assignee: null,
            // It's the member's own subtask → keep it in their filtered board.
            assigneeId: lockedAssigneeId,
            groupName: null,
            groupColor: null,
            deadline: s.deadline,
            hasMySubtask: true,
            leadsMyGroup: false,
          };
        }),
      );

      // A plain member's stake in the parent is only through those subtasks, so
      // the parent card is replaced by them. The group's leader owns the parent
      // task itself, so it stays on their board alongside their own subtasks.
      if (!leadsMyGroup) return cards;
    }

    const o = ov(
      `task-${t.id}`,
      t.status?.key ?? null,
      t.status?.name ?? null,
      t.status?.color ?? null,
    );
    cards.push({
      kind: "task",
      key: `task-${t.id}`,
      id: t.id,
      projectId: t.project_id,
      projectColor: projectColor.get(t.project_id) ?? "#6366f1",
      title: t.title,
      priority: t.priority,
      statusName: o.statusName,
      statusKey: o.statusKey,
      statusColor: o.statusColor,
      assignee: t.assignee,
      assigneeId: t.assignee_id,
      groupName: t.group?.name ?? null,
      groupColor: t.group?.color ?? null,
      deadline: t.deadline,
      hasMySubtask: t.has_my_subtask ?? false,
      leadsMyGroup,
    });

    return cards;
  });

  // Custom-status columns known across the in-scope projects, so an empty custom
  // status still shows a column (merged by lowercased name across projects).
  const customColumns: StatusColumn[] = [];
  {
    const seen = new Set<string>();
    for (const pid of activeProjectIds) {
      for (const s of statusesByProject.get(pid) ?? []) {
        if (s.key !== "custom") continue;
        const colId = colIdOf(s.key, s.name);
        if (seen.has(colId)) continue;
        seen.add(colId);
        customColumns.push({
          colId,
          label: s.name,
          color: s.color ?? "#9ca3af",
        });
      }
    }
  }

  const filtered = items.filter((i) => {
    if (
      lockedAssigneeId != null &&
      i.assigneeId !== lockedAssigneeId &&
      !i.hasMySubtask &&
      !i.leadsMyGroup
    )
      return false;
    if (memberIds.length) {
      const wantsUnassigned = memberIds.includes(UNASSIGNED_ID);
      const matchesMember =
        i.assigneeId != null && memberIds.includes(i.assigneeId);
      const matchesUnassigned = wantsUnassigned && i.assigneeId == null;
      if (!matchesMember && !matchesUnassigned) return false;
    }
    return true;
  });

  // Optional deadline ordering (applied within each List section / Kanban
  // column). Tasks without a deadline always sink to the end.
  const sorted =
    deadlineSort == null
      ? filtered
      : [...filtered].sort((a, b) => {
          const ta = a.deadline ? new Date(a.deadline).getTime() : null;
          const tb = b.deadline ? new Date(b.deadline).getTime() : null;
          if (ta == null && tb == null) return 0;
          if (ta == null) return 1;
          if (tb == null) return -1;
          return deadlineSort === "asc" ? ta - tb : tb - ta;
        });

  // Drag-and-drop (Kanban)
  const [draggingKey, setDraggingKey] = useState<string | null>(null);
  const [overCol, setOverCol] = useState<string | null>(null);
  const draggingItemRef = useRef<WorkItem | null>(null);

  // A card can only be dropped in a column whose status exists in the card's
  // own project. Custom-status columns are merged by name across projects, so a
  // task whose project lacks that status must not accept the drop (it would
  // otherwise silently no-op in moveItem). Default columns exist in every project.
  const canDropItem = (item: WorkItem, colId: string): boolean => {
    if (colId === NO_STATUS) return false;
    return (statusesByProject.get(item.projectId) ?? []).some(
      (st) => colIdOf(st.key, st.name) === colId,
    );
  };

  const moveItem = async (item: WorkItem, targetColId: string) => {
    // Dropping into "No Status" is not a manual action — statuses are cleared
    // only when a custom status is deleted.
    if (targetColId === NO_STATUS) return;
    if (colIdOf(item.statusKey, item.statusName) === targetColId) return;
    // Match the target column back to one of THIS task's project statuses.
    const target = (statusesByProject.get(item.projectId) ?? []).find(
      (st) => colIdOf(st.key, st.name) === targetColId,
    );
    if (!target) return;
    setDragOverride((o) => ({
      ...o,
      [item.key]: {
        id: target.id,
        key: target.key,
        name: target.name,
        color: target.color,
      },
    }));
    try {
      if (item.kind === "subtask" && item.subtaskId) {
        // Members may change the status of a subtask assigned to them.
        await projectsApi.updateSubtask(item.subtaskId, {
          status_id: target.id,
        });
      } else {
        await projectsApi.updateTask(item.id, { status_id: target.id });
        // Dragging a task into the Done column is a completion — celebrate.
        if (target.key === "done") celebrateTask(item.id);
      }
      await qc.invalidateQueries({
        queryKey: projectKeys.tasks(item.projectId),
      });
      // Refresh the parent task detail too (its subtask list / status changed).
      await qc.invalidateQueries({ queryKey: projectKeys.task(item.id) });
    } finally {
      setDragOverride((o) => {
        const n = { ...o };
        delete n[item.key];
        return n;
      });
    }
  };

  const handleOpen = (taskId: string) => {
    const params = new URLSearchParams(searchParams.toString());
    params.set("task", taskId);
    router.push(`${pathname}?${params.toString()}`, { scroll: false });
  };

  return (
    <div className="mt-6">
      {/* Live updates for every project currently in view. */}
      {activeProjectIds.map((pid) => (
        <ProjectChannel key={pid} projectId={pid} />
      ))}

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-3">
        {/* View switcher: List / Kanban / Calendar — full-width equal tabs on
            mobile, content-width from sm up. */}
        <SegmentedControl className="w-full sm:w-auto">
          {VIEWS.map((v) => (
            <SegmentedItem
              key={v.key}
              active={view === v.key}
              onClick={() => setView(v.key)}
              className="flex-1 sm:flex-none"
            >
              {v.label}
            </SegmentedItem>
          ))}
        </SegmentedControl>

        {/* Filters + actions — flow full-width below the switcher on mobile,
            align right on a shared row from sm up. */}
        <div className="flex w-full flex-wrap items-center gap-3 sm:ml-auto sm:w-auto">
          <FilterPopover
            projects={projects}
            members={members}
            projectIds={projectIds}
            setProjectIds={setProjectIds}
            memberIds={memberIds}
            setMemberIds={setMemberIds}
            deadlineSort={deadlineSort}
            setDeadlineSort={setDeadlineSort}
          />

          {/* Notes sits immediately right of Filter, same height and shape, so
              the row reads as one control group. */}
          <NotesToggleButton
            target={USER_NOTE}
            open={notesOpen}
            onToggle={() => setNotesOpen(!notesOpen)}
          />

          {/* Creating a task lives in the page header (dashboard / workspace),
              so it stays reachable from every tab — not just this board. */}
          {isManager && lockedAssigneeId == null && projects.length > 0 && (
            <Button
              variant="brand-outline"
              onClick={() => setStatusModalOpen(true)}
              className="flex-1 sm:flex-none"
            >
              <PlusIcon className="h-4 w-4" />
              Add status
            </Button>
          )}
        </div>
      </div>

      {/* Body — the notes panel is a sibling so that on wide screens it pushes
          the board aside instead of covering it (it overlays below xl). */}
      <div className="mt-5 flex items-start gap-5">
        <div className="min-w-0 flex-1">
        {isLoading ? (
          <p className="text-ink-soft">Loading tasks…</p>
        ) : sorted.length === 0 ? (
          <Card className="p-10 text-center">
            <p className="text-lg font-bold text-ink">
              No tasks match these filters
            </p>
            <p className="mt-1 text-ink-soft">
              Adjust the project or member filters, or create tasks in a
              project.
            </p>
          </Card>
        ) : view === "list" ? (
          <ListView
            items={sorted}
            customColumns={customColumns}
            projectName={projectName}
            projectColor={projectColor}
            onOpen={handleOpen}
          />
        ) : view === "kanban" ? (
          <KanbanView
            items={sorted}
            customColumns={customColumns}
            projectName={projectName}
            onOpen={handleOpen}
            draggingKey={draggingKey}
            overCol={overCol}
            onDragStart={(item) => {
              draggingItemRef.current = item;
              setDraggingKey(item.key);
            }}
            onDragEnd={() => {
              setDraggingKey(null);
              setOverCol(null);
            }}
            onDragOverCol={(colKey) => setOverCol(colKey)}
            onDropCol={(colKey) => {
              const item = draggingItemRef.current;
              draggingItemRef.current = null;
              setDraggingKey(null);
              setOverCol(null);
              if (item) moveItem(item, colKey);
            }}
            canDropItem={canDropItem}
          />
        ) : (
          <CalendarView items={sorted} onOpen={handleOpen} />
        )}
        </div>

        <NotesTaskPanel
          target={USER_NOTE}
          open={notesOpen}
          onClose={() => setNotesOpen(false)}
        />
      </div>

      {/* The task-view modal is rendered globally (projects layout) off `?task=`. */}

      <StatusManagerModal
        open={statusModalOpen}
        onClose={() => setStatusModalOpen(false)}
        projects={
          projectIds.length
            ? projects.filter((p) => projectIds.includes(p.id))
            : projects
        }
        defaultProjectId={activeProjectIds.length === 1 ? activeProjectIds[0] : undefined}
      />
    </div>
  );
}

export function WorkspaceViews(props: WorkspaceViewsProps): React.ReactElement {
  return (
    <Suspense fallback={<p className="mt-6 text-ink-soft">Loading tasks…</p>}>
      <WorkspaceViewsInner {...props} />
    </Suspense>
  );
}

/**
 * A single popover combining every board filter — project, member/unassigned,
 * and deadline ordering — anchored directly below its trigger button. Empty
 * project/member selections mean "all"; the badge counts active constraints.
 */
function FilterPopover({
  projects,
  members,
  projectIds,
  setProjectIds,
  memberIds,
  setMemberIds,
  deadlineSort,
  setDeadlineSort,
}: {
  projects: Project[];
  members: User[];
  projectIds: string[];
  setProjectIds: (ids: string[]) => void;
  memberIds: number[];
  setMemberIds: (ids: number[]) => void;
  deadlineSort: DeadlineSort;
  setDeadlineSort: (s: DeadlineSort) => void;
}): React.ReactElement {
  const [open, setOpen] = useState(false);

  const activeCount =
    projectIds.length + memberIds.length + (deadlineSort ? 1 : 0);

  const toggleProject = (id: string): void =>
    setProjectIds(
      projectIds.includes(id)
        ? projectIds.filter((x) => x !== id)
        : [...projectIds, id],
    );
  const toggleMember = (id: number): void =>
    setMemberIds(
      memberIds.includes(id)
        ? memberIds.filter((x) => x !== id)
        : [...memberIds, id],
    );
  const clearAll = (): void => {
    setProjectIds([]);
    setMemberIds([]);
    setDeadlineSort(null);
  };

  const sortBtn = (active: boolean): string =>
    `flex-1 rounded-lg border px-2.5 py-1.5 text-xs font-bold transition-colors ${
      active
        ? "border-brand bg-brand/10 text-ink"
        : "border-line text-ink-soft hover:text-ink"
    }`;

  const row =
    "flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-ink hover:bg-black/5";

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className={`flex h-10 items-center gap-2 rounded-lg border bg-surface px-3 text-sm font-bold transition-colors ${
          activeCount > 0
            ? "border-brand text-ink"
            : "border-line text-ink-soft hover:text-ink"
        }`}
      >
        <FilterIcon className="h-4 w-4" />
        Filters
        {activeCount > 0 && (
          <CountBubble>{activeCount}</CountBubble>
        )}
        <ChevronDownIcon className="h-4 w-4" />
      </button>

      {open && (
        <>
          <div
            className="fixed inset-0 z-10"
            onClick={() => setOpen(false)}
            aria-hidden
          />
          {/* Anchored below the button: opens rightward on mobile, leftward
              (right-aligned) from sm up where the toolbar sits on the right. */}
          <div className="absolute left-0 top-full z-20 mt-2 w-72 rounded-xl border border-line bg-surface p-3 shadow-lg sm:left-auto sm:right-0">
            <div className="flex items-center justify-between px-1 pb-2">
              <span className="text-xs font-bold uppercase tracking-wide text-ink-faint">
                Filters
              </span>
              {activeCount > 0 && (
                <button
                  type="button"
                  onClick={clearAll}
                  className="text-xs font-bold text-brand hover:underline"
                >
                  Clear all
                </button>
              )}
            </div>

            {/* Deadline sort */}
            <div className="px-1 pb-3">
              <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wide text-ink-faint">
                Sort by deadline
              </p>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() =>
                    setDeadlineSort(deadlineSort === "asc" ? null : "asc")
                  }
                  className={sortBtn(deadlineSort === "asc")}
                >
                  ↑ Earliest first
                </button>
                <button
                  type="button"
                  onClick={() =>
                    setDeadlineSort(deadlineSort === "desc" ? null : "desc")
                  }
                  className={sortBtn(deadlineSort === "desc")}
                >
                  ↓ Latest first
                </button>
              </div>
            </div>

            {/* Project */}
            <div className="border-t border-line px-1 pt-2">
              <p className="mb-1 text-[10px] font-bold uppercase tracking-wide text-ink-faint">
                Project
              </p>
              <div className="max-h-40 space-y-0.5 overflow-y-auto">
                {projects.length === 0 ? (
                  <p className="px-1 py-1 text-xs text-ink-faint">No projects</p>
                ) : (
                  projects.map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      onClick={() => toggleProject(p.id)}
                      className={row}
                    >
                      <span
                        className="h-2.5 w-2.5 shrink-0 rounded-full"
                        style={{ backgroundColor: p.color ?? "#6366f1" }}
                      />
                      <span className="min-w-0 flex-1 truncate">{p.name}</span>
                      {projectIds.includes(p.id) && (
                        <span className="text-xs font-bold text-brand">✓</span>
                      )}
                    </button>
                  ))
                )}
              </div>
            </div>

            {/* Member / Unassigned */}
            <div className="mt-2 border-t border-line px-1 pt-2">
              <p className="mb-1 text-[10px] font-bold uppercase tracking-wide text-ink-faint">
                Member
              </p>
              <div className="max-h-40 space-y-0.5 overflow-y-auto">
                <button
                  type="button"
                  onClick={() => toggleMember(UNASSIGNED_ID)}
                  className={row}
                >
                  <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-black/5 text-xs text-ink-faint">
                    —
                  </span>
                  <span className="min-w-0 flex-1 truncate">Unassigned</span>
                  {memberIds.includes(UNASSIGNED_ID) && (
                    <span className="text-xs font-bold text-brand">✓</span>
                  )}
                </button>
                {members.map((m) => (
                  <button
                    key={m.id}
                    type="button"
                    onClick={() => toggleMember(m.id)}
                    className={row}
                  >
                    <Avatar name={m.name} src={m.avatar} size={24} />
                    <span className="min-w-0 flex-1 truncate">{m.name}</span>
                    {memberIds.includes(m.id) && (
                      <span className="text-xs font-bold text-brand">✓</span>
                    )}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

/**
 * "Add Task" from a multi-project surface: pick the project first (when more
 * than one is in scope), then fill in the task itself. Rendered by the page
 * headers that own the button — the dashboard and the workspace detail view.
 */
export function WorkspaceCreateTaskModal({
  projects,
  onClose,
}: {
  projects: Project[];
  onClose: () => void;
}): React.ReactElement {
  const [projectId, setProjectId] = useState(projects[0]?.id ?? "");
  const statusesQuery = useStatuses(projectId);
  const statuses = statusesQuery.data ?? [];

  const selector =
    projects.length > 1 ? (
      <Field label="Project">
        <Select
          value={projectId}
          onChange={(e) => setProjectId(e.target.value)}
        >
          {projects.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
      </Field>
    ) : null;

  return (
    <TaskModal
      open={true}
      onClose={onClose}
      projectId={projectId}
      statuses={statuses}
      mode="create"
      projectSelector={selector}
    />
  );
}
