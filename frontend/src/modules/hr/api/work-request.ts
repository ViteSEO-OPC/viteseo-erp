import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type { WorkRequest } from "@/modules/hr/types/leave";

export async function getWorkRequests(params: QueueParams): Promise<QueuePage<WorkRequest>> {
  const res = await apiClient.get("/hr/work-requests", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

/**
 * Ask to work today. No date and no type: this stands in for a time-in punch, so the server
 * uses its own Manila today and derives the kind of request from it — a client-sent date would
 * let someone file for a day they are not standing in.
 */
export async function createWorkRequest(reason?: string): Promise<WorkRequest> {
  const res = await apiClient.post("/hr/work-requests", { reason });
  return res.data.data;
}

/** HR: approve — this WRITES the attendance row, stamped at `requested_at`. */
export async function approveWorkRequest(id: number): Promise<WorkRequest> {
  const res = await apiClient.post(`/hr/work-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectWorkRequest(id: number, reason: string): Promise<WorkRequest> {
  const res = await apiClient.post(`/hr/work-requests/${id}/reject`, { reason });
  return res.data.data;
}

/** HR: undo the decision. An approved one takes its attendance row with it. */
export async function reopenWorkRequest(id: number, reason?: string): Promise<WorkRequest> {
  const res = await apiClient.post(`/hr/work-requests/${id}/reopen`, { reason });
  return res.data.data;
}

/** The employee withdraws their own pending request. Reason required. */
export async function cancelWorkRequest(id: number, reason: string): Promise<WorkRequest> {
  const res = await apiClient.post(`/hr/work-requests/${id}/cancel`, { reason });
  return res.data.data;
}
