import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type {
  LeaveType,
  LeaveRequest,
  MyLeaveSummary,
  StoreLeaveInput,
} from "@/modules/hr/types/leave";

export async function getLeaveTypes(): Promise<LeaveType[]> {
  const res = await apiClient.get("/hr/leave-types");
  return res.data.data;
}

export async function getMyLeaveSummary(): Promise<MyLeaveSummary> {
  const res = await apiClient.get("/hr/me/leave-balances");
  return {
    mode: res.data.meta?.mode ?? "not_shared",
    balances: res.data.data ?? [],
    pool: res.data.meta?.pool ?? null,
  };
}

// HR gets all requests; employees get their own (scoped server-side by role).
export async function getLeaveRequests(params: QueueParams): Promise<QueuePage<LeaveRequest>> {
  const res = await apiClient.get("/hr/leave-requests", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export async function createLeaveRequest(
  input: StoreLeaveInput,
): Promise<LeaveRequest> {
  const res = await apiClient.post("/hr/leave-requests", input);
  return res.data.data;
}

export async function cancelLeaveRequest(
  id: number,
  reason: string,
): Promise<LeaveRequest> {
  const res = await apiClient.post(`/hr/leave-requests/${id}/cancel`, { reason });
  return res.data.data;
}

export async function approveLeaveRequest(id: number): Promise<LeaveRequest> {
  const res = await apiClient.post(`/hr/leave-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectLeaveRequest(
  id: number,
  reason: string,
): Promise<LeaveRequest> {
  const res = await apiClient.post(`/hr/leave-requests/${id}/reject`, { reason });
  return res.data.data;
}

// Undo the decision: back to pending, and any credits the approval consumed are returned.
export async function reopenLeaveRequest(id: number, reason?: string): Promise<LeaveRequest> {
  const res = await apiClient.post(`/hr/leave-requests/${id}/reopen`, { reason });
  return res.data.data;
}
