import { apiClient } from "@/core/api-client";
import type { Department, DepartmentInput } from "@/modules/hr/types/department";

// The list endpoint is paginated: the array is in `data`, pagination in `meta`.
// Seed data is small, so page 1 (per_page 100) is the whole list for MVP.
export async function getDepartments(): Promise<Department[]> {
  const res = await apiClient.get("/hr/departments");
  return res.data.data;
}

export async function createDepartment(
  input: DepartmentInput,
): Promise<Department> {
  const res = await apiClient.post("/hr/departments", input);
  return res.data.data;
}

export async function updateDepartment(
  id: number,
  input: DepartmentInput,
): Promise<Department> {
  const res = await apiClient.put(`/hr/departments/${id}`, input);
  return res.data.data;
}

export async function deleteDepartment(id: number): Promise<void> {
  await apiClient.delete(`/hr/departments/${id}`);
}

export async function archiveDepartment(id: number): Promise<Department> {
  const res = await apiClient.post(`/hr/departments/${id}/archive`);
  return res.data.data;
}

export async function restoreDepartment(id: number): Promise<Department> {
  const res = await apiClient.post(`/hr/departments/${id}/restore`);
  return res.data.data;
}
