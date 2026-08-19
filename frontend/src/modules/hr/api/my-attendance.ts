import { apiClient } from "@/core/api-client";
import type { MyAttendanceReport } from "@/modules/hr/types/my-attendance";

/**
 * The caller's OWN attendance for a month. No employee id is sent — the server resolves it from
 * the token, which is the only thing that makes this endpoint safe to expose to employees.
 */
export async function getMyAttendanceReport(month?: string): Promise<MyAttendanceReport> {
  const res = await apiClient.get("/hr/me/attendance-report", {
    params: month ? { month } : undefined,
  });
  return res.data.data;
}
