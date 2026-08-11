import type { User } from "@/core/types";
import type { StatusKey } from "@/modules/projects/types/common";
import type { Group } from "@/modules/projects/types/group";

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

/**
 * What a project member is still holding, when removing them is refused.
 *
 * The server returns this in the 409 body so the PM can reassign from the same
 * dialog instead of hunting for the work — a sub-task carries its parent's id
 * because that is what a task modal opens.
 */
export interface BlockingWork {
  member: { id: number; name: string };
  tasks: { id: string; title: string; status: string | null }[];
  subtasks: {
    id: string;
    name: string;
    status: string | null;
    task_id: string | null;
    task_title: string | null;
  }[];
}
