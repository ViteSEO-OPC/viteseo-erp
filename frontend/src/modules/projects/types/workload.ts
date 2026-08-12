import type { Priority, StatusKey } from "@/modules/projects/types/common";

/**
 * Workload Analysis.
 *
 * Two different notions of "heavy" live here and they are NOT the same thing:
 *  - `WorkloadCategory` grades ONE task by its own effort score (Light 1-3,
 *    Moderate 4-7, Heavy 8-10) — the KPI cards and the table's Category badge.
 *  - `WorkloadLevel` grades a PERSON (or group) by their summed effort against
 *    the editable capacity bands — the chart colouring and the assignment
 *    indicator in the task modal.
 */
export type WorkloadCategory = "light" | "moderate" | "heavy";

/** One row of active work, flattened across tasks and sub-tasks. */
export interface WorkloadRow {
  id: string;
  kind: "task" | "subtask";
  title: string;
  /** Set on sub-task rows — the parent task's title, for context. */
  parent_title?: string | null;
  project_id: string | null;
  project_name: string | null;
  task_id: string | null;
  effort_score: number;
  category: WorkloadCategory;
  priority: Priority | null;
  deadline: string | null;
  assignee_id: number | null;
  assignee_name: string | null;
  group_id: string | null;
  group_name: string | null;
  status_key: StatusKey | null;
}

/** A chart bar: effort split by task category, plus the band the total falls in. */
export interface WorkloadBar {
  id: number | string;
  name: string;
  light: number;
  moderate: number;
  heavy: number;
  total_effort: number;
  task_count: number;
  level: WorkloadLevel;
  /** Group rows only. */
  member_count?: number;
}

/** The editable capacity bands (inclusive upper bounds). */
export interface WorkloadThresholds {
  light: number;
  moderate: number;
  max: number;
}

export interface WorkloadAnalysis {
  totals: { total: number; light: number; moderate: number; heavy: number };
  thresholds: WorkloadThresholds;
  can_adjust_capacity: boolean;
  /** Manager payload. */
  members?: WorkloadBar[];
  groups?: WorkloadBar[];
  /** Member payload. */
  me?: WorkloadBar;
  completion?: {
    todo: number;
    in_progress: number;
    done: number;
    total: number;
    percent: number;
  };
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
