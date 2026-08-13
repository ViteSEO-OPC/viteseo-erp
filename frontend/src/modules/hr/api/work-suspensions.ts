import { apiClient } from "@/core/api-client";
import type { WorkSuspension, WorkSuspensionInput } from "@/modules/hr/types/holiday";

/**
 * The declaration plus, when present, the sentence saying a payroll run has already priced
 * this date without knowing about it. The warning is about the PERIOD rather than the row, so
 * it travels separately — a caller that drops it leaves figures quietly wrong.
 */
export interface WorkSuspensionResult {
  suspension: WorkSuspension;
  warning: string | null;
}

export async function getWorkSuspensions(): Promise<WorkSuspension[]> {
  const res = await apiClient.get("/hr/work-suspensions");
  return res.data.data;
}

export async function declareWorkSuspension(
  input: WorkSuspensionInput,
): Promise<WorkSuspensionResult> {
  const res = await apiClient.post("/hr/work-suspensions", input);
  return { suspension: res.data.data, warning: res.data.meta?.warning ?? null };
}

/** Amend one — in practice, flipping paid ⇄ unpaid. The date cannot be moved. */
export async function updateWorkSuspension(
  id: number,
  input: Partial<Omit<WorkSuspensionInput, "date">>,
): Promise<WorkSuspensionResult> {
  const res = await apiClient.put(`/hr/work-suspensions/${id}`, input);
  return { suspension: res.data.data, warning: res.data.meta?.warning ?? null };
}

/** Withdraw it — the day goes back to being an ordinary workday. */
export async function deleteWorkSuspension(id: number): Promise<void> {
  await apiClient.delete(`/hr/work-suspensions/${id}`);
}
