import type { User } from "@/core/types";
import type {
  ApprovalStatus,
  FlagReason,
  Priority,
  StatusKey,
} from "@/modules/projects/types/common";

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
