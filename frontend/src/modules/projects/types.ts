import type { User } from "@/core/types";

export type Priority = "Low" | "Medium" | "High";
export type AssignmentType = "group" | "individual";
export type StatusKey = "todo" | "in_progress" | "done" | "custom";
export type FlagReason = "assignee_left_group" | "group_reassigned";

/**
 * Review state of a task/sub-task. Anything a PM/Admin creates is "approved" on
 * the spot; a member's proposal starts "pending" and stays off every board,
 * workload total and search result until it is approved. Only its author (and
 * the review queue) can see it in the meantime.
 */
export type ApprovalStatus = "approved" | "pending" | "rejected";

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
  /** Groups working on this project (auto-attached when all their members join). */
  groups?: Group[];
  groups_count?: number;
  tasks_count?: number;
  created_at: string;
  updated_at: string;
}

/** Lightweight project reference embedded on a Group (a group spans many). */
export interface GroupProjectRef {
  id: string;
  name: string;
  priority: "low" | "medium" | "high" | null;
  status?: ProjectState;
}

export interface Group {
  id: string;
  name: string;
  color: string;
  archived: boolean;
  leader?: User;
  members?: User[];
  members_count?: number;
  /** Projects this group belongs to (auto-attached when a project holds its roster). */
  projects?: GroupProjectRef[];
  projects_count?: number;
  created_at?: string;
}

export interface Task {
  id: string;
  project_id: string;
  title: string;
  description: string | null;
  assignment_type: AssignmentType;
  group_id: string | null;
  group?: { id: string; name: string; color?: string | null } | null;
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
  start_date?: string | null;
  subtasks_count?: number;
  // Board-list flag: the requesting user has a subtask assigned under this task,
  // so it belongs in their "My Tasks" view even if the task itself isn't theirs.
  has_my_subtask?: boolean;
  // Board-list flag: this is a group task and the requesting user leads that
  // group, so the parent task is theirs to own on their "My Tasks" view.
  leads_my_group?: boolean;
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
    approval_status: ApprovalStatus;
    rejection_reason: string | null;
  }[];
  // Review state. "approved" for everything a PM/Admin created directly; a
  // non-approved row is the viewer's own proposal (nobody else can see it).
  approval_status: ApprovalStatus;
  rejection_reason: string | null;
  submitted_by_id?: number | null;
  submitted_by?: User | null;
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
  approval_status: ApprovalStatus;
  rejection_reason: string | null;
  submitted_by_id?: number | null;
  submitted_by?: User | null;
  created_at: string;
  updated_at: string;
}

export interface Comment {
  id: string;
  task_id: string | null;
  subtask_id: string | null;
  content: string;
  /**
   * The version the thread first saw. Null until the author's first edit — so
   * a non-null value is what makes "View Original" available to everyone.
   * It stays the ORIGINAL across later edits, not the previous version.
   */
  original_content: string | null;
  edited_at: string | null;
  author_id?: number;
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
  start_date?: string | null;
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

/**
 * A member's task proposal as it appears in the PM/Admin review queue.
 * `id` is the task's own UUID, so a row opens the ordinary task modal (?task=)
 * where the reviewer edits it before deciding.
 */
export interface PendingTask {
  id: string;
  project_id: string | null;
  project_name: string | null;
  title: string;
  description: string | null;
  priority: Priority;
  effort_score: number | null;
  status: { id: string; name: string; key: StatusKey; color: string | null } | null;
  deadline: string | null;
  start_date: string | null;
  subtasks_count?: number;
  approval_status: ApprovalStatus;
  rejection_reason: string | null;
  submitted_by: User | null;
  reviewed_by: User | null;
  reviewed_at: string | null;
  created_at: string;
  updated_at: string;
}

/** A stand-alone sub-task proposal in the review queue (one bundled with a task
 *  proposal is reviewed through its parent and never appears here). */
export interface PendingSubtask {
  id: string;
  task_id: string | null;
  task_name: string | null;
  project_id: string | null;
  project_name: string | null;
  name: string;
  description: string | null;
  priority: Priority;
  effort_score: number;
  status: { id: string; name: string; key: StatusKey; color: string | null } | null;
  deadline: string | null;
  approval_status: ApprovalStatus;
  rejection_reason: string | null;
  submitted_by: User | null;
  reviewed_by: User | null;
  reviewed_at: string | null;
  created_at: string;
  updated_at: string;
}

/**
 * Outcome of a bulk approval. `skipped` counts rows that were no longer
 * approvable when the batch ran — someone else decided them, or they were
 * removed — so a stale selection reports honestly instead of failing whole.
 */
export interface BulkApproveResult {
  approved: number;
  skipped: number;
}

/** Aggregated live workload for a member (Member Workload Indicator). */
export type WorkloadLevel = "light" | "moderate" | "heavy";

export interface Workload {
  user_id: number;
  /** Sum of effort scores from the member's active (Todo/In Progress) work. */
  effort_score: number;
  level: WorkloadLevel;
  /** Inclusive upper bounds for the Light and Moderate levels. */
  thresholds: { light: number; moderate: number };
}

/** Aggregated live workload for a group (same rules, scoped to the group). */
export interface GroupWorkload {
  group_id: string;
  effort_score: number;
  level: WorkloadLevel;
  thresholds: { light: number; moderate: number };
}

// ---------------------------------------------------------------------------
// Global search (topbar jump-to menu)
// ---------------------------------------------------------------------------

/** Shortest term the search endpoint accepts — mirrors SearchService::MIN_TERM_LENGTH. */
export const SEARCH_MIN_LENGTH = 2;

export interface SearchProjectHit {
  id: string;
  name: string;
  /** Truncated server-side to one line of menu context. */
  description: string | null;
  color: string | null;
  status: ProjectState;
  workspace: { id: string; name: string } | null;
  tasks_count: number;
}

export interface SearchTaskHit {
  id: string;
  title: string;
  priority: Priority | null;
  deadline: string | null;
  project: { id: string; name: string } | null;
  status: { id: string; name: string; key: StatusKey; color: string | null } | null;
  assignee: { id: number; name: string; avatar: string | null } | null;
}

export interface SearchSubtaskHit {
  id: string;
  name: string;
  priority: Priority | null;
  deadline: string | null;
  /** The parent task — a sub-task is opened through it, so this drives the link. */
  task: { id: string; title: string } | null;
  project: { id: string; name: string } | null;
  status: { id: string; name: string; key: StatusKey; color: string | null } | null;
  assignee: { id: number; name: string; avatar: string | null } | null;
}

export interface SearchResults {
  projects: SearchProjectHit[];
  tasks: SearchTaskHit[];
  subtasks: SearchSubtaskHit[];
}
