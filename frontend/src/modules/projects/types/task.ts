import type { User } from "@/core/types";
import type {
  ApprovalStatus,
  AssignmentType,
  Priority,
  StatusKey,
} from "@/modules/projects/types/common";
import type { ActivityLog } from "@/modules/projects/types/activity-log";
import type { Comment } from "@/modules/projects/types/comment";
import type { Link } from "@/modules/projects/types/link";
import type { Subtask } from "@/modules/projects/types/subtask";

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

/**
 * One row of the open-task pool.
 *
 * Carries its project's NAME, unlike the board's {@link Task}: the pool spans
 * projects the viewer may not be a member of, so `useProjects()` has nothing to
 * resolve a bare id against.
 */
export interface ClaimableTask {
  id: string;
  title: string;
  project: { id: string; name: string } | null;
  status: {
    id: string;
    name: string;
    key: StatusKey;
    color: string | null;
  } | null;
  priority: Priority;
  effort_score: number | null;
  deadline: string | null;
  /** Who put it up for grabs. */
  posted_by: User | null;
  created_at: string;
}
