"use client";

import { useQueryClient } from "@tanstack/react-query";
import type { Paginated } from "@/core/types";
import { useRealtimeEvent, useRealtimeReconnect } from "@/core/realtime/use-realtime";
import { celebrateTask } from "@/core/lib/confetti";
import { projectKeys } from "./hooks";
import type { ProjectStatus, Task } from "./types";

/** Delete/relation payloads carry only scalar identifiers. */
interface TaskDeletedEvent {
  id: string;
  project_id: string;
}
interface StatusDeletedEvent {
  id: string;
  project_id: string;
}
interface CommentEvent {
  project_id: string;
  task_id: string;
}
interface SubtaskUpdatedEvent {
  task_id: string;
}

/**
 * Subscribe to a project's private channel and patch the react-query cache from
 * each broadcast so the board / modal update live without refetching. Mirrors
 * the optimistic patterns in `hooks.ts` (e.g. useUpdateTask.onMutate).
 *
 * Patch where the payload is user-neutral & sufficient; invalidate where it is
 * relational or carries per-user fields (my_subtasks, comments).
 *
 * CHANNEL OWNERSHIP. `project.{id}` is not as obviously shared as `user.{id}`, but it
 * is shared in practice: the workspace board mounts one <ProjectChannel> per project
 * while an open Project Detail subscribes the same id, so two subscribers to one
 * channel is a normal state, not a corner case. This hook used to call
 * `echo.leave()` on unmount, which meant whichever of the two closed first silenced
 * the other. It now registers callbacks and lets the manager leave the channel when
 * the last listener goes.
 */
export function useProjectChannel(projectId?: string): void {
  const qc = useQueryClient();
  const channel = projectId ? `project.${projectId}` : null;
  const tasksKey = projectKeys.tasks(projectId ?? "");
  const statusesKey = projectKeys.statuses(projectId ?? "");

  // --- Tasks ---
  useRealtimeEvent(channel, ".TaskCreated", () => {
    // Board rows carry per-user fields (my_subtasks) the broadcast can't know,
    // so refetch rather than append a partial row.
    qc.invalidateQueries({ queryKey: tasksKey });
  });

  useRealtimeEvent(channel, ".TaskUpdated", (task: Task) => {
      qc.setQueryData<Paginated<Task>>(tasksKey, (prev) =>
        prev
          ? {
              ...prev,
              data: prev.data.map((t) =>
                t.id === task.id ? { ...t, ...task } : t,
              ),
            }
          : prev,
      );
    if (qc.getQueryData(projectKeys.task(task.id))) {
      qc.setQueryData<Task>(projectKeys.task(task.id), (prev) =>
        prev ? { ...prev, ...task } : prev,
      );
    }
  });

  // A task was marked done by someone else — join the celebration.
  useRealtimeEvent(channel, ".TaskCompleted", (task: Task) => {
    celebrateTask(task.id);
  });

  useRealtimeEvent(channel, ".TaskDeleted", (ev: TaskDeletedEvent) => {
    qc.setQueryData<Paginated<Task>>(tasksKey, (prev) =>
      prev
        ? {
            ...prev,
            data: prev.data.filter((t) => t.id !== ev.id),
            meta: { ...prev.meta, total: Math.max(0, prev.meta.total - 1) },
          }
        : prev,
    );
    qc.removeQueries({ queryKey: projectKeys.task(ev.id) });
  });

  // --- Statuses ---
  useRealtimeEvent(channel, ".StatusCreated", (status: ProjectStatus) => {
    qc.setQueryData<ProjectStatus[]>(statusesKey, (prev) => {
      if (!prev) return prev;
      if (prev.some((s) => s.id === status.id)) return prev;
      return [...prev, status].sort((a, b) => a.order - b.order);
    });
  });

  useRealtimeEvent(channel, ".StatusUpdated", (status: ProjectStatus) => {
    qc.setQueryData<ProjectStatus[]>(statusesKey, (prev) =>
      prev
        ? prev
            .map((s) => (s.id === status.id ? { ...s, ...status } : s))
            .sort((a, b) => a.order - b.order)
        : prev,
    );
  });

  useRealtimeEvent(channel, ".StatusDeleted", (ev: StatusDeletedEvent) => {
    qc.setQueryData<ProjectStatus[]>(statusesKey, (prev) =>
      prev ? prev.filter((s) => s.id !== ev.id) : prev,
    );
    // Tasks/subtasks whose status was deleted fall back to "no status".
    qc.invalidateQueries({ queryKey: tasksKey });
    qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
  });

  // --- Comments (rendered off the full-detail task) ---
  // Two events, one behaviour — registered separately because each subscription is
  // tracked individually by the manager.
  const onComment = (ev: CommentEvent) => {
    qc.invalidateQueries({ queryKey: projectKeys.task(ev.task_id) });
    qc.invalidateQueries({ queryKey: projectKeys.comments(ev.task_id) });
  };
  useRealtimeEvent(channel, ".CommentCreated", onComment);
  useRealtimeEvent(channel, ".CommentDeleted", onComment);

  // --- Subtasks ---
  useRealtimeEvent(channel, ".SubtaskUpdated", (ev: SubtaskUpdatedEvent) => {
    qc.invalidateQueries({ queryKey: projectKeys.task(ev.task_id) });
    qc.invalidateQueries({ queryKey: projectKeys.subtasks(ev.task_id) });
    // Member "My Tasks" boards render subtask cards, so refresh the list too.
    qc.invalidateQueries({ queryKey: tasksKey });
  });

  // Reconnect backfill: a dropped WebSocket silently loses every event fired during
  // the gap (unlike notifications, this channel's events aren't otherwise re-synced).
  // After the connection re-establishes, refetch this project's board + statuses and
  // any open task's detail — comments/subtasks/links all live under the
  // ["projects","tasks"] prefix, which is disjoint from the ["projects","projects"]
  // board keys.
  //
  // The "is this a reconnect or the first connect?" test used to be hand-rolled here
  // against `pusher.connection.state`; it is the manager's now, with the same
  // semantics, so this reads as plain intent.
  useRealtimeReconnect(() => {
    if (!channel) return;
    qc.invalidateQueries({ queryKey: tasksKey });
    qc.invalidateQueries({ queryKey: statusesKey });
    qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
  });
}

/**
 * Renders nothing — subscribes to one project's channel for its lifetime. Use in
 * multi-project views (e.g. the workspace board) where the number of channels
 * varies, so React manages each subscription's mount/unmount.
 */
export function ProjectChannel({ projectId }: { projectId: string }): null {
  useProjectChannel(projectId);
  return null;
}
