import { apiClient } from "@/core/api-client";

/**
 * One row on the HR dashboard's approvals card.
 *
 * Assembled by the SERVER from thirteen queues — see ApprovalQueueService. The browser used to
 * merge those thirteen itself, which meant thirteen round trips and up to thirteen hundred rows
 * travelling to draw twenty.
 */
export interface PendingApproval {
  /** Unique across kinds: two queues can both hold a row 7. */
  key: string;
  kind: string;
  id: number;
  /**
   * Who it is from. "Anonymous" for an anonymous concern — decided server-side, so the
   * reporter's name is never sent rather than sent and hidden.
   */
  name: string;
  created_at: string;
  /** The screen that can action this row. */
  href: string;
}

/** Everything awaiting a decision, oldest first, with how many there are altogether. */
export async function getPendingApprovals(
  limit = 50,
): Promise<{ items: PendingApproval[]; total: number }> {
  const res = await apiClient.get("/hr/approvals/pending", { params: { limit } });
  return { items: res.data.data, total: res.data.meta.total };
}
