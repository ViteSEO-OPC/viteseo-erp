import type { User } from "@/core/types";

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
