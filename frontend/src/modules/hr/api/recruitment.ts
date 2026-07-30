import { apiClient } from "@/core/api-client";
import type { Requisition, Candidate } from "@/modules/hr/types/recruitment";

export async function getRequisitions(): Promise<Requisition[]> {
  const res = await apiClient.get("/hr/requisitions");
  return res.data.data;
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

/** What HR composes for an assessment email; ignored by the templated types. */
export interface AssessmentCompose {
  link?: string | null;
  instructions?: string | null;
  /** "yyyy-MM-dd" */
  deadline?: string | null;
}

/**
 * Send a stage-specific email (online/final interview invite, assessment, offer,
 * rejection). `compose` applies to the assessment email, which HR writes.
 */
export async function sendCandidateEmail(
  id: number,
  type: string,
  compose?: AssessmentCompose,
): Promise<Candidate> {
  const res = await apiClient.post(`/hr/candidates/${id}/email`, { type, ...compose });
  return res.data.data;
}

export async function rejectCandidate(
  id: number,
  input: { reason: string; note: string | null; send_email: boolean },
): Promise<Candidate> {
  const res = await apiClient.post(`/hr/candidates/${id}/reject`, input);
  return res.data.data;
}
