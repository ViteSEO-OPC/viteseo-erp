import type { User } from "@/core/types";
import type {
  ApprovalStatus,
  Priority,
  StatusKey,
} from "@/modules/projects/types/common";

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
