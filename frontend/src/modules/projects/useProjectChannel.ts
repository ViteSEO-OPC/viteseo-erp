"use client";

import { useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";
import type { Paginated } from "@/core/types";
import { getEcho } from "@/core/realtime/echo";
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
 */
export function useProjectChannel(projectId?: string): void {
  const qc = useQueryClient();
  const connectedOnce = useRef(false);

  useEffect(() => {
    if (!projectId) return;
    const echo = getEcho();
    if (!echo) return;

    const tasksKey = projectKeys.tasks(projectId);
    const statusesKey = projectKeys.statuses(projectId);
    const channel = echo.private(`project.${projectId}`);

    // --- Tasks ---
    channel.listen(".TaskCreated", () => {
      // Board rows carry per-user fields (my_subtasks) the broadcast can't know,
      // so refetch rather than append a partial row.
      qc.invalidateQueries({ queryKey: tasksKey });
    });

    channel.listen(".TaskUpdated", (task: Task) => {
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
    channel.listen(".TaskCompleted", (task: Task) => {
      celebrateTask(task.id);
    });

    channel.listen(".TaskDeleted", (ev: TaskDeletedEvent) => {
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
    channel.listen(".StatusCreated", (status: ProjectStatus) => {
      qc.setQueryData<ProjectStatus[]>(statusesKey, (prev) => {
        if (!prev) return prev;
        if (prev.some((s) => s.id === status.id)) return prev;
        return [...prev, status].sort((a, b) => a.order - b.order);
      });
    });

    channel.listen(".StatusUpdated", (status: ProjectStatus) => {
      qc.setQueryData<ProjectStatus[]>(statusesKey, (prev) =>
        prev
          ? prev
              .map((s) => (s.id === status.id ? { ...s, ...status } : s))
              .sort((a, b) => a.order - b.order)
          : prev,
      );
    });

    channel.listen(".StatusDeleted", (ev: StatusDeletedEvent) => {
      qc.setQueryData<ProjectStatus[]>(statusesKey, (prev) =>
        prev ? prev.filter((s) => s.id !== ev.id) : prev,
      );
      // Tasks/subtasks whose status was deleted fall back to "no status".
      qc.invalidateQueries({ queryKey: tasksKey });
      qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
    });

    // --- Comments (rendered off the full-detail task) ---
    const onComment = (ev: CommentEvent) => {
      qc.invalidateQueries({ queryKey: projectKeys.task(ev.task_id) });
      qc.invalidateQueries({ queryKey: projectKeys.comments(ev.task_id) });
    };
    channel.listen(".CommentCreated", onComment);
    channel.listen(".CommentDeleted", onComment);

    // --- Subtasks ---
    channel.listen(".SubtaskUpdated", (ev: SubtaskUpdatedEvent) => {
      qc.invalidateQueries({ queryKey: projectKeys.task(ev.task_id) });
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(ev.task_id) });
      // Member "My Tasks" boards render subtask cards, so refresh the list too.
      qc.invalidateQueries({ queryKey: tasksKey });
    });

    // Reconnect backfill: a dropped WebSocket silently loses every event fired
    // during the gap (unlike notifications in RealtimeProvider, this channel's
    // events aren't otherwise re-synced). After the connection re-establishes,
    // refetch this project's board + statuses and any open task's detail —
    // comments/subtasks/links all live under the ["projects","tasks"] prefix,
    // which is disjoint from the ["projects","projects"] board keys. Skip the
    // first connect (the initial REST fetches already cover current state).
    const pusher = (
      echo.connector as unknown as {
        pusher?: {
          connection?: {
            state?: string;
            bind?: (e: string, cb: () => void) => void;
            unbind?: (e: string, cb: () => void) => void;
          };
        };
      }
    ).pusher;
    // If the socket is ALREADY connected when this channel mounts (the usual
    // case — RealtimeProvider opens the connection first), the initial data is
    // loaded by the query hooks and the next "connected" event is a genuine
    // reconnect that must backfill. Only when we mount before the first connect
    // do we skip that first event (its REST fetches cover current state).
    connectedOnce.current = pusher?.connection?.state === "connected";
    const onConnected = () => {
      if (connectedOnce.current) {
        qc.invalidateQueries({ queryKey: tasksKey });
        qc.invalidateQueries({ queryKey: statusesKey });
        qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
      }
      connectedOnce.current = true;
    };
    pusher?.connection?.bind?.("connected", onConnected);

    return () => {
      pusher?.connection?.unbind?.("connected", onConnected);
      echo.leave(`project.${projectId}`);
    };
  }, [projectId, qc]);
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
