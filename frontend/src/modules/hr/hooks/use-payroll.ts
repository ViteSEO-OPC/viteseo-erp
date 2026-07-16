"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getPayrollRuns,
  getPayrollRun,
  runPayroll,
  markPayslipDone,
  getMyNextPayday,
  getMyPayslips,
  getPayslip,
  getPayrollSettings,
  getThirteenthMonth,
  updatePayrollSettings,
} from "@/modules/hr/api/payroll";

const RUNS_KEY = ["hr", "payroll", "runs"] as const;
const SETTINGS_KEY = ["hr", "payroll", "settings"] as const;

export function usePayrollSettings() {
  return useQuery({ queryKey: SETTINGS_KEY, queryFn: getPayrollSettings });
}

export function useUpdatePayrollSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: updatePayrollSettings,
    onSuccess: () => qc.invalidateQueries({ queryKey: SETTINGS_KEY }),
  });
}

export function usePayrollRuns() {
  return useQuery({ queryKey: RUNS_KEY, queryFn: getPayrollRuns });
}

export function usePayrollRun(id: number | undefined) {
  return useQuery({
    queryKey: ["hr", "payroll", "run", id],
    queryFn: () => getPayrollRun(id!),
    enabled: !!id,
  });
}

export function useRunPayroll() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: runPayroll,
    onSuccess: () => {
      // Refresh the runs list, the open breakdown detail (a re-run recomputes it),
      // and settings/pay-groups.
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: SETTINGS_KEY });
    },
  });
}

export function useMarkPayslipDone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => markPayslipDone(id),
    onSuccess: () => {
      // Refresh the open breakdown detail AND the runs list, so Run History
      // reflects the new disbursed status/totals without a manual refetch.
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: RUNS_KEY });
    },
  });
}

export function useThirteenthMonth(year?: number) {
  return useQuery({
    queryKey: ["hr", "payroll", "thirteenth-month", year ?? "current"],
    queryFn: () => getThirteenthMonth(year),
  });
}

export function useMyNextPayday() {
  return useQuery({ queryKey: ["hr", "me", "next-payday"], queryFn: getMyNextPayday });
}

export function useMyPayslips() {
  return useQuery({ queryKey: ["hr", "me", "payslips"], queryFn: getMyPayslips });
}

export function usePayslip(id: number | undefined) {
  return useQuery({
    queryKey: ["hr", "me", "payslip", id],
    queryFn: () => getPayslip(id!),
    enabled: !!id,
  });
}
