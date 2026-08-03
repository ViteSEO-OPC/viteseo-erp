"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getPayrollRuns,
  getPayrollRun,
  runPayroll,
  markPayslipDone,
  reopenPayslip,
  lockPayrollRun,
  unlockPayrollRun,
  getMyNextPayday,
  getMyPayslips,
  getPayslip,
  getHrPayslip,
  getPayrollSettings,
  getThirteenthMonth,
  previewThirteenthMonth,
  runThirteenthMonth,
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
      // A re-run recomputes the amounts, so an open payslip document is now stale.
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
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
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
    },
  });
}

/** Undo a disbursement. Also refreshes claims — any it paid are owed again. */
export function useReopenPayslip() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenPayslip(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
      qc.invalidateQueries({ queryKey: ["hr", "reimbursements"] });
    },
  });
}

/** Lock / unlock a run (Slice 5). Same invalidation as marking a payslip disbursed. */
export function useSetPayrollRunLock() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, lock }: { id: number; lock: boolean }) =>
      lock ? lockPayrollRun(id) : unlockPayrollRun(id),
    onSuccess: () => {
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

/** Slice B17 — what the run would pay, so the confirmation shows real figures. */
export function useThirteenthMonthPreview(year?: number, enabled = true) {
  return useQuery({
    queryKey: ["hr", "payroll", "thirteenth-month", "preview", year ?? "current"],
    queryFn: () => previewThirteenthMonth(year),
    enabled,
  });
}

export function useRunThirteenthMonth() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (year: number) => runThirteenthMonth(year),
    onSuccess: () => {
      // It IS a payroll run — the history, the run detail and the accrual view all move.
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "thirteenth-month"] });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
    },
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

/** HR reading one employee's payslip. Separate key from "me" — the payload differs by role. */
export function useHrPayslip(id: number | undefined) {
  return useQuery({
    queryKey: ["hr", "payslip", id],
    queryFn: () => getHrPayslip(id!),
    enabled: !!id,
  });
}
