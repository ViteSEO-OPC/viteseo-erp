import apiClient from "@/core/api/client";
import type { ApiResource, Paginated, User } from "@/core/types";
import type {
  ActivityLog,
  ArchivedWorkspace,
  ArchivedProject,
  ArchivedTask,
  ArchivedSubtask,
  Comment,
  CreateTaskPayload,
  Group,
  BulkApproveResult,
  Link,
  PendingSubtask,
  PendingTask,
  Project,
  ProjectStatus,
  GroupWorkload,
  SearchResults,
  Subtask,
  Task,
  Workload,
  Workspace,
} from "./types";
import type { NotificationDto } from "./notifications";

// Paths are relative to the shared api-client base (NEXT_PUBLIC_API_BASE_URL,
// default `/api/v1`), so no `/api/v1` prefix here.
const BASE = "/projects";

function filter(workspaceId?: string): Record<string, unknown> {
  return workspaceId ? { "filter[workspace_id]": workspaceId } : {};
}

export const projectsApi = {
  // Users (shared directory, for member/assignee pickers)
  listUsers: async (search?: string): Promise<User[]> => {
    const { data } = await apiClient.get<Paginated<User>>(`${BASE}/users`, {
      params: { search, per_page: 100 },
    });
    return data.data;
  },
  // Live member workload (Member Workload Indicator) — loaded on demand when an
  // assignee is selected in the task modal.
  getUserWorkload: async (userId: number): Promise<Workload> => {
    const { data } = await apiClient.get<ApiResource<Workload>>(
      `${BASE}/members/${userId}/workload`,
    );
    return data.data;
  },
  // Live group workload (same aggregation, scoped to the group) — for the
  // Groups page and the task modal's group-assignment indicator.
  getGroupWorkload: async (groupId: string): Promise<GroupWorkload> => {
    const { data } = await apiClient.get<ApiResource<GroupWorkload>>(
      `${BASE}/groups/${groupId}/workload`,
    );
    return data.data;
  },

  // Global search across projects and tasks (topbar jump-to menu). `q` must be
  // at least SEARCH_MIN_LENGTH characters or the API rejects it with a 422.
  // `signal` comes from TanStack Query so a superseded keystroke's request is
  // aborted instead of racing the current one.
  search: async (
    q: string,
    limit = 5,
    signal?: AbortSignal,
  ): Promise<SearchResults> => {
    const { data } = await apiClient.get<ApiResource<SearchResults>>(
      `${BASE}/search`,
      { params: { q, limit }, signal },
    );
    return data.data;
  },

  // Workspaces
  listWorkspaces: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<Workspace>> => {
    const { data } = await apiClient.get<Paginated<Workspace>>(
      `${BASE}/workspaces`,
      { params },
    );
    return data;
  },
  getWorkspace: async (id: string): Promise<Workspace> => {
    const { data } = await apiClient.get<ApiResource<Workspace>>(
      `${BASE}/workspaces/${id}`,
    );
    return data.data;
  },
  createWorkspace: async (payload: {
    name: string;
    description?: string;
  }): Promise<Workspace> => {
    const { data } = await apiClient.post<ApiResource<Workspace>>(
      `${BASE}/workspaces`,
      payload,
    );
    return data.data;
  },
  updateWorkspace: async (
    id: string,
    payload: { name?: string; description?: string | null },
  ): Promise<Workspace> => {
    const { data } = await apiClient.put<ApiResource<Workspace>>(
      `${BASE}/workspaces/${id}`,
      payload,
    );
    return data.data;
  },
  deleteWorkspace: async (id: string): Promise<void> => {
    await apiClient.delete(`${BASE}/workspaces/${id}`);
  },
  // Groups (scoped to a project)
  listGroups: async (projectId?: string): Promise<Paginated<Group>> => {
    const { data } = await apiClient.get<Paginated<Group>>(`${BASE}/groups`, {
      params: {
        per_page: 100,
        ...(projectId ? { "filter[project_id]": projectId } : {}),
      },
    });
    return data;
  },

  getGroup: async (id: string): Promise<Group> => {
    const { data } = await apiClient.get<ApiResource<Group>>(
      `${BASE}/groups/${id}`,
    );
    return data.data;
  },
  createGroup: async (payload: {
    name: string;
    color: string;
    leader_id: number;
    member_ids: number[];
  }): Promise<Group> => {
    const { data } = await apiClient.post<ApiResource<Group>>(
      `${BASE}/groups`,
      payload,
    );
    return data.data;
  },
  updateGroup: async (
    id: string,
    payload: { name?: string; color?: string; leader_id?: number },
  ): Promise<Group> => {
    const { data } = await apiClient.put<ApiResource<Group>>(
      `${BASE}/groups/${id}`,
      payload,
    );
    return data.data;
  },
  deleteGroup: async (id: string): Promise<void> => {
    await apiClient.delete(`${BASE}/groups/${id}`);
  },
  addGroupMember: async (id: string, userId: number): Promise<void> => {
    await apiClient.post(`${BASE}/groups/${id}/members`, { user_id: userId });
  },
  removeGroupMember: async (id: string, userId: number): Promise<void> => {
    await apiClient.delete(`${BASE}/groups/${id}/members/${userId}`);
  },

  // Projects
  listProjects: async (workspaceId?: string): Promise<Paginated<Project>> => {
    const { data } = await apiClient.get<Paginated<Project>>(
      `${BASE}/projects`,
      {
        params: filter(workspaceId),
      },
    );
    return data;
  },
  getProject: async (id: string): Promise<Project> => {
    const { data } = await apiClient.get<ApiResource<Project>>(
      `${BASE}/projects/${id}`,
    );
    return data.data;
  },
  createProject: async (payload: {
    workspace_id: string;
    name: string;
    description?: string;
    priority?: "low" | "medium" | "high";
    color?: string;
  }): Promise<Project> => {
    const { data } = await apiClient.post<ApiResource<Project>>(
      `${BASE}/projects`,
      payload,
    );
    return data.data;
  },
  updateProject: async (
    id: string,
    payload: {
      name?: string;
      description?: string | null;
      priority?: "low" | "medium" | "high" | null;
      color?: string | null;
      status?: "active" | "done";
    },
  ): Promise<Project> => {
    const { data } = await apiClient.put<ApiResource<Project>>(
      `${BASE}/projects/${id}`,
      payload,
    );
    return data.data;
  },
  deleteProject: async (id: string): Promise<void> => {
    await apiClient.delete(`${BASE}/projects/${id}`);
  },
  addProjectMember: async (id: string, userId: number): Promise<void> => {
    await apiClient.post(`${BASE}/projects/${id}/members`, { user_id: userId });
  },
  removeProjectMember: async (id: string, userId: number): Promise<void> => {
    await apiClient.delete(`${BASE}/projects/${id}/members/${userId}`);
  },

  // Statuses
  listStatuses: async (projectId: string): Promise<ProjectStatus[]> => {
    const { data } = await apiClient.get<ApiResource<ProjectStatus[]>>(
      `${BASE}/projects/${projectId}/statuses`,
    );
    return data.data;
  },
  createStatus: async (
    projectId: string,
    payload: { name: string; color: string },
  ): Promise<ProjectStatus> => {
    const { data } = await apiClient.post<ApiResource<ProjectStatus>>(
      `${BASE}/projects/${projectId}/statuses`,
      payload,
    );
    return data.data;
  },
  updateStatus: async (
    id: string,
    payload: { name?: string; color?: string },
  ): Promise<ProjectStatus> => {
    const { data } = await apiClient.put<ApiResource<ProjectStatus>>(
      `${BASE}/statuses/${id}`,
      payload,
    );
    return data.data;
  },
  deleteStatus: async (id: string): Promise<void> => {
    await apiClient.delete(`${BASE}/statuses/${id}`);
  },

  // Tasks
  listTasks: async (
    projectId: string,
    params?: Record<string, unknown>,
  ): Promise<Paginated<Task>> => {
    const { data } = await apiClient.get<Paginated<Task>>(
      `${BASE}/projects/${projectId}/tasks`,
      { params: { per_page: 100, ...params } },
    );
    return data;
  },
  getTask: async (id: string): Promise<Task> => {
    const { data } = await apiClient.get<ApiResource<Task>>(
      `${BASE}/tasks/${id}`,
    );
    return data.data;
  },
  createTask: async (
    projectId: string,
    payload: CreateTaskPayload,
  ): Promise<Task> => {
    const { data } = await apiClient.post<ApiResource<Task>>(
      `${BASE}/projects/${projectId}/tasks`,
      payload,
    );
    return data.data;
  },
  updateTask: async (
    id: string,
    payload: Partial<CreateTaskPayload>,
  ): Promise<Task> => {
    const { data } = await apiClient.put<ApiResource<Task>>(
      `${BASE}/tasks/${id}`,
      payload,
    );
    return data.data;
  },
  deleteTask: async (id: string): Promise<void> => {
    await apiClient.delete(`${BASE}/tasks/${id}`);
  },

  // Subtasks
  listSubtasks: async (taskId: string): Promise<Paginated<Subtask>> => {
    const { data } = await apiClient.get<Paginated<Subtask>>(
      `${BASE}/tasks/${taskId}/subtasks`,
    );
    return data;
  },
  createSubtask: async (
    taskId: string,
    payload: {
      name: string;
      assignee_id: number | null;
      status_id: string;
      priority: string;
      effort_score: number;
      deadline?: string | null;
    },
  ): Promise<Subtask> => {
    const { data } = await apiClient.post<ApiResource<Subtask>>(
      `${BASE}/tasks/${taskId}/subtasks`,
      payload,
    );
    return data.data;
  },
  updateSubtask: async (
    id: string,
    payload: Record<string, unknown>,
  ): Promise<Subtask> => {
    const { data } = await apiClient.put<ApiResource<Subtask>>(
      `${BASE}/subtasks/${id}`,
      payload,
    );
    return data.data;
  },
  deleteSubtask: async (id: string): Promise<void> => {
    await apiClient.delete(`${BASE}/subtasks/${id}`);
  },

  // Comments
  listTaskComments: async (taskId: string): Promise<Paginated<Comment>> => {
    const { data } = await apiClient.get<Paginated<Comment>>(
      `${BASE}/tasks/${taskId}/comments`,
    );
    return data;
  },
  addTaskComment: async (taskId: string, content: string): Promise<Comment> => {
    const { data } = await apiClient.post<ApiResource<Comment>>(
      `${BASE}/tasks/${taskId}/comments`,
      {
        content,
      },
    );
    return data.data;
  },
  /** Revise your own comment; the server preserves the original version. */
  updateComment: async (id: string, content: string): Promise<Comment> => {
    const { data } = await apiClient.patch<ApiResource<Comment>>(
      `${BASE}/comments/${id}`,
      { content },
    );
    return data.data;
  },

  // Links
  listTaskLinks: async (taskId: string): Promise<Paginated<Link>> => {
    const { data } = await apiClient.get<Paginated<Link>>(
      `${BASE}/tasks/${taskId}/links`,
    );
    return data;
  },
  addTaskLink: async (taskId: string, url: string): Promise<Link> => {
    const { data } = await apiClient.post<ApiResource<Link>>(
      `${BASE}/tasks/${taskId}/links`,
      { url },
    );
    return data.data;
  },

  // Activity log (task history)
  listActivityLogs: async (taskId: string): Promise<Paginated<ActivityLog>> => {
    const { data } = await apiClient.get<Paginated<ActivityLog>>(
      `${BASE}/tasks/${taskId}/activity-logs`,
    );
    return data;
  },

  // Archives (admin only)
  listArchivedWorkspaces: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<ArchivedWorkspace>> => {
    const { data } = await apiClient.get<Paginated<ArchivedWorkspace>>(
      `${BASE}/admin/archives/workspaces`,
      { params },
    );
    return data;
  },
  restoreWorkspace: async (id: string): Promise<void> => {
    await apiClient.post(`${BASE}/admin/archives/workspaces/${id}/restore`);
  },
  listArchivedProjects: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<ArchivedProject>> => {
    const { data } = await apiClient.get<Paginated<ArchivedProject>>(
      `${BASE}/admin/archives/projects`,
      { params },
    );
    return data;
  },
  restoreProject: async (id: string): Promise<void> => {
    await apiClient.post(`${BASE}/admin/archives/projects/${id}/restore`);
  },
  listArchivedTasks: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<ArchivedTask>> => {
    const { data } = await apiClient.get<Paginated<ArchivedTask>>(
      `${BASE}/admin/archives/tasks`,
      { params },
    );
    return data;
  },
  restoreTask: async (id: string): Promise<void> => {
    await apiClient.post(`${BASE}/admin/archives/tasks/${id}/restore`);
  },
  listArchivedSubtasks: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<ArchivedSubtask>> => {
    const { data } = await apiClient.get<Paginated<ArchivedSubtask>>(
      `${BASE}/admin/archives/subtasks`,
      { params },
    );
    return data;
  },
  restoreSubtask: async (id: string): Promise<void> => {
    await apiClient.post(`${BASE}/admin/archives/subtasks/${id}/restore`);
  },

  // Approvals — the PM/Admin review queue for member-submitted work. Reviewers
  // EDIT a proposal through updateTask / updateSubtask above; these endpoints
  // only carry the decision.
  listPendingTasks: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<PendingTask>> => {
    const { data } = await apiClient.get<Paginated<PendingTask>>(
      `${BASE}/admin/approvals/tasks`,
      { params },
    );
    return data;
  },
  listPendingSubtasks: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<PendingSubtask>> => {
    const { data } = await apiClient.get<Paginated<PendingSubtask>>(
      `${BASE}/admin/approvals/subtasks`,
      { params },
    );
    return data;
  },
  approveTask: async (id: string): Promise<Task> => {
    const { data } = await apiClient.post<ApiResource<Task>>(
      `${BASE}/admin/approvals/tasks/${id}/approve`,
    );
    return data.data;
  },
  /** Approve a whole selection at once; returns what went through vs was skipped. */
  bulkApproveTasks: async (ids: string[]): Promise<BulkApproveResult> => {
    const { data } = await apiClient.post<ApiResource<BulkApproveResult>>(
      `${BASE}/admin/approvals/tasks/bulk-approve`,
      { ids },
    );
    return data.data;
  },
  bulkApproveSubtasks: async (ids: string[]): Promise<BulkApproveResult> => {
    const { data } = await apiClient.post<ApiResource<BulkApproveResult>>(
      `${BASE}/admin/approvals/subtasks/bulk-approve`,
      { ids },
    );
    return data.data;
  },
  rejectTask: async (id: string, reason: string): Promise<Task> => {
    const { data } = await apiClient.post<ApiResource<Task>>(
      `${BASE}/admin/approvals/tasks/${id}/reject`,
      { reason },
    );
    return data.data;
  },
  approveSubtask: async (id: string): Promise<Subtask> => {
    const { data } = await apiClient.post<ApiResource<Subtask>>(
      `${BASE}/admin/approvals/subtasks/${id}/approve`,
    );
    return data.data;
  },
  rejectSubtask: async (id: string, reason: string): Promise<Subtask> => {
    const { data } = await apiClient.post<ApiResource<Subtask>>(
      `${BASE}/admin/approvals/subtasks/${id}/reject`,
      { reason },
    );
    return data.data;
  },
  /** Author-side: send a revised (rejected) proposal back for review. */
  resubmitTask: async (id: string): Promise<Task> => {
    const { data } = await apiClient.post<ApiResource<Task>>(
      `${BASE}/tasks/${id}/resubmit`,
    );
    return data.data;
  },
  resubmitSubtask: async (id: string): Promise<Subtask> => {
    const { data } = await apiClient.post<ApiResource<Subtask>>(
      `${BASE}/subtasks/${id}/resubmit`,
    );
    return data.data;
  },

  // Notifications
  listNotifications: async (
    params?: Record<string, unknown>,
  ): Promise<Paginated<NotificationDto>> => {
    const { data } = await apiClient.get<Paginated<NotificationDto>>(
      `${BASE}/notifications`,
      { params: { per_page: 30, ...params } },
    );
    return data;
  },
  getUnreadCount: async (): Promise<number> => {
    const { data } = await apiClient.get<{ unread_count: number }>(
      `${BASE}/notifications/unread-count`,
    );
    return data.unread_count;
  },
  markNotificationRead: async (id: string): Promise<void> => {
    await apiClient.patch(`${BASE}/notifications/${id}/read`);
  },
  markAllNotificationsRead: async (): Promise<void> => {
    await apiClient.patch(`${BASE}/notifications/read-all`);
  },
};

export default projectsApi;
