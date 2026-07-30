import { apiClient } from "@/core/api-client";
import type { Reimbursement } from "@/modules/hr/types/reimbursement";

export async function getReimbursements(): Promise<Reimbursement[]> {
  const res = await apiClient.get("/hr/reimbursements");
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
