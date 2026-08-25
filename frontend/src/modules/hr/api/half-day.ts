import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type { HalfDayRequest } from "@/modules/hr/types/leave";

export async function getHalfDayRequests(params: QueueParams): Promise<QueuePage<HalfDayRequest>> {
  const res = await apiClient.get("/hr/half-day-requests", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export async function createHalfDayRequest(input: {
  work_date: string;
  period: "AM" | "PM";
  reason: string;
}): Promise<HalfDayRequest> {
  const res = await apiClient.post("/hr/half-day-requests", input);
  return res.data.data;
}

export async function approveHalfDayRequest(id: number): Promise<HalfDayRequest> {
  const res = await apiClient.post(`/hr/half-day-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectHalfDayRequest(
  id: number,
  reason: string,
): Promise<HalfDayRequest> {
  const res = await apiClient.post(`/hr/half-day-requests/${id}/reject`, { reason });
  return res.data.data;
}

export async function reopenHalfDayRequest(id: number, reason?: string): Promise<HalfDayRequest> {
  const res = await apiClient.post(`/hr/half-day-requests/${id}/reopen`, { reason });
  return res.data.data;
}

/** The employee withdraws their own pending request. Reason required. */
export async function cancelHalfDayRequest(id: number, reason: string): Promise<HalfDayRequest> {
  const res = await apiClient.post(`/hr/half-day-requests/${id}/cancel`, { reason });
  return res.data.data;
}
