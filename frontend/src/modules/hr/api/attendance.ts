import { apiClient } from "@/core/api-client";
import type {
  Attendance,
  DayTask,
  MyAttendance,
  HrAttendanceRow,
  AttendanceSummary,
  Violation,
  DailyOverride,
  LocationFix,
} from "@/modules/hr/types/attendance";

export async function getMyAttendance(): Promise<MyAttendance> {
  const res = await apiClient.get("/hr/me/attendance");
  return res.data.data;
}

/**
 * `fix` is optional on purpose: no location is a legitimate, common case — the geofence is
 * off, the browser denied permission, or the machine can't resolve a position at all. The
 * server decides what an absent reading means; we just don't send one.
 */
export async function clockIn(fix?: LocationFix | null): Promise<Attendance> {
  const res = await apiClient.post("/hr/attendance/clock-in", fix ?? {});
  return res.data.data;
}

export async function clockOut(tasks?: DayTask[]): Promise<Attendance> {
  const res = await apiClient.post("/hr/attendance/clock-out", tasks?.length ? { tasks } : {});
  return res.data.data;
}

export async function toggleBreak(type: "lunch" | "break"): Promise<Attendance> {
  const res = await apiClient.post("/hr/attendance/break", { type });
  return res.data.data;
}

export async function getHrAttendance(date?: string): Promise<HrAttendanceRow[]> {
  const res = await apiClient.get("/hr/attendance", {
    params: date ? { date } : undefined,
  });
  return res.data.data;
}

export async function getAttendanceSummary(date?: string): Promise<AttendanceSummary> {
  const res = await apiClient.get("/hr/attendance/summary", {
    params: date ? { date } : undefined,
  });
  return res.data.data;
}

export async function getViolations(
  employeeId?: number,
  month?: string,
): Promise<Violation[]> {
  const res = await apiClient.get("/hr/violations", {
    params: {
      ...(employeeId ? { employee: employeeId } : {}),
      ...(month ? { month } : {}),
    },
  });
  return res.data.data;
}

/** The caller's own flags. Read-only server-side — never triggers a recompute. */
export async function getMyViolations(month?: string): Promise<Violation[]> {
  const res = await apiClient.get("/hr/me/violations", {
    params: month ? { month } : undefined,
  });
  return res.data.data;
}

export async function resolveViolation(id: number): Promise<Violation> {
  const res = await apiClient.post(`/hr/violations/${id}/resolve`);
  return res.data.data;
}

/** Undo a resolve, putting the flag back in play for the escalation ladder. */
export async function reopenViolation(id: number): Promise<Violation> {
  const res = await apiClient.post(`/hr/violations/${id}/reopen`);
  return res.data.data;
}

export interface AdjustAttendanceInput {
  employee_id: number;
  work_date: string;
  time_in: string | null;
  time_out: string | null;
  /**
   * Slice 16 — null/omitted derives the status from the times (the old behaviour). A value
   * here is an explicit HR override that wins over both the clock and the leave table.
   */
  status?: string | null;
  note: string | null;
}

export async function adjustAttendance(
  input: AdjustAttendanceInput,
): Promise<Attendance> {
  const res = await apiClient.post("/hr/attendance/adjust", input);
  return res.data.data;
}

export async function getDailyOverride(date?: string): Promise<DailyOverride | null> {
  const res = await apiClient.get("/hr/daily-overrides", {
    params: date ? { date } : undefined,
  });
  return res.data.data;
}

export async function upsertDailyOverride(
  date: string,
  graceMinutes: number | null,
): Promise<DailyOverride> {
  const res = await apiClient.put("/hr/daily-overrides", { date, grace_minutes: graceMinutes });
  return res.data.data;
}
