import { apiClient } from "@/core/api-client";
import type { LocationFix, RemoteCheckin } from "@/modules/hr/types/attendance";

/**
 * File an off-site time-in. Multipart so the selfie rides along; axios sets the boundary
 * itself for a FormData body.
 */
export async function submitRemoteCheckin(
  photo: File,
  fix?: LocationFix | null,
): Promise<RemoteCheckin> {
  const form = new FormData();
  form.append("photo", photo);
  if (fix) {
    form.append("lat", String(fix.lat));
    form.append("lng", String(fix.lng));
  }

  const res = await apiClient.post("/hr/attendance/remote-checkin", form);
  return res.data.data;
}

/** The caller's newest submission, or null if they've never made one. */
export async function getMyRemoteCheckin(): Promise<RemoteCheckin | null> {
  const res = await apiClient.get("/hr/me/remote-checkin");
  return res.data.data;
}

/** HR's queue. `status` of "all" returns the whole history. */
export async function getRemoteCheckins(status = "pending"): Promise<RemoteCheckin[]> {
  const res = await apiClient.get("/hr/attendance/remote-checkins", { params: { status } });
  return res.data.data;
}

export async function approveRemoteCheckin(id: number): Promise<RemoteCheckin> {
  const res = await apiClient.post(`/hr/attendance/remote-checkins/${id}/approve`);
  return res.data.data;
}

export async function rejectRemoteCheckin(id: number, note: string): Promise<RemoteCheckin> {
  const res = await apiClient.post(`/hr/attendance/remote-checkins/${id}/reject`, { note });
  return res.data.data;
}
