import type { Priority, StatusKey } from "@/modules/projects/types/common";
import type { ProjectState } from "@/modules/projects/types/project";


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
