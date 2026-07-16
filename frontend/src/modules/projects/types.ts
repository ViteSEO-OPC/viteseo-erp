import type { User } from "@/core/types";

export type Priority = "Low" | "Medium" | "High";
export type AssignmentType = "group" | "individual";
export type StatusKey = "todo" | "in_progress" | "done" | "custom";
export type FlagReason = "assignee_left_group" | "group_reassigned";

// NOTE: project-management entities (workspace, project, task, subtask, status,
// group, comment, link, activity log) use UUID string ids. User ids remain
// numeric (assignee_id, leader_id, author, user), so those stay `number`.
export interface Workspace {
  id: string;
  name: string;
  description: string | null;
  created_at: string;
  updated_at: string;
}

export interface ProjectStatus {
  id: string;
  project_id: string;
  name: string;
  key: StatusKey;
  /** Hex color for custom statuses; null for the seeded defaults. */
  color: string | null;
  is_protected: boolean;
  order: number;
}

export type ProjectState = "active" | "done";

export interface Project {
  id: string;
  workspace_id: string;
  name: string;
  description: string | null;
  priority: "low" | "medium" | "high" | null;
  color: string | null;
  /** Lifecycle state; a "done" project's tasks drop out of the active views. */
  status: ProjectState;
  statuses?: ProjectStatus[];
  members?: User[];
  members_count?: number;
  tasks_count?: number;
  created_at: string;
  updated_at: string;
}

export interface Group {
  id: string;
  project_id: string;
  name: string;
  color: string;
  archived: boolean;
  leader?: User;
  members?: User[];
  members_count?: number;
  created_at?: string;
}

export interface Task {
  id: string;
  project_id: string;
  title: string;
  description: string | null;
  assignment_type: AssignmentType;
  group_id: string | null;
  group?: { id: string; name: string } | null;
  assignee_id: number | null;
  assignee?: User | null;
  /** Who set the current assignment (null when unassigned). */
  assigned_by_id?: number | null;
  assigned_by?: User | null;
  // Null when the task has "no status" (e.g. its custom status was deleted).
  status_id: string | null;
  status?: {
    id: string;
    name: string;
    key: StatusKey;
    color: string | null;
  } | null;
  priority: Priority;
  effort_score: number | null;
  deadline: string | null;
  subtasks_count?: number;
  // Board-list flag: the requesting user has a subtask assigned under this task,
  // so it belongs in their "My Tasks" view even if the task itself isn't theirs.
  has_my_subtask?: boolean;
  // Board-list only: the requesting user's own subtasks under this task. The
  // member "My Tasks" board renders these as their own cards.
  my_subtasks?: {
    id: string;
    name: string;
    status_id: string | null;
    status: {
      id: string;
      name: string;
      key: StatusKey;
      color: string | null;
    } | null;
    priority: Priority;
    deadline: string | null;
    effort_score: number;
  }[];
  // Full-detail fields — only present when a single task is fetched by UUID
  // (GET /tasks/{uuid}); the lightweight board list omits them.
  project?: { id: string; name: string } | null;
  subtasks?: Subtask[];
  comments?: Comment[];
  links?: Link[];
  activity_logs?: ActivityLog[];
  created_at: string;
  updated_at: string;
}

export interface Subtask {
  id: string;
  task_id: string;
  name: string;
  description: string | null;
  assignee_id: number | null;
  assignee?: User | null;
  status_id: string | null;
  status?: {
    id: string;
    name: string;
    key: StatusKey;
    color: string | null;
  } | null;
  priority: Priority;
  effort_score: number;
  deadline: string | null;
  flag_reason: FlagReason | null;
  flagged_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface Comment {
  id: string;
  task_id: string | null;
  subtask_id: string | null;
  content: string;
  author?: User | null;
  created_at: string;
}

export interface Link {
  id: string;
  task_id: string | null;
  subtask_id: string | null;
  url: string;
  user?: User | null;
  created_at: string;
}

export interface ActivityLog {
  id: string;
  task_id: string;
  subtask_id: string | null;
  action: string;
  field_changed: string | null;
  old_value: string | null;
  new_value: string | null;
  user?: User | null;
  created_at: string;
}

export interface CreateTaskPayload {
  title: string;
  description?: string;
  assignment_type: AssignmentType;
  group_id?: string | null;
  assignee_id?: number | null;
  status_id: string;
  priority: Priority;
  effort_score?: number | null;
  deadline?: string | null;
}

export interface ArchivedWorkspace {
  id: string;
  name: string;
  description: string | null;
  deleted_at: string;
  created_at: string;
  updated_at: string;
}

export interface ArchivedProject {
  id: string;
  workspace_id: string | null;
  workspace_name: string | null;
  name: string;
  description: string | null;
  priority: "low" | "medium" | "high" | null;
  color: string | null;
  deleted_at: string;
  created_at: string;
  updated_at: string;
}

export interface ArchivedTask {
  id: string;
  project_id: string | null;
  project_name: string | null;
  title: string;
  description: string | null;
  assignment_type: AssignmentType;
  group_name: string | null;
  assignee: User | null;
  priority: Priority;
  status: { id: string; name: string; key: StatusKey; color: string | null } | null;
  deadline: string | null;
  deleted_at: string;
  created_at: string;
  updated_at: string;
}

export interface ArchivedSubtask {
  id: string;
  task_id: string | null;
  task_name: string | null;
  project_id: string | null;
  project_name: string | null;
  name: string;
  description: string | null;
  assignee: User | null;
  priority: Priority;
  status: { id: string; name: string; key: StatusKey; color: string | null } | null;
  deadline: string | null;
  deleted_at: string;
  created_at: string;
  updated_at: string;
}
