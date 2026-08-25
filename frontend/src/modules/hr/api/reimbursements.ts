import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type { Reimbursement, ReimbursementSummary } from "@/modules/hr/types/reimbursement";

export async function getReimbursements(params: QueueParams): Promise<QueuePage<Reimbursement>> {
  const res = await apiClient.get("/hr/reimbursements", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

/** Counts and peso totals per status, for the cards above the finance queue. HR only. */
export async function getReimbursementSummary(): Promise<ReimbursementSummary> {
  const res = await apiClient.get("/hr/reimbursements/summary");
  return res.data.data;
}

// Sent as multipart/form-data so the optional receipt file can ride along.
// (Axios sets the multipart boundary automatically for a FormData body.)
export async function createReimbursement(
  form: FormData,
): Promise<Reimbursement> {
  const res = await apiClient.post("/hr/reimbursements", form);
  return res.data.data;
}

export async function approveReimbursement(id: number): Promise<Reimbursement> {
  const res = await apiClient.post(`/hr/reimbursements/${id}/approve`);
  return res.data.data;
}

export async function rejectReimbursement(
  id: number,
  reason: string,
): Promise<Reimbursement> {
  const res = await apiClient.post(`/hr/reimbursements/${id}/reject`, { reason });
  return res.data.data;
}

export async function markReimbursementPaid(id: number): Promise<Reimbursement> {
  const res = await apiClient.post(`/hr/reimbursements/${id}/mark-paid`);
  return res.data.data;
}

/** Undo the last mark, one step: paid → approved (still owed), decided → pending. */
export async function reopenReimbursement(id: number, reason?: string): Promise<Reimbursement> {
  const res = await apiClient.post(`/hr/reimbursements/${id}/reopen`, { reason });
  return res.data.data;
}

/** The employee withdraws their own claim while it is still pending. Reason required. */
export async function cancelReimbursement(id: number, reason: string): Promise<Reimbursement> {
  const res = await apiClient.post(`/hr/reimbursements/${id}/cancel`, { reason });
  return res.data.data;
}
