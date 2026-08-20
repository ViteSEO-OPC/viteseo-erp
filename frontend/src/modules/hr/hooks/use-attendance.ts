"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getMyAttendance,
  clockIn,
  clockOut,
  toggleBreak,
  getHrAttendance,
  adjustAttendance,
  getAttendanceSummary,
  getOpenShifts,
  getViolations,
  getMyViolations,
  resolveViolation,
  reopenViolation,
  getDailyOverride,
  upsertDailyOverride,
} from "@/modules/hr/api/attendance";
import type { DayTask, LocationFix } from "@/modules/hr/types/attendance";

const MY_KEY = ["hr", "me", "attendance"] as const;

export function useMyAttendance() {
  return useQuery({ queryKey: MY_KEY, queryFn: getMyAttendance });
}

// Every clock action refetches the panel's data (today + week) — that's how the
// dot, buttons, and stats update after each press.
export function useClockIn() {
  const qc = useQueryClient();
  return useMutation({
    // Optional location — omitted entirely when the geofence is off, or when the browser
    // couldn't produce a fix. Both are normal; the server treats them the same.
    mutationFn: (fix?: LocationFix | null) => clockIn(fix),
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_KEY }),
  });
}

export function useClockOut() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (tasks?: DayTask[]) => clockOut(tasks),
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_KEY }),
  });
}

export function useToggleBreak() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (type: "lunch" | "break") => toggleBreak(type),
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_KEY }),
  });
}

export function useHrAttendance(date?: string) {
  return useQuery({
    queryKey: ["hr", "attendance", date ?? "today"],
    queryFn: () => getHrAttendance(date),
    refetchInterval: 60_000,
  });
}

export function useAdjustAttendance() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: adjustAttendance,
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "attendance"] }),
  });
}

/** The stale-attendance review queue (item 6). */
export function useOpenShifts(from?: string, to?: string) {
  return useQuery({
    queryKey: ["hr", "attendance", "open-shifts", from ?? "", to ?? ""],
    queryFn: () => getOpenShifts(from, to),
  });
}

export function useAttendanceSummary(date?: string) {
  return useQuery({
    queryKey: ["hr", "attendance-summary", date ?? "today"],
    queryFn: () => getAttendanceSummary(date),
    refetchInterval: 60_000,
  });
}

// A specific employee's violations for a given month (for the flag detail dialog).
// `month` is 'YYYY-MM'; omitting it returns all months (unused by the dialog now).
export function useViolations(employeeId: number | null, month?: string) {
  return useQuery({
    queryKey: ["hr", "violations", employeeId ?? "none", month ?? "all"],
    queryFn: () => getViolations(employeeId as number, month),
    enabled: employeeId != null,
  });
}

/** The signed-in employee's own violations (the dashboard alert banner). */
export function useMyViolations(month?: string) {
  return useQuery({
    queryKey: ["hr", "me", "violations", month ?? "all"],
    queryFn: () => getMyViolations(month),
  });
}

export function useResolveViolation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => resolveViolation(id),
    // A resolved flag changes the roster too, so refresh both.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "violations"] });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}

export function useReopenViolation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => reopenViolation(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "violations"] });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}

export function useDailyOverride(date?: string) {
  return useQuery({
    queryKey: ["hr", "daily-override", date ?? "today"],
    queryFn: () => getDailyOverride(date),
    refetchInterval: 60_000,
  });
}

export function useUpsertDailyOverride() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      date,
      graceMinutes,
      minFullDayMinutes,
    }: {
      date: string;
      graceMinutes: number | null;
      minFullDayMinutes: number | null;
    }) => upsertDailyOverride(date, graceMinutes, minFullDayMinutes),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "daily-override"] });
      qc.invalidateQueries({ queryKey: ["hr", "attendance-summary"] });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
      // The rule the employee panel prints comes from `me/attendance`, so changing it here
      // has to refresh that too — an HR officer is an employee with a clock panel as well.
      qc.invalidateQueries({ queryKey: MY_KEY });
    },
  });
}
