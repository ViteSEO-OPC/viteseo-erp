import { apiClient } from "@/core/api-client";
import type { LeaveType, LeaveBalance } from "@/modules/hr/types/leave";
import type {
  LeaveTypeAllotment,
  LeaveTypeInput,
} from "@/modules/hr/types/leave-config";

// --- Leave types ---

export async function getLeaveTypes(): Promise<LeaveType[]> {
  const res = await apiClient.get("/hr/leave-types");
  return res.data.data;
}

export async function createLeaveType(input: LeaveTypeInput): Promise<LeaveType> {
  const res = await apiClient.post("/hr/leave-types", input);
  return res.data.data;
}

export async function updateLeaveType(
  id: number,
  input: LeaveTypeInput,
): Promise<LeaveType> {
  const res = await apiClient.put(`/hr/leave-types/${id}`, input);
  return res.data.data;
}

export async function deleteLeaveType(id: number): Promise<void> {
  await apiClient.delete(`/hr/leave-types/${id}`);
}

// --- Allotment defaults (per employment status) ---

export async function getAllotments(): Promise<LeaveTypeAllotment[]> {
  const res = await apiClient.get("/hr/leave-type-allotments");
  return res.data.data;
}

export async function upsertAllotment(input: {
  leave_type_id: number;
  employment_status: string;
  days_per_year: number;
}): Promise<LeaveTypeAllotment> {
  const res = await apiClient.post("/hr/leave-type-allotments", input);
  return res.data.data;
}

// --- Per-person balances (the editable override) ---

export async function getEmployeeBalances(
  employeeId: number,
): Promise<LeaveBalance[]> {
  const res = await apiClient.get(`/hr/employees/${employeeId}/leave-balances`);
  return res.data.data;
}

export async function upsertEmployeeBalance(
  employeeId: number,
  input: { leave_type_id: number; entitled_days: number },
): Promise<LeaveBalance> {
  const res = await apiClient.put(
    `/hr/employees/${employeeId}/leave-balances`,
    input,
  );
  return res.data.data;
}

export async function deleteEmployeeBalance(balanceId: number): Promise<void> {
  await apiClient.delete(`/hr/leave-balances/${balanceId}`);
}
