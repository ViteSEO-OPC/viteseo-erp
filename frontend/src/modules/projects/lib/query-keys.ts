import { noteTargetId } from "@/modules/projects/lib/note-doc";
import type { NoteTarget } from "@/modules/projects/types/note";

/**
 * Every React Query key the module uses, in one place.
 *
 * Kept out of the hook files because invalidation crosses resources constantly:
 * approving a task refreshes the board AND the workloads, a realtime frame
 * writes straight into the task cache, and the workspace views invalidate keys
 * they never query. One shared table is what keeps those literals from drifting
 * apart.
 */
export const projectKeys = {
  users: ["users"] as const,
  // Member/group workload aggregates — invalidated whenever task/subtask
  // assignment, status, or effort changes so the indicator reflects live data.
  workloads: ["projects", "workloads"] as const,
  workspaces: ["projects", "workspaces"] as const,
  workspace: (id: string) => ["projects", "workspaces", id] as const,
  groups: (projectId?: string) =>
    ["projects", "groups", projectId ?? "all"] as const,
  group: (id: string) => ["projects", "groups", "detail", id] as const,
  projects: (workspaceId?: string) =>
    ["projects", "projects", workspaceId ?? "all"] as const,
  project: (id: string) => ["projects", "projects", "detail", id] as const,
  statuses: (projectId: string) =>
    ["projects", "projects", projectId, "statuses"] as const,
  tasks: (projectId: string) =>
    ["projects", "projects", projectId, "tasks"] as const,
  task: (id: string) => ["projects", "tasks", id] as const,
  subtasks: (taskId: string) =>
    ["projects", "tasks", taskId, "subtasks"] as const,
  activity: (taskId: string) =>
    ["projects", "tasks", taskId, "activity"] as const,
  // The cross-project Recent Activity feed. Its scope (workspace/project) is
  // part of the key, so a mutation invalidates the shared prefix below to
  // refresh every scope at once.
  activityFeedRoot: ["projects", "activity-feed"] as const,
  activityFeed: (params?: Record<string, unknown>) =>
    ["projects", "activity-feed", params ?? {}] as const,
  comments: (taskId: string) =>
    ["projects", "tasks", taskId, "comments"] as const,
  links: (taskId: string) => ["projects", "tasks", taskId, "links"] as const,
  search: (q: string, limit: number) =>
    ["projects", "search", q, limit] as const,
  // The open-task pool. Anything that can put a task in it or take one out
  // (create, claim, release, delete, a decision) invalidates the shared prefix.
  claimableRoot: ["projects", "claimable"] as const,
  claimable: (params?: Record<string, unknown>) =>
    ["projects", "claimable", params ?? {}] as const,
};

/** Archives (admin only). */
export const archiveKeys = {
  workspaces: ["admin", "archives", "workspaces"] as const,
  projects: ["admin", "archives", "projects"] as const,
  tasks: ["admin", "archives", "tasks"] as const,
  subtasks: ["admin", "archives", "subtasks"] as const,
};

/** Workload Analysis. */
export const workloadKeys = {
  analysis: ["projects", "workload", "analysis"] as const,
  tasks: ["projects", "workload", "tasks"] as const,
};

/** Approvals — the PM/Admin review queue for member-submitted work. */
export const approvalKeys = {
  tasks: ["admin", "approvals", "tasks"] as const,
  subtasks: ["admin", "approvals", "subtasks"] as const,
};

export const notificationKeys = {
  all: ["notifications"] as const,
};

/**
 * Notes.
 *
 * The user note deliberately has ONE key regardless of where it's rendered, so
 * the dashboard card and the task-view panel share a single React Query cache
 * entry — editing on either surface updates the other with no refetch.
 */
export const noteKeys = {
  all: ["projects", "notes"] as const,
  note: (target: NoteTarget) =>
    ["projects", "notes", noteTargetId(target)] as const,
};
