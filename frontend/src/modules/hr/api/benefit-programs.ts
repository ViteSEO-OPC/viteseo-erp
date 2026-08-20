import { apiClient } from "@/core/api-client";
import type {
  BenefitProgram,
  BenefitProgramInput,
  EmployeeAllowance,
  MyBenefit,
  PlanEnrollments,
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

// ---- Company-plan enrolment ----

/** Who is on this plan, and who its rule allows but is not on it yet. */
export async function getPlanEnrollments(programId: number): Promise<PlanEnrollments> {
  const res = await apiClient.get(`/hr/benefit-programs/${programId}/enrollments`);
  return res.data;
}

/**
 * Enrol people, or change a tier / share / dates. Rows the plan's rule does not cover come back
 * in `rejected` rather than failing the whole call — one ineligible person should not undo the
 * other seven.
 */
export async function updatePlanEnrollments(
  programId: number,
  enrollments: {
    employee_id: number;
    tier?: string | null;
    employee_share?: number | null;
    enrolled_at?: string | null;
    ended_at?: string | null;
  }[],
): Promise<PlanEnrollments> {
  const res = await apiClient.put(`/hr/benefit-programs/${programId}/enrollments`, {
    enrollments,
  });
  return res.data;
}

/** End someone's enrolment, dated — the row survives so past payslips still explain themselves. */
export async function endPlanEnrollment(
  programId: number,
  employeeId: number,
): Promise<PlanEnrollments> {
  const res = await apiClient.delete(
    `/hr/benefit-programs/${programId}/enrollments/${employeeId}`,
  );
  return res.data;
}

// ---- One employee's own allowance amounts ----

/**
 * What this employee is paid, allowance by allowance. Built from the catalogue server-side, so a
 * new allowance appears here with no code change.
 */
export async function getEmployeeAllowances(
  employeeId: number,
): Promise<EmployeeAllowance[]> {
  const res = await apiClient.get(`/hr/employees/${employeeId}/allowances`);
  return res.data.data;
}

/** `amount: null` hands the employee back to the programme's amount. */
export async function setEmployeeAllowance(
  employeeId: number,
  programId: number,
  amount: number | null,
): Promise<EmployeeAllowance[]> {
  const res = await apiClient.put(
    `/hr/employees/${employeeId}/allowances/${programId}`,
    { amount },
  );
  return res.data.data;
}

// ---- Employee "My Benefits" (the caller's own enrolled programs) ----

export async function getMyBenefits(): Promise<MyBenefit[]> {
  const res = await apiClient.get("/hr/me/benefit-programs");
  return res.data.data;
}
