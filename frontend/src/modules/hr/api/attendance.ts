import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type {
  Attendance,
  DayTask,
  MyAttendance,
  HrAttendanceRow,
  AttendanceSummary,
  Violation,
  DailyOverride,
  LocationFix,
  OpenShiftRow,
  ManagementGenerationStatus,
  ManagementGenerationResult,
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

/**
 * ONE PAGE of the roster for a date.
 *
 * `status` and `timing` reach the SERVER even though neither is a column — they are derived per
 * row from the employee's schedule and the day, and the server filters after deriving them.
 * Applying either here would have narrowed the twenty-five rows already on screen.
 */
export async function getHrAttendance(
  date: string | undefined,
  params: QueueParams & { timing?: string },
): Promise<QueuePage<HrAttendanceRow>> {
  const res = await apiClient.get("/hr/attendance", {
    params: {
      ...queueQuery(params),
      ...(date ? { date } : {}),
      ...(params.timing && params.timing !== "all" ? { timing: params.timing } : {}),
    },
  });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export async function getAttendanceSummary(date?: string): Promise<AttendanceSummary> {
  const res = await apiClient.get("/hr/attendance/summary", {
    params: date ? { date } : undefined,
  });
  return res.data.data;
}

export async function getOpenShifts(
  from: string | undefined,
  to: string | undefined,
  params: QueueParams,
): Promise<QueuePage<OpenShiftRow>> {
  const res = await apiClient.get("/hr/attendance/open-shifts", {
    params: {
      ...queueQuery(params),
      from: from || undefined,
      to: to || undefined,
    },
  });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

export async function getViolations(
  employeeId?: number,
  month?: string,
  params?: QueueParams,
): Promise<QueuePage<Violation>> {
  const res = await apiClient.get("/hr/violations", {
    params: {
      ...(employeeId ? { employee: employeeId } : {}),
      ...(month ? { month } : {}),
      ...(params ? queueQuery(params) : {}),
    },
  });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

/** The caller's own flags. Read-only server-side — never triggers a recompute. */
/**
 * The caller's own flags for a month.
 *
 * `per_page` is passed explicitly and generously: the dashboard banner picks the WORST
 * unresolved flag, and severity is not the sort key — so the one it needs could sit on any page.
 * One employee, one month, is at most a few dozen rows, which is what makes asking for all of
 * them the right answer here rather than a cap in disguise.
 */
export async function getMyViolations(month?: string): Promise<Violation[]> {
  const res = await apiClient.get("/hr/me/violations", {
    params: { ...(month ? { month } : {}), per_page: 100 },
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
   * null/omitted derives the status from the times (the old behaviour). A value
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

/**
 * PUT, not PATCH: the two rules are one record and one decision, so the dialog always sends
 * both. A null field clears that rule; both null removes the day's row entirely.
 *
 * `date` may be in the past — that is the point of the feature, since a typhoon morning is
 * known only after people have clocked in. A past date inside a completed payroll run is
 * refused once with the periods named; resend with `acknowledgeRepricing` to go ahead.
 */
export async function upsertDailyOverride(
  date: string,
  graceMinutes: number | null,
  minFullDayMinutes: number | null,
  acknowledgeRepricing = false,
): Promise<DailyOverride> {
  const res = await apiClient.put("/hr/daily-overrides", {
    date,
    grace_minutes: graceMinutes,
    min_full_day_minutes: minFullDayMinutes,
    ...(acknowledgeRepricing ? { acknowledge_repricing: true } : {}),
  });
  return res.data.data;
}

/** Did the nightly management-attendance job actually run? */
export async function getManagementGeneration(): Promise<ManagementGenerationStatus> {
  const res = await apiClient.get("/hr/attendance/management-generation");
  return res.data.data;
}

/** Run it now. Omitting the range covers the last seven days, as the nightly does. */
export async function regenerateManagementAttendance(range?: {
  from?: string;
  to?: string;
}): Promise<ManagementGenerationResult> {
  const res = await apiClient.post("/hr/attendance/management-generation", range ?? {});
  return res.data.data;
}
