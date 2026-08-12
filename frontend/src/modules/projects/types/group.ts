import type { User } from "@/core/types";
import type { ProjectState } from "@/modules/projects/types/project";

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
