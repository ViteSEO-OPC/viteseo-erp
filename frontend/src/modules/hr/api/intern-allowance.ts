import { apiClient } from "@/core/api-client";
import type {
  InternAllowanceOverview,
  InternAllowanceSaveInput,
  InternAllowanceSettings,
  InternPreview,
  InternPreviewSample,
} from "@/modules/hr/types/intern-allowance";

export async function getInternAllowanceSettings(): Promise<InternAllowanceOverview> {
  const res = await apiClient.get("/hr/intern-allowance-settings");
  return res.data.data;
}

/** A full replacement: every setting is sent. */
export async function saveInternAllowanceSettings(
  input: InternAllowanceSaveInput,
): Promise<InternAllowanceOverview> {
  const res = await apiClient.put("/hr/intern-allowance-settings", input);
  return res.data.data;
}

/** Withdraw a version that has not taken effect yet. */
export async function deleteInternAllowanceVersion(date: string): Promise<InternAllowanceOverview> {
  const res = await apiClient.delete(`/hr/intern-allowance-settings/versions/${date}`);
  return res.data.data;
}

/** Price an invented month under unsaved settings. Writes nothing. */
export async function previewInternAllowance(
  settings: InternAllowanceSettings,
  sample: InternPreviewSample,
): Promise<InternPreview> {
  const res = await apiClient.post("/hr/intern-allowance-settings/preview", { ...settings, sample });
  return res.data.data;
}
