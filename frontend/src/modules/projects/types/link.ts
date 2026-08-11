import type { User } from "@/core/types";

export interface Link {
  id: string;
  task_id: string | null;
  subtask_id: string | null;
  url: string;
  user?: User | null;
  created_at: string;
}
