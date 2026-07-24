import { apiClient } from "@/core/api-client";
import type {
  WorkSchedule,
  WorkScheduleInput,
  AttendancePolicy,
  DeductionPreview,
  EmployeeSchedule,
  EmployeeScheduleInput,
} from "@/modules/hr/types/rules";

// --- Work schedules ---

export async function getWorkSchedules(): Promise<WorkSchedule[]> {
  const res = await apiClient.get("/hr/work-schedules");
  return res.data.data;
}

export async function createWorkSchedule(input: WorkScheduleInput): Promise<WorkSchedule> {
  const res = await apiClient.post("/hr/work-schedules", input);
  return res.data.data;
}

export async function updateWorkSchedule(
  id: number,
  input: WorkScheduleInput,
): Promise<WorkSchedule> {
  const res = await apiClient.put(`/hr/work-schedules/${id}`, input);
  return res.data.data;
}

export async function deleteWorkSchedule(id: number): Promise<void> {
  await apiClient.delete(`/hr/work-schedules/${id}`);
}

// --- Attendance policy ---

export async function getAttendancePolicy(): Promise<AttendancePolicy> {
  const res = await apiClient.get("/hr/attendance-policy");
  return res.data.data;
}

export async function updateAttendancePolicy(
  input: Partial<Omit<AttendancePolicy, "id">>,
): Promise<AttendancePolicy> {
  const res = await apiClient.put("/hr/attendance-policy", input);
  return res.data.data;
}

// Dry-run: what the PROPOSED policy would have deducted last period (nothing saved).
export async function previewDeductions(
  input: Partial<Omit<AttendancePolicy, "id">>,
): Promise<DeductionPreview> {
  const res = await apiClient.post("/hr/attendance-policy/preview", input);
  return res.data.data;
}

// --- Per-employee schedule ---

export async function getEmployeeSchedule(
  employeeId: number,
): Promise<EmployeeSchedule> {
  const res = await apiClient.get(`/hr/employees/${employeeId}/schedule`);
  return res.data.data;
}

export async function updateEmployeeSchedule(
  employeeId: number,
  input: EmployeeScheduleInput,
): Promise<EmployeeSchedule> {
  const res = await apiClient.put(`/hr/employees/${employeeId}/schedule`, input);
  return res.data.data;
}
