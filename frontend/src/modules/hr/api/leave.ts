import { apiClient } from "@/core/api-client";
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
export async function getLeaveRequests(): Promise<LeaveRequest[]> {
  const res = await apiClient.get("/hr/leave-requests");
  return res.data.data;
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
