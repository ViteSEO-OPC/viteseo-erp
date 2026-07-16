import { apiClient } from "@/core/api-client";
import type { Concern, Meeting } from "@/modules/hr/types/concern";

// --- Concerns ---

export async function getConcerns(): Promise<Concern[]> {
  const res = await apiClient.get("/hr/concerns");
  return res.data.data;
}

export async function getMyConcerns(): Promise<Concern[]> {
  const res = await apiClient.get("/hr/me/concerns");
  return res.data.data;
}

export async function createConcern(input: {
  category: string;
  description: string;
  is_anonymous: boolean;
}): Promise<Concern> {
  const res = await apiClient.post("/hr/me/concerns", input);
  return res.data.data;
}

export async function setConcernStatus(id: number, status: string): Promise<Concern> {
  const res = await apiClient.post(`/hr/concerns/${id}/status`, { status });
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
