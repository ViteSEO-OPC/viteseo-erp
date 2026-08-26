import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type { EarlyOutRequest } from "@/modules/hr/types/leave";

export async function getEarlyOutRequests(params: QueueParams): Promise<QueuePage<EarlyOutRequest>> {
  const res = await apiClient.get("/hr/early-out-requests", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export async function createEarlyOutRequest(input: {
  work_date: string;
  early_out_time: string;
  reason: string;
}): Promise<EarlyOutRequest> {
  const res = await apiClient.post("/hr/early-out-requests", input);
  return res.data.data;
}

export async function approveEarlyOutRequest(id: number): Promise<EarlyOutRequest> {
  const res = await apiClient.post(`/hr/early-out-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectEarlyOutRequest(
  id: number,
  reason: string,
): Promise<EarlyOutRequest> {
  const res = await apiClient.post(`/hr/early-out-requests/${id}/reject`, { reason });
  return res.data.data;
}

export async function reopenEarlyOutRequest(id: number, reason?: string): Promise<EarlyOutRequest> {
  const res = await apiClient.post(`/hr/early-out-requests/${id}/reopen`, { reason });
  return res.data.data;
}

/** The employee withdraws their own pending request. Reason required. */
export async function cancelEarlyOutRequest(id: number, reason: string): Promise<EarlyOutRequest> {
  const res = await apiClient.post(`/hr/early-out-requests/${id}/cancel`, { reason });
  return res.data.data;
}
