"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getWorkSchedules,
  createWorkSchedule,
  updateWorkSchedule,
  deleteWorkSchedule,
  getAttendancePolicy,
  updateAttendancePolicy,
  previewDeductions,
  getEmployeeSchedule,
  updateEmployeeSchedule,
} from "@/modules/hr/api/rules";
import type {
  WorkScheduleInput,
  AttendancePolicy,
  EmployeeScheduleInput,
} from "@/modules/hr/types/rules";

const SCHEDULES_KEY = ["hr", "rules", "schedules"] as const;
const POLICY_KEY = ["hr", "rules", "policy"] as const;
const employeeScheduleKey = (id: number) =>
  ["hr", "rules", "employee-schedule", id] as const;

// --- Work schedules ---

export function useWorkSchedules() {
  return useQuery({ queryKey: SCHEDULES_KEY, queryFn: getWorkSchedules });
}

export function useCreateWorkSchedule() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: WorkScheduleInput) => createWorkSchedule(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULES_KEY }),
  });
}

export function useUpdateWorkSchedule() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: WorkScheduleInput }) =>
      updateWorkSchedule(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULES_KEY }),
  });
}

export function useDeleteWorkSchedule() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteWorkSchedule(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULES_KEY }),
  });
}

// --- Attendance policy ---

export function useAttendancePolicy() {
  return useQuery({ queryKey: POLICY_KEY, queryFn: getAttendancePolicy });
}

export function useUpdateAttendancePolicy() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: Partial<Omit<AttendancePolicy, "id">>) =>
      updateAttendancePolicy(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: POLICY_KEY }),
  });
}

// Dry-run preview — never touches the saved policy, so no cache invalidation.
export function usePreviewDeductions() {
  return useMutation({
    mutationFn: (input: Partial<Omit<AttendancePolicy, "id">>) =>
      previewDeductions(input),
  });
}

// --- Per-employee schedule ---

export function useEmployeeSchedule(employeeId: number | null) {
  return useQuery({
    queryKey: employeeId
      ? employeeScheduleKey(employeeId)
      : ["hr", "rules", "employee-schedule", "none"],
    queryFn: () => getEmployeeSchedule(employeeId as number),
    enabled: employeeId != null,
  });
}

export function useUpdateEmployeeSchedule(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: EmployeeScheduleInput) =>
      updateEmployeeSchedule(employeeId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: employeeScheduleKey(employeeId) }),
  });
}
