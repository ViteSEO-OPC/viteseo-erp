"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getLeaveTypes,
  createLeaveType,
  updateLeaveType,
  deleteLeaveType,
  getAllotments,
  upsertAllotment,
  getLeaveSetting,
  updateLeaveSetting,
  getSharedAllotments,
  upsertSharedAllotment,
  getEmployeeBalances,
  upsertEmployeeBalance,
  deleteEmployeeBalance,
} from "@/modules/hr/api/leave-config";
import type { AllotmentMode, LeaveTypeInput } from "@/modules/hr/types/leave-config";

const TYPES_KEY = ["hr", "leave-config", "types"] as const;
const ALLOTMENTS_KEY = ["hr", "leave-config", "allotments"] as const;
const SETTINGS_KEY = ["hr", "leave-config", "settings"] as const;
const SHARED_ALLOTMENTS_KEY = ["hr", "leave-config", "shared-allotments"] as const;
const balancesKey = (employeeId: number) =>
  ["hr", "leave-config", "balances", employeeId] as const;

// --- Leave types ---

export function useLeaveTypes() {
  return useQuery({ queryKey: TYPES_KEY, queryFn: getLeaveTypes });
}

export function useCreateLeaveType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: LeaveTypeInput) => createLeaveType(input),
    // A new type must appear in the allotment grid too.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: TYPES_KEY });
      qc.invalidateQueries({ queryKey: ALLOTMENTS_KEY });
    },
  });
}

export function useUpdateLeaveType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: LeaveTypeInput }) =>
      updateLeaveType(id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: TYPES_KEY });
      qc.invalidateQueries({ queryKey: ALLOTMENTS_KEY });
    },
  });
}

export function useDeleteLeaveType() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteLeaveType(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: TYPES_KEY });
      qc.invalidateQueries({ queryKey: ALLOTMENTS_KEY });
    },
  });
}

// --- Allotment defaults ---

export function useAllotments() {
  return useQuery({ queryKey: ALLOTMENTS_KEY, queryFn: getAllotments });
}

export function useUpsertAllotment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: upsertAllotment,
    onSuccess: () => qc.invalidateQueries({ queryKey: ALLOTMENTS_KEY }),
  });
}

// --- Global allotment mode (shared vs not-shared) ---

export function useLeaveSetting() {
  return useQuery({ queryKey: SETTINGS_KEY, queryFn: getLeaveSetting });
}

export function useUpdateLeaveSetting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (mode: AllotmentMode) => updateLeaveSetting(mode),
    // Switching mode backfills balances server-side; refresh both grids.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: SETTINGS_KEY });
      qc.invalidateQueries({ queryKey: SHARED_ALLOTMENTS_KEY });
      qc.invalidateQueries({ queryKey: ALLOTMENTS_KEY });
    },
  });
}

// --- Shared allotment defaults ---

export function useSharedAllotments() {
  return useQuery({ queryKey: SHARED_ALLOTMENTS_KEY, queryFn: getSharedAllotments });
}

export function useUpsertSharedAllotment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: upsertSharedAllotment,
    onSuccess: () => qc.invalidateQueries({ queryKey: SHARED_ALLOTMENTS_KEY }),
  });
}

// --- Per-person balances ---

export function useEmployeeBalances(employeeId: number | null) {
  return useQuery({
    queryKey: employeeId ? balancesKey(employeeId) : ["hr", "leave-config", "balances", "none"],
    queryFn: () => getEmployeeBalances(employeeId as number),
    enabled: employeeId != null,
  });
}

export function useUpsertEmployeeBalance(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { leave_type_id: number; entitled_days: number }) =>
      upsertEmployeeBalance(employeeId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: balancesKey(employeeId) }),
  });
}

export function useDeleteEmployeeBalance(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (balanceId: number) => deleteEmployeeBalance(balanceId),
    onSuccess: () => qc.invalidateQueries({ queryKey: balancesKey(employeeId) }),
  });
}
