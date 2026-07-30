import { apiClient } from "@/core/api-client";
import type {
  BenefitProgram,
  BenefitProgramInput,
  MyBenefit,
} from "@/modules/hr/types/benefits";

// ---- HR management (hr_officer only) ----

export async function getBenefitPrograms(): Promise<BenefitProgram[]> {
  const res = await apiClient.get("/hr/benefit-programs");
  return res.data.data;
}

export async function createBenefitProgram(
  input: BenefitProgramInput,
): Promise<BenefitProgram> {
  const res = await apiClient.post("/hr/benefit-programs", input);
  return res.data.data;
}

export async function updateBenefitProgram(
  id: number,
  input: BenefitProgramInput,
): Promise<BenefitProgram> {
  const res = await apiClient.put(`/hr/benefit-programs/${id}`, input);
  return res.data.data;
}

export async function deleteBenefitProgram(id: number): Promise<void> {
  await apiClient.delete(`/hr/benefit-programs/${id}`);
}

/** Partial update — used by the Edit Allowance / Edit HMO modals. */
export async function patchBenefitProgram(
  id: number,
  patch: Record<string, unknown>,
): Promise<BenefitProgram> {
  const res = await apiClient.put(`/hr/benefit-programs/${id}`, patch);
  return res.data.data;
}

/** Create a benefit plan (e.g. a new allowance inline from the Assign modal). */
export async function createBenefitPlan(
  payload: Record<string, unknown>,
): Promise<BenefitProgram> {
  const res = await apiClient.post("/hr/benefit-programs", payload);
  return res.data.data;
}

// ---- Employee "My Benefits" (the caller's own enrolled programs) ----

export async function getMyBenefits(): Promise<MyBenefit[]> {
  const res = await apiClient.get("/hr/me/benefit-programs");
  return res.data.data;
}
