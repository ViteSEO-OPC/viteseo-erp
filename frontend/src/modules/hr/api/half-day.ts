import { apiClient } from "@/core/api-client";
import type { HalfDayRequest } from "@/modules/hr/types/leave";

export async function getHalfDayRequests(): Promise<HalfDayRequest[]> {
  const res = await apiClient.get("/hr/half-day-requests");
  return res.data.data;
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
