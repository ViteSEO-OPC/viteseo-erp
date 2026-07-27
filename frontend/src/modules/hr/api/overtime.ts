import { apiClient } from "@/core/api-client";
import type {
  OvertimeClassification,
  OvertimeRequest,
  OvertimeSettings,
} from "@/modules/hr/types/leave";

/** Whether overtime can be REQUESTED (only in "approved" mode) + the note to show if not. */
export async function getOvertimeSettings(): Promise<OvertimeSettings> {
  const res = await apiClient.get("/hr/overtime-requests/settings");
  return res.data.data;
}

export async function getOvertimeRequests(): Promise<OvertimeRequest[]> {
  const res = await apiClient.get("/hr/overtime-requests");
  return res.data.data;
}

/** How a date's overtime would be classified + its rate — display only; payroll re-derives it. */
export async function classifyOvertime(date: string): Promise<OvertimeClassification> {
  const res = await apiClient.get("/hr/overtime-requests/classify", { params: { date } });
  return res.data.data;
}

export async function createOvertimeRequest(input: {
  work_date: string;
  time_from: string;
  time_to: string;
  reason: string;
}): Promise<OvertimeRequest> {
  const res = await apiClient.post("/hr/overtime-requests", input);
  return res.data.data;
}

export async function approveOvertimeRequest(id: number): Promise<OvertimeRequest> {
  const res = await apiClient.post(`/hr/overtime-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectOvertimeRequest(
  id: number,
  reason: string,
): Promise<OvertimeRequest> {
  const res = await apiClient.post(`/hr/overtime-requests/${id}/reject`, { reason });
  return res.data.data;
}
