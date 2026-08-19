import { apiClient } from "@/core/api-client";
import type {
  AutoOvertimeReviewItem,
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

/**
 * FIN-039 — auto-mode overtime the policy caps held back, awaiting an approval. HR only.
 *
 * Derived from attendance on every read, so there is nothing to resolve here: an item disappears
 * when an overtime request covering that date is APPROVED through the ordinary queue, which is also
 * what releases the minutes for payment.
 */
export async function getAutoOvertimeReview(
  start: string,
  end: string,
): Promise<AutoOvertimeReviewItem[]> {
  const res = await apiClient.get("/hr/overtime-requests/auto-review", {
    params: { start, end },
  });
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

/**
 * Item 10 — HR files overtime for an employee on a date that has already passed. HR only.
 *
 * Lands PENDING, not approved: the ordinary approve action still has to rule on it, which is what
 * keeps the audit entry and the settled-period retro flag in the path.
 */
export async function createOvertimeRequestForEmployee(input: {
  employee_id: number;
  work_date: string;
  time_from: string;
  time_to: string;
  reason: string;
}): Promise<OvertimeRequest> {
  const res = await apiClient.post("/hr/overtime-requests/for-employee", input);
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

/** HR undoes an approved (or pending) request — Slice 6. Reason is required. */
export async function cancelOvertimeRequest(
  id: number,
  reason: string,
): Promise<OvertimeRequest> {
  const res = await apiClient.post(`/hr/overtime-requests/${id}/cancel`, { reason });
  return res.data.data;
}

/** Back to pending for a fresh decision — unlike cancel, the request stays live. */
export async function reopenOvertimeRequest(id: number, reason?: string): Promise<OvertimeRequest> {
  const res = await apiClient.post(`/hr/overtime-requests/${id}/reopen`, { reason });
  return res.data.data;
}
