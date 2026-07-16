import { apiClient } from "@/core/api-client";
import type {
  DocumentRequest,
  ChangeRequest,
  PayslipRequest,
  PayrollInquiry,
} from "@/modules/hr/types/request";

// --- Document requests ---

export async function getDocumentRequests(): Promise<DocumentRequest[]> {
  const res = await apiClient.get("/hr/document-requests");
  return res.data.data;
}

export async function getMyDocumentRequests(): Promise<DocumentRequest[]> {
  const res = await apiClient.get("/hr/me/document-requests");
  return res.data.data;
}

export async function createDocumentRequest(input: {
  type?: string;
  employee_document_id?: number;
  other_detail?: string | null;
  reason?: string | null;
}): Promise<DocumentRequest> {
  const res = await apiClient.post("/hr/me/document-requests", input);
  return res.data.data;
}

export async function fulfillDocumentRequest(
  id: number,
  note?: string,
): Promise<DocumentRequest> {
  const res = await apiClient.post(`/hr/document-requests/${id}/fulfill`, { note });
  return res.data.data;
}

export async function rejectDocumentRequest(
  id: number,
  reason: string,
): Promise<DocumentRequest> {
  const res = await apiClient.post(`/hr/document-requests/${id}/reject`, { reason });
  return res.data.data;
}

// --- Change requests ---

export async function getChangeRequests(): Promise<ChangeRequest[]> {
  const res = await apiClient.get("/hr/change-requests");
  return res.data.data;
}

export async function getMyChangeRequests(): Promise<ChangeRequest[]> {
  const res = await apiClient.get("/hr/me/change-requests");
  return res.data.data;
}

// Submit several field changes at once — each becomes its own request HR reviews.
export async function createChangeRequest(input: {
  changes: { field: string; new_value: string }[];
}): Promise<ChangeRequest[]> {
  const res = await apiClient.post("/hr/me/change-requests", input);
  return res.data.data;
}

export async function approveChangeRequest(id: number): Promise<ChangeRequest> {
  const res = await apiClient.post(`/hr/change-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectChangeRequest(
  id: number,
  reason: string,
): Promise<ChangeRequest> {
  const res = await apiClient.post(`/hr/change-requests/${id}/reject`, { reason });
  return res.data.data;
}

// --- Payslip requests ---

export async function getPayslipRequests(): Promise<PayslipRequest[]> {
  const res = await apiClient.get("/hr/payslip-requests");
  return res.data.data;
}

export async function getMyPayslipRequests(): Promise<PayslipRequest[]> {
  const res = await apiClient.get("/hr/me/payslip-requests");
  return res.data.data;
}

export async function createPayslipRequest(input: {
  reason: string;
  payslip_id?: number | null;
}): Promise<PayslipRequest> {
  const res = await apiClient.post("/hr/me/payslip-requests", input);
  return res.data.data;
}

export async function approvePayslipRequest(id: number): Promise<PayslipRequest> {
  const res = await apiClient.post(`/hr/payslip-requests/${id}/approve`);
  return res.data.data;
}

export async function rejectPayslipRequest(id: number, reason: string): Promise<PayslipRequest> {
  const res = await apiClient.post(`/hr/payslip-requests/${id}/reject`, { reason });
  return res.data.data;
}

// --- Payroll inquiries ---

export async function getPayrollInquiries(): Promise<PayrollInquiry[]> {
  const res = await apiClient.get("/hr/payroll-inquiries");
  return res.data.data;
}

export async function getMyPayrollInquiries(): Promise<PayrollInquiry[]> {
  const res = await apiClient.get("/hr/me/payroll-inquiries");
  return res.data.data;
}

export async function createPayrollInquiry(input: {
  subject: string;
  topic: string;
  message: string;
  payslip_id?: number | null;
  attachment?: File | null;
}): Promise<PayrollInquiry> {
  // Multipart because an inquiry can carry an optional payslip screenshot.
  const fd = new FormData();
  fd.append("subject", input.subject);
  fd.append("topic", input.topic);
  fd.append("message", input.message);
  if (input.payslip_id != null) fd.append("payslip_id", String(input.payslip_id));
  if (input.attachment) fd.append("attachment", input.attachment);
  const res = await apiClient.post("/hr/me/payroll-inquiries", fd);
  return res.data.data;
}

export async function progressPayrollInquiry(id: number): Promise<PayrollInquiry> {
  const res = await apiClient.post(`/hr/payroll-inquiries/${id}/progress`);
  return res.data.data;
}

export async function resolvePayrollInquiry(id: number, note?: string): Promise<PayrollInquiry> {
  const res = await apiClient.post(`/hr/payroll-inquiries/${id}/resolve`, { note });
  return res.data.data;
}
