import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type {
  Requisition,
  Candidate,
  CandidateEmail,
  CandidateEmailDraft,
} from "@/modules/hr/types/recruitment";

export async function getRequisitions(params: QueueParams): Promise<QueuePage<Requisition>> {
  const res = await apiClient.get("/hr/requisitions", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export interface RequisitionInput {
  title: string;
  employment_type: string | null;
  salary_range: string | null;
  department_id: number;
  hiring_manager_id: number | null;
  openings: number;
  stage: string;
  target_start_date: string | null;
  location: string | null;
  job_description: string | null;
}

export async function createRequisition(
  input: RequisitionInput,
): Promise<Requisition> {
  const res = await apiClient.post("/hr/requisitions", input);
  return res.data.data;
}

export async function updateRequisition(
  id: number,
  input: RequisitionInput,
): Promise<Requisition> {
  const res = await apiClient.put(`/hr/requisitions/${id}`, input);
  return res.data.data;
}

export async function deleteRequisition(id: number): Promise<void> {
  await apiClient.delete(`/hr/requisitions/${id}`);
}

export async function getCandidates(requisitionId?: number): Promise<Candidate[]> {
  const res = await apiClient.get("/hr/candidates", {
    params: requisitionId ? { requisition: requisitionId } : undefined,
  });
  return res.data.data;
}

export interface CandidateInput {
  requisition_id: number;
  name: string;
  email: string | null;
  phone: string | null;
  source: string;
  expected_salary: string | null;
  location: string | null;
  notes: string | null;
  resume: File | null;
}

export async function createCandidate(input: CandidateInput): Promise<Candidate> {
  const form = new FormData();
  form.append("requisition_id", String(input.requisition_id));
  form.append("name", input.name);
  if (input.email) form.append("email", input.email);
  if (input.phone) form.append("phone", input.phone);
  form.append("source", input.source);
  if (input.expected_salary) form.append("expected_salary", input.expected_salary);
  if (input.location) form.append("location", input.location);
  if (input.notes) form.append("notes", input.notes);
  if (input.resume) form.append("resume", input.resume);

  const res = await apiClient.post("/hr/candidates", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data.data;
}

export async function moveCandidate(
  id: number,
  stage: string,
): Promise<Candidate> {
  const res = await apiClient.post(`/hr/candidates/${id}/move`, { stage });
  return res.data.data;
}

export async function hireCandidate(id: number): Promise<Candidate> {
  const res = await apiClient.post(`/hr/candidates/${id}/hire`);
  return res.data.data;
}

/** What the compose modal opens with: the stage template, or the last email of this type. */
export async function getCandidateEmailDraft(
  id: number,
  type: string,
): Promise<CandidateEmailDraft> {
  const res = await apiClient.get(`/hr/candidates/${id}/email/draft`, {
    params: { type },
  });
  return res.data.data;
}

/** Every email sent to this candidate, newest first. */
export async function getCandidateEmails(id: number): Promise<CandidateEmail[]> {
  const res = await apiClient.get(`/hr/candidates/${id}/emails`);
  return res.data.data;
}

/** A composed candidate email, as the modal hands it over. */
export interface CandidateEmailInput {
  type: string;
  to: string;
  cc: string[];
  bcc: string[];
  reply_to: string | null;
  subject: string;
  body_html: string;
  /** Assessment only — the board badge reads it off the candidate. "yyyy-MM-dd". */
  deadline?: string | null;
  attachments: File[];
}

/**
 * Send the composed email. Always multipart, even with no files: a mixed JSON/multipart
 * pair of code paths is one more thing to keep in step for no gain.
 */
export async function sendCandidateEmail(
  id: number,
  input: CandidateEmailInput,
): Promise<{ candidate: Candidate; email: CandidateEmail }> {
  const form = new FormData();
  form.append("type", input.type);
  form.append("to", input.to);
  input.cc.forEach((address) => form.append("cc[]", address));
  input.bcc.forEach((address) => form.append("bcc[]", address));
  if (input.reply_to) form.append("reply_to", input.reply_to);
  form.append("subject", input.subject);
  form.append("body_html", input.body_html);
  if (input.deadline) form.append("deadline", input.deadline);
  input.attachments.forEach((file) => form.append("attachments[]", file));

  const res = await apiClient.post(`/hr/candidates/${id}/email`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data.data;
}

export async function rejectCandidate(
  id: number,
  input: { reason: string; note: string | null },
): Promise<Candidate> {
  const res = await apiClient.post(`/hr/candidates/${id}/reject`, input);
  return res.data.data;
}
