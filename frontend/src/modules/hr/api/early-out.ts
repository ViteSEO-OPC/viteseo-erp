import { apiClient } from "@/core/api-client";
import type { EarlyOutRequest } from "@/modules/hr/types/leave";

export async function getEarlyOutRequests(): Promise<EarlyOutRequest[]> {
  const res = await apiClient.get("/hr/early-out-requests");
  return res.data.data;
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
