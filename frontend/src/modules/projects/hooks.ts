import {
  keepPreviousData,
  useMutation,
  useQueries,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import type { Paginated } from "@/core/types";
import { celebrateTask } from "@/core/lib/confetti";
import { projectsApi } from "./api";
import { SEARCH_MIN_LENGTH } from "./types";
import type { CreateTaskPayload, ProjectStatus, Subtask, Task } from "./types";

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
  comments: (taskId: string) =>
    ["projects", "tasks", taskId, "comments"] as const,
  links: (taskId: string) => ["projects", "tasks", taskId, "links"] as const,
  search: (q: string, limit: number) =>
    ["projects", "search", q, limit] as const,
};

// Users
export function useUsers(search?: string) {
  return useQuery({
    queryKey: [...projectKeys.users, search ?? ""],
    queryFn: () => projectsApi.listUsers(search),
  });
}

/**
 * Live workload for a member, fetched on demand only when a valid assignee is
 * selected in the task modal. Advisory data — it never blocks assignment.
 */
export function useUserWorkload(userId: number | null | undefined) {
  return useQuery({
    queryKey: [...projectKeys.workloads, "user", userId ?? 0],
    queryFn: () => projectsApi.getUserWorkload(userId as number),
    enabled: userId != null && userId > 0,
    // Always refetch on open so PMs see current workload after recent changes.
    staleTime: 0,
  });
}

/**
 * Live workload for a group — same aggregation scoped to the group. Used on the
 * Groups page and for the task modal's group-assignment indicator.
 */
export function useGroupWorkload(groupId: string | null | undefined) {
  return useQuery({
    queryKey: [...projectKeys.workloads, "group", groupId ?? ""],
    queryFn: () => projectsApi.getGroupWorkload(groupId as string),
    enabled: !!groupId,
    staleTime: 0,
  });
}

/**
 * Global search across projects and tasks for the topbar menu.
 *
 * Pass an ALREADY-DEBOUNCED term — the query key is the term, so every distinct
 * value is one request. Terms shorter than SEARCH_MIN_LENGTH are never sent
 * (the API 422s on those), and the previous term's results stay on screen while
 * the next one loads so the menu doesn't flash empty between keystrokes.
 */
export function useProjectSearch(term: string, limit = 5) {
  const q = term.trim();
  const enabled = q.length >= SEARCH_MIN_LENGTH;

  return useQuery({
    queryKey: projectKeys.search(q, limit),
    queryFn: ({ signal }) => projectsApi.search(q, limit, signal),
    enabled,
    placeholderData: keepPreviousData,
    // Results for a given term rarely change within a single search session,
    // so re-typing one (or backspacing into it) is served from cache.
    staleTime: 30_000,
    // Nothing here is worth a retry storm on a keystroke-driven endpoint.
    retry: false,
  });
}

// Workspaces
export function useWorkspaces(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...projectKeys.workspaces, params ?? {}],
    queryFn: () => projectsApi.listWorkspaces(params),
  });
}
export function useWorkspace(id: string) {
  return useQuery({
    queryKey: projectKeys.workspace(id),
    queryFn: () => projectsApi.getWorkspace(id),
    enabled: !!id,
  });
}
export function useCreateWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: projectsApi.createWorkspace,
    onSuccess: () => qc.invalidateQueries({ queryKey: projectKeys.workspaces }),
  });
}
export function useUpdateWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; description?: string | null };
    }) => projectsApi.updateWorkspace(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
      qc.invalidateQueries({ queryKey: projectKeys.workspace(id) });
    },
  });
}
export function useDeleteWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.deleteWorkspace(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
      // Projects roll up under workspaces; refresh their lists too.
      qc.invalidateQueries({ queryKey: ["projects", "projects"] });
    },
  });
}
// Groups
export function useGroups(projectId?: string) {
  return useQuery({
    queryKey: projectKeys.groups(projectId),
    queryFn: () => projectsApi.listGroups(projectId),
  });
}
export function useGroup(id: string) {
  return useQuery({
    queryKey: projectKeys.group(id),
    queryFn: () => projectsApi.getGroup(id),
    enabled: !!id,
  });
}
export function useCreateGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: projectsApi.createGroup,
    onSuccess: () => qc.invalidateQueries({ queryKey: ["projects", "groups"] }),
  });
}
export function useUpdateGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; color?: string; leader_id?: number };
    }) => projectsApi.updateGroup(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ["projects", "groups"] });
      qc.invalidateQueries({ queryKey: projectKeys.group(id) });
    },
  });
}
export function useDeleteGroup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.deleteGroup(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["projects", "groups"] }),
  });
}
export function useGroupMembers(groupId: string) {
  const qc = useQueryClient();
  const invalidate = () =>
    qc.invalidateQueries({ queryKey: projectKeys.group(groupId) });
  const add = useMutation({
    mutationFn: (userId: number) => projectsApi.addGroupMember(groupId, userId),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (userId: number) =>
      projectsApi.removeGroupMember(groupId, userId),
    onSuccess: invalidate,
  });
  return { add, remove };
}

// Projects
export function useProjects(workspaceId?: string) {
  return useQuery({
    queryKey: projectKeys.projects(workspaceId),
    queryFn: () => projectsApi.listProjects(workspaceId),
  });
}
export function useProject(id: string) {
  return useQuery({
    queryKey: projectKeys.project(id),
    queryFn: () => projectsApi.getProject(id),
    enabled: !!id,
  });
}
export function useCreateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: projectsApi.createProject,
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: ["projects", "projects"] }),
  });
}
export function useUpdateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Parameters<typeof projectsApi.updateProject>[1];
    }) => projectsApi.updateProject(id, payload),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ["projects", "projects"] });
      qc.invalidateQueries({ queryKey: projectKeys.project(id) });
    },
  });
}
export function useDeleteProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.deleteProject(id),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: ["projects", "projects"] }),
  });
}
export function useProjectMembers(projectId: string) {
  const qc = useQueryClient();
  const invalidate = () =>
    qc.invalidateQueries({ queryKey: projectKeys.project(projectId) });
  const add = useMutation({
    mutationFn: (userId: number) =>
      projectsApi.addProjectMember(projectId, userId),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (userId: number) =>
      projectsApi.removeProjectMember(projectId, userId),
    onSuccess: invalidate,
  });
  return { add, remove };
}

// Statuses
export function useStatuses(projectId: string) {
  return useQuery({
    queryKey: projectKeys.statuses(projectId),
    queryFn: () => projectsApi.listStatuses(projectId),
    enabled: !!projectId,
  });
}

export function useCreateStatus(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: { name: string; color: string }) =>
      projectsApi.createStatus(projectId, payload),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: projectKeys.statuses(projectId) }),
  });
}
export function useUpdateStatus(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: { name?: string; color?: string };
    }) => projectsApi.updateStatus(id, payload),
    onSuccess: () =>
      qc.invalidateQueries({ queryKey: projectKeys.statuses(projectId) }),
  });
}
export function useDeleteStatus(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.deleteStatus(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.statuses(projectId) });
      // Deleting a status falls its tasks/subtasks back to "no status".
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
    },
  });
}

/** Statuses for many projects at once, keyed by project id (for workspace views). */
export function useWorkspaceStatuses(
  projectIds: string[],
): Map<string, ProjectStatus[]> {
  const results = useQueries({
    queries: projectIds.map((pid) => ({
      queryKey: projectKeys.statuses(pid),
      queryFn: () => projectsApi.listStatuses(pid),
      enabled: !!pid,
    })),
  });

  const map = new Map<string, ProjectStatus[]>();
  projectIds.forEach((pid, idx) => map.set(pid, results[idx]?.data ?? []));
  return map;
}

// Tasks
export function useTasks(projectId: string) {
  return useQuery({
    queryKey: projectKeys.tasks(projectId),
    queryFn: () => projectsApi.listTasks(projectId),
    enabled: !!projectId,
  });
}
export function useTask(id: string) {
  return useQuery({
    queryKey: projectKeys.task(id),
    queryFn: () => projectsApi.getTask(id),
    enabled: !!id,
  });
}

/** Fetch and merge tasks across several projects (for workspace-level views). */
export function useWorkspaceTasks(projectIds: string[]): {
  tasks: Task[];
  isLoading: boolean;
} {
  const results = useQueries({
    queries: projectIds.map((pid) => ({
      queryKey: projectKeys.tasks(pid),
      queryFn: () => projectsApi.listTasks(pid),
      enabled: !!pid,
    })),
  });

  const tasks = results.flatMap((r) => r.data?.data ?? []);
  const isLoading = results.some((r) => r.isLoading);
  return { tasks, isLoading };
}

/** Fetch and merge subtasks across many tasks (for workspace-level views). */
export function useWorkspaceSubtasks(taskIds: string[]): {
  subtasks: Subtask[];
  isLoading: boolean;
} {
  const results = useQueries({
    queries: taskIds.map((tid) => ({
      queryKey: projectKeys.subtasks(tid),
      queryFn: () => projectsApi.listSubtasks(tid),
      enabled: !!tid,
    })),
  });

  const subtasks = results.flatMap((r) => r.data?.data ?? []);
  const isLoading = results.some((r) => r.isLoading);
  return { subtasks, isLoading };
}
export function useCreateTask(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateTaskPayload) =>
      projectsApi.createTask(projectId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}
export function useUpdateTask(projectId: string) {
  const qc = useQueryClient();
  const key = projectKeys.tasks(projectId);
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Partial<CreateTaskPayload>;
    }) => projectsApi.updateTask(id, payload),
    // Optimistically apply the change (e.g. a drag-and-drop status move) so the
    // board updates instantly; roll back on error.
    onMutate: async ({ id, payload }) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueryData<Paginated<Task>>(key);
      if (prev) {
        qc.setQueryData<Paginated<Task>>(key, {
          ...prev,
          data: prev.data.map((t) => (t.id === id ? { ...t, ...payload } : t)),
        });
      }
      return { prev };
    },
    onError: (_err, _vars, ctx) => {
      if (ctx?.prev) qc.setQueryData(key, ctx.prev);
    },
    onSuccess: (data, { payload }) => {
      // Celebrate when this update transitioned the task into "done".
      if (payload.status_id && data?.status?.key === "done") {
        celebrateTask(data.id);
      }
    },
    onSettled: (_data, _err, { id }) => {
      qc.invalidateQueries({ queryKey: key });
      // Refresh the single-task detail (status/priority/etc. + new activity log)
      // so an open task view reflects the change.
      qc.invalidateQueries({ queryKey: projectKeys.task(id) });
      // Assignment/status changes move effort between members.
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

export function useDeleteTask(projectId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.deleteTask(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: projectKeys.tasks(projectId) });
      qc.removeQueries({ queryKey: projectKeys.task(id) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

// Subtasks
export function useSubtasks(taskId: string) {
  return useQuery({
    queryKey: projectKeys.subtasks(taskId),
    queryFn: () => projectsApi.listSubtasks(taskId),
    enabled: !!taskId,
  });
}
export function useCreateSubtask(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: {
      name: string;
      assignee_id: number | null;
      status_id: string;
      priority: string;
      effort_score: number;
      deadline?: string | null;
    }) => projectsApi.createSubtask(taskId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}
export function useUpdateSubtask(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: string;
      payload: Record<string, unknown>;
    }) => projectsApi.updateSubtask(id, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

export function useDeleteSubtask(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.deleteSubtask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.subtasks(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

// Activity log (task history)
export function useActivityLogs(taskId: string) {
  return useQuery({
    queryKey: ["projects", "tasks", taskId, "activity"],
    queryFn: () => projectsApi.listActivityLogs(taskId),
    enabled: !!taskId,
  });
}

// Comments / Links
export function useTaskComments(taskId: string) {
  return useQuery({
    queryKey: projectKeys.comments(taskId),
    queryFn: () => projectsApi.listTaskComments(taskId),
    enabled: !!taskId,
  });
}
export function useAddComment(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (content: string) =>
      projectsApi.addTaskComment(taskId, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.comments(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}
/** Edit a comment. Only its author may — the server enforces that too. */
export function useUpdateComment(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, content }: { id: string; content: string }) =>
      projectsApi.updateComment(id, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.comments(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}

export function useTaskLinks(taskId: string) {
  return useQuery({
    queryKey: projectKeys.links(taskId),
    queryFn: () => projectsApi.listTaskLinks(taskId),
    enabled: !!taskId,
  });
}
export function useAddLink(taskId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (url: string) => projectsApi.addTaskLink(taskId, url),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: projectKeys.links(taskId) });
      qc.invalidateQueries({ queryKey: projectKeys.task(taskId) });
    },
  });
}

// Archives (admin only)
export const archiveKeys = {
  workspaces: ["admin", "archives", "workspaces"] as const,
  projects: ["admin", "archives", "projects"] as const,
  tasks: ["admin", "archives", "tasks"] as const,
  subtasks: ["admin", "archives", "subtasks"] as const,
};

export function useArchivedWorkspaces(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.workspaces, params ?? {}],
    queryFn: () => projectsApi.listArchivedWorkspaces(params),
  });
}

export function useRestoreWorkspace() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.restoreWorkspace(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.workspaces });
      qc.invalidateQueries({ queryKey: projectKeys.workspaces });
    },
  });
}

export function useArchivedProjects(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.projects, params ?? {}],
    queryFn: () => projectsApi.listArchivedProjects(params),
  });
}

export function useRestoreProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.restoreProject(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.projects });
      qc.invalidateQueries({ queryKey: projectKeys.projects("all") });
    },
  });
}

export function useArchivedTasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.tasks, params ?? {}],
    queryFn: () => projectsApi.listArchivedTasks(params),
  });
}

export function useRestoreTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.restoreTask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.tasks });
      // Restored task reappears on its project board.
      qc.invalidateQueries({ queryKey: ["projects", "projects"] });
    },
  });
}

export function useArchivedSubtasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...archiveKeys.subtasks, params ?? {}],
    queryFn: () => projectsApi.listArchivedSubtasks(params),
  });
}

export function useRestoreSubtask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.restoreSubtask(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: archiveKeys.subtasks });
      // Restored subtask reappears under its parent task.
      qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
    },
  });
}

// Workload Analysis
export const workloadKeys = {
  analysis: ["projects", "workload", "analysis"] as const,
  tasks: ["projects", "workload", "tasks"] as const,
};

export function useWorkloadAnalysis(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...workloadKeys.analysis, params ?? {}],
    queryFn: () => projectsApi.getWorkloadAnalysis(params),
  });
}

export function useWorkloadTasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...workloadKeys.tasks, params ?? {}],
    queryFn: () => projectsApi.listWorkloadTasks(params),
  });
}

/**
 * Retuning capacity re-derives every workload level, so it invalidates the
 * analysis AND the per-member/group indicators shown in the task modal.
 */
export function useUpdateWorkloadCapacity() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: {
      max_capacity: number;
      light_max: number;
      moderate_max: number;
    }) => projectsApi.updateWorkloadCapacity(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["projects", "workload"] });
      qc.invalidateQueries({ queryKey: projectKeys.workloads });
    },
  });
}

// Approvals — the PM/Admin review queue for member-submitted work.
export const approvalKeys = {
  tasks: ["admin", "approvals", "tasks"] as const,
  subtasks: ["admin", "approvals", "subtasks"] as const,
};

/**
 * A decision (or a resubmission) moves work between the queue and the board, so
 * it invalidates both — plus workloads, which a newly approved item feeds into.
 */
function invalidateAfterReview(qc: ReturnType<typeof useQueryClient>): void {
  qc.invalidateQueries({ queryKey: approvalKeys.tasks });
  qc.invalidateQueries({ queryKey: approvalKeys.subtasks });
  qc.invalidateQueries({ queryKey: ["projects", "tasks"] });
  qc.invalidateQueries({ queryKey: ["projects", "projects"] });
  qc.invalidateQueries({ queryKey: projectKeys.workloads });
}

/** `enabled` is false for non-managers — the endpoint 403s for them. */
export function usePendingTasks(
  params?: Record<string, unknown>,
  enabled = true,
) {
  return useQuery({
    queryKey: [...approvalKeys.tasks, params ?? {}],
    queryFn: () => projectsApi.listPendingTasks(params),
    enabled,
  });
}

export function usePendingSubtasks(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: [...approvalKeys.subtasks, params ?? {}],
    queryFn: () => projectsApi.listPendingSubtasks(params),
  });
}

export function useApproveTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.approveTask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useBulkApproveTasks() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (ids: string[]) => projectsApi.bulkApproveTasks(ids),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useBulkApproveSubtasks() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (ids: string[]) => projectsApi.bulkApproveSubtasks(ids),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useRejectTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      projectsApi.rejectTask(id, reason),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useApproveSubtask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.approveSubtask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useRejectSubtask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      projectsApi.rejectSubtask(id, reason),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useResubmitTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.resubmitTask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}

export function useResubmitSubtask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => projectsApi.resubmitSubtask(id),
    onSuccess: () => invalidateAfterReview(qc),
  });
}
