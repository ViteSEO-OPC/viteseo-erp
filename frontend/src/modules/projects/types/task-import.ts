import type { AssignmentType, Priority } from "@/modules/projects/types/common";

/** A pickable value in a remap dropdown or an inline editor. */
export interface ImportOption {
  /** Status/group are UUID strings; members are numeric user ids. */
  value: string | number;
  label: string;
}

/** Everything the preview screen can offer as a replacement value. */
export interface ImportOptions {
  statuses: ImportOption[];
  groups: ImportOption[];
  members: ImportOption[];
  priorities: ImportOption[];
}

/** The field an unresolved value belongs to. */
export type ImportField =
  | "status"
  | "group"
  | "assignee"
  | "priority"
  | "effort_score";

/**
 * One value the assistant wrote that this project has no match for, with every
 * row it appears on.
 *
 * These are aggregated server-side: an invented status used on fourteen rows is
 * one entry with fourteen `refs`, so the PM fixes it once. `refs` is what the
 * client applies the fix to — never the raw string, which would have to be
 * re-matched.
 */
export interface UnresolvedValue {
  field: ImportField;
  /** What the assistant wrote. `null` when the field was missing entirely. */
  raw: string | null;
  /** `select` renders a dropdown of `candidates`; `number` renders an input. */
  kind: "select" | "number";
  label: string;
  count: number;
  refs: string[];
  candidates: ImportOption[];
}

/** Fields still needing a value before a row can be created. */
export type UnresolvedField =
  | "status_id"
  | "group_id"
  | "assignee_id"
  | "priority"
  | "effort_score";

interface ImportRowBase {
  /** Synthetic, stable within one preview: `t3`, `t3s1`. */
  ref: string;
  status_id: string | null;
  status_raw: string | null;
  priority: Priority | null;
  priority_raw: string | null;
  assignee_id: number | null;
  assignee_raw: string | null;
  deadline: string | null;
  effort_score: number | null;
  description: string | null;
  /** Validation failures — fixed inline on the row. */
  errors: string[];
  /** Missing/unmatched values — fixed from the remap bar or inline. */
  unresolved_fields: UnresolvedField[];
}

export interface ImportPreviewSubtask extends ImportRowBase {
  kind: "subtask";
  name: string | null;
}

export interface ImportPreviewTask extends ImportRowBase {
  kind: "task";
  title: string | null;
  assignment_type: AssignmentType;
  group_id: string | null;
  group_raw: string | null;
  start_date: string | null;
  subtasks: ImportPreviewSubtask[];
}

/**
 * What the import is allowed to produce, decided server-side from the caller's
 * permission.
 *
 * `direct` — a manager assigning across the project. Tasks land approved and on
 * the board immediately.
 * `proposal` — a member submitting their own work. Every row is forced to their
 * name, effort is required, and nothing appears on the board until a PM approves.
 */
export type ImportMode = "direct" | "proposal";

/**
 * What this import would do to the author's own load, on the `proposal` path only.
 *
 * `current` counts only APPROVED work, so a pending import contributes nothing to
 * it — this is "what your load becomes if your PM approves all of it", and the UI
 * says so. Advisory: it warns, it never blocks.
 */
export interface ImportWorkload {
  current: number;
  adds: number;
  projected: number;
  level: "light" | "moderate" | "heavy";
  projected_level: "light" | "moderate" | "heavy";
  thresholds: { light: number; moderate: number; max: number };
}

/** The dry-run response. Nothing has been created at this point. */
export interface ImportPlan {
  project_id: string;
  mode: ImportMode;
  summary: {
    tasks: number;
    subtasks: number;
    ready: number;
    needs_attention: number;
  };
  options: ImportOptions;
  unresolved: UnresolvedValue[];
  tasks: ImportPreviewTask[];
  /** Present on the `proposal` path only. */
  workload: ImportWorkload | null;
}

/**
 * What a commit reports back. With `dry_run` nothing is created and `errors`
 * carries the re-validation result, keyed by row ref.
 */
export interface ImportResult {
  created_tasks: number;
  created_subtasks: number;
  errors: Record<string, string[]>;
}
