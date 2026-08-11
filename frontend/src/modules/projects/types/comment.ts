import type { User } from "@/core/types";

export interface Comment {
  id: string;
  task_id: string | null;
  subtask_id: string | null;
  content: string;
  /**
   * The version the thread first saw. Null until the author's first edit — so
   * a non-null value is what makes "View Original" available to everyone.
   * It stays the ORIGINAL across later edits, not the previous version.
   */
  original_content: string | null;
  edited_at: string | null;
  author_id?: number;
  author?: User | null;
  created_at: string;
}
