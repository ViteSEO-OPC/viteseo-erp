import { apiClient } from "@/core/api-client";
import type { HrDashboardStats } from "@/modules/hr/types/dashboard";

export async function getHrDashboard(): Promise<HrDashboardStats> {
  const res = await apiClient.get("/hr/dashboard/stats");
  return res.data.data;
}
