import { apiClient } from "@/core/api-client";
import type { RestDayRequest } from "@/modules/hr/types/leave";

export async function getRestDayRequests(): Promise<RestDayRequest[]> {
  const res = await apiClient.get("/hr/rest-day-requests");
  return res.data.data;
}

/**
 * Ask to work today's rest day. No date: this stands in for a time-in punch, so the server
 * uses its own Manila today — a client-sent date would let someone file for a day they are
 * not standing in.
 */
export async function createRestDayRequest(reason?: string): Promise<RestDayRequest> {
  const res = await apiClient.post("/hr/rest-day-requests", { reason });
  return res.data.data;
}

/** HR: approve — this WRITES the attendance row, stamped at `requested_at`. */
export async function approveRestDayRequest(id: number): Promise<RestDayRequest> {
  const res = await apiClient.post(`/hr/rest-day-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectRestDayRequest(
  id: number,
  reason: string,
): Promise<RestDayRequest> {
  const res = await apiClient.post(`/hr/rest-day-requests/${id}/reject`, { reason });
  return res.data.data;
}

/** HR: undo the decision. An approved one takes its attendance row with it. */
export async function reopenRestDayRequest(id: number, reason?: string): Promise<RestDayRequest> {
  const res = await apiClient.post(`/hr/rest-day-requests/${id}/reopen`, { reason });
  return res.data.data;
}

/** The employee withdraws their own pending request. Reason required. */
export async function cancelRestDayRequest(id: number, reason: string): Promise<RestDayRequest> {
  const res = await apiClient.post(`/hr/rest-day-requests/${id}/cancel`, { reason });
  return res.data.data;
}
