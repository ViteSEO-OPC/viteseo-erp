import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type { Concern, ConcernRevealRequest, Meeting } from "@/modules/hr/types/concern";

// --- Concerns ---

export async function getConcerns(params: QueueParams): Promise<QueuePage<Concern>> {
  const res = await apiClient.get("/hr/concerns", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export async function getMyConcerns(params: QueueParams): Promise<QueuePage<Concern>> {
  const res = await apiClient.get("/hr/me/concerns", { params: queueQuery(params) });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

// Sent as multipart/form-data so supporting documents can ride along.
// (Axios sets the multipart boundary automatically for a FormData body.)
export async function createConcern(form: FormData): Promise<Concern> {
  const res = await apiClient.post("/hr/me/concerns", form);
  return res.data.data;
}

export async function setConcernStatus(id: number, status: string): Promise<Concern> {
  const res = await apiClient.post(`/hr/concerns/${id}/status`, { status });
  return res.data.data;
}

// Steps the status back one: resolved → under review, under review → pending.
export async function reopenConcern(id: number, reason?: string): Promise<Concern> {
  const res = await apiClient.post(`/hr/concerns/${id}/reopen`, { reason });
  return res.data.data;
}

export async function scheduleMeeting(
  concernId: number,
  input: { scheduled_at: string; location: string | null; agenda: string | null },
): Promise<Meeting> {
  const res = await apiClient.post(`/hr/concerns/${concernId}/meetings`, input);
  return res.data.data;
}

/** Schedule a meeting HR sets directly with an employee (e.g. about attendance). */
export async function scheduleEmployeeMeeting(
  employeeId: number,
  input: { scheduled_at: string; location: string | null; agenda: string | null },
): Promise<Meeting> {
  const res = await apiClient.post(`/hr/employees/${employeeId}/meetings`, input);
  return res.data.data;
}

// --- Meeting invites (employee) ---

export async function getMyMeetings(): Promise<Meeting[]> {
  const res = await apiClient.get("/hr/me/meetings");
  return res.data.data;
}

export async function respondMeeting(
  id: number,
  response: "accepted" | "declined",
): Promise<Meeting> {
  const res = await apiClient.post(`/hr/me/meetings/${id}/respond`, { response });
  return res.data.data;
}

/** The employee withdraws their own pending concern. Reason required. */
export async function cancelConcern(id: number, reason: string): Promise<Concern> {
  const res = await apiClient.post(`/hr/me/concerns/${id}/cancel`, { reason });
  return res.data.data;
}

// --- Identity reveal on an anonymous concern -------------------------------------------------

/**
 * HR asks who filed this. Asking only — the identity is not emitted until the reporter accepts.
 * The reason is required and has a minimum length server-side: the reporter is being asked to
 * give up the one protection they chose, and they cannot weigh "need to know".
 */
export async function requestConcernReveal(
  concernId: number,
  reason: string,
): Promise<ConcernRevealRequest> {
  const res = await apiClient.post(`/hr/concerns/${concernId}/reveal-requests`, { reason });
  return res.data.data;
}

/** The reporter agrees to be named. Only this makes an identity visible to HR. */
export async function acceptConcernReveal(id: number): Promise<ConcernRevealRequest> {
  const res = await apiClient.post(`/hr/me/concern-reveal-requests/${id}/accept`);
  return res.data.data;
}

/** The reporter says no, and says why. HR sees the reason instead of the name. */
export async function declineConcernReveal(
  id: number,
  reason: string,
): Promise<ConcernRevealRequest> {
  const res = await apiClient.post(`/hr/me/concern-reveal-requests/${id}/decline`, { reason });
  return res.data.data;
}
