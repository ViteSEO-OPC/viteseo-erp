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
  getViolations,
  getMyViolations,
  resolveViolation,
  getDailyOverride,
  upsertDailyOverride,
} from "@/modules/hr/api/attendance";
import type { DayTask } from "@/modules/hr/types/attendance";

const MY_KEY = ["hr", "me", "attendance"] as const;

export function useMyAttendance() {
  return useQuery({ queryKey: MY_KEY, queryFn: getMyAttendance });
}

// Every clock action refetches the panel's data (today + week) — that's how the
// dot, buttons, and stats update after each press.
export function useClockIn() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: clockIn,
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
    mutationFn: ({ date, graceMinutes }: { date: string; graceMinutes: number | null }) =>
      upsertDailyOverride(date, graceMinutes),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "daily-override"] });
      qc.invalidateQueries({ queryKey: ["hr", "attendance-summary"] });
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
    },
  });
}
