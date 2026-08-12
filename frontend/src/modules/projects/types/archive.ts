import type { User } from "@/core/types";
import type {
  AssignmentType,
  Priority,
  StatusKey,
} from "@/modules/projects/types/common";

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
