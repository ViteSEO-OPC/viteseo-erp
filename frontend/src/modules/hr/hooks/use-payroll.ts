"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { QueueParams } from "@/modules/hr/api/queue";

import {
  getPayrollRuns,
  getPayrollRunForCycle,
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
  amendPayrollRun,
  correctPayslipLine,
  correctRunLine,
  removePayslipLineCorrection,
  acknowledgePayslipLineCorrection,
  getRerunPreview,
  rerunPayrollRun,
  getRetroFlags,
  resolveRetroFlag,
  createPaymentBatch,
  getPaymentBatches,
  getPaymentBatchTotals,
  getPaymentBatch,
  settlePayment,
  reconcilePaymentBatch,
  getDeductionArrears,
  getAttendanceExceptions,
  getMinimumWageCheck,
  applyMinimumWageAdjustments,
} from "@/modules/hr/api/payroll";

const RUNS_KEY = ["hr", "payroll", "runs"] as const;
const SETTINGS_KEY = ["hr", "payroll", "settings"] as const;
const RETRO_KEY = ["hr", "payroll", "retro-flags"] as const;
const ARREARS_KEY = ["hr", "payroll", "deduction-arrears"] as const;
const BATCHES_KEY = ["hr", "payroll", "payment-batches"] as const;
const MINIMUM_WAGE_KEY = ["hr", "payroll", "minimum-wage"] as const;

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

/**
 * Whether one exact period has already been run. `undefined` while loading, `null` when it has
 * not been. See getPayrollRunForCycle() for why this is not a lookup through the history.
 */
export function usePayrollRunForCycle(
  cycle?: { frequency: string; cycle_start: string; cycle_end: string },
) {
  return useQuery({
    queryKey: [...RUNS_KEY, "for-cycle", cycle ?? "none"] as const,
    queryFn: () => getPayrollRunForCycle(cycle!),
    enabled: !!cycle,
  });
}

/**
 * One page of the run history, with the total.
 *
 * Each run row carries four aggregate subqueries over its own payslips, so the page size bounds
 * work that scales with HEADCOUNT — not just the number of rows drawn.
 */
export function usePayrollRuns(params: QueueParams) {
  return useQuery({
    queryKey: [...RUNS_KEY, params] as const,
    queryFn: () => getPayrollRuns(params),
    placeholderData: keepPreviousData,
  });
}

/**
 * Who the selected cycle would pay below the floor.
 *
 * Fetched with the pay group so the payroll screen can warn BEFORE the run rather than after, and
 * disabled until there is a cycle to ask about.
 */
export function useMinimumWageCheck(
  input: { frequency: string; cycle_start?: string; cycle_end?: string } | null,
) {
  return useQuery({
    queryKey: [...MINIMUM_WAGE_KEY, input],
    queryFn: () => getMinimumWageCheck(input!),
    enabled: !!input?.frequency,
  });
}

/**
 * Item 12 — attendance exceptions for the cycle about to be priced.
 *
 * Read at the moment the money is committed, not on whichever screen owns each queue: HR runs
 * payroll from here, so this is where the counts have to appear.
 */
export function useAttendanceExceptions(start?: string, end?: string) {
  return useQuery({
    queryKey: ["hr", "payroll", "attendance-exceptions", start ?? "", end ?? ""],
    queryFn: () => getAttendanceExceptions(start!, end!),
    enabled: !!start && !!end,
  });
}

export function useApplyMinimumWage() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: applyMinimumWageAdjustments,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: MINIMUM_WAGE_KEY });
      // Salaries moved, so the directory, the open employee record and the outstanding
      // back-pay queue are all stale.
      qc.invalidateQueries({ queryKey: ["hr", "employees"] });
      qc.invalidateQueries({ queryKey: RETRO_KEY });
    },
  });
}

/**
 * One run, with ONE PAGE of its per-employee breakdown.
 *
 * Everything the screen states about the run — the register totals, the correction notices,
 * whether it can be locked — arrives as its own field, counted by the database. None of it may
 * be derived from `breakdown`, which is ten rows of a payroll.
 */
export function usePayrollRun(
  id: number | undefined,
  breakdown?: { page: number; per_page: number; search?: string },
) {
  return useQuery({
    queryKey: ["hr", "payroll", "run", id, breakdown ?? "all"] as const,
    queryFn: () => getPayrollRun(id!, breakdown),
    enabled: !!id,
    placeholderData: keepPreviousData,
  });
}

export function useRunPayroll() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: runPayroll,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ARREARS_KEY });
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

/** Lock / unlock a run. Same invalidation as marking a payslip disbursed. */
export function useSetPayrollRunLock() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      lock,
      acknowledgeOverpayment,
    }: {
      id: number;
      lock: boolean;
      /** Confirms locking over an unrecovered overpayment. See lockPayrollRun. */
      acknowledgeOverpayment?: boolean;
    }) => (lock ? lockPayrollRun(id, acknowledgeOverpayment) : unlockPayrollRun(id)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: RUNS_KEY });
    },
  });
}

/** One page of the 13th-month accrual, with the year's real total beside it. */
export function useThirteenthMonth(year: number | undefined, params: QueueParams) {
  return useQuery({
    queryKey: ["hr", "payroll", "thirteenth-month", year ?? "current", params] as const,
    queryFn: () => getThirteenthMonth(year, params),
    placeholderData: keepPreviousData,
  });
}

/** What the run would pay, so the confirmation shows real figures. */
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

/** Waive a line across a whole period. */
export function useCorrectRunLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ runId, ...input }: Parameters<typeof correctRunLine>[1] & { runId: number }) =>
      correctRunLine(runId, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslip"] });
    },
  });
}

/** Correct one line on one payslip — and put the corrected document straight into the cache. */
export function useCorrectPayslipLine() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      payslipId,
      ...input
    }: Parameters<typeof correctPayslipLine>[1] & { payslipId: number }) =>
      correctPayslipLine(payslipId, input),
    onSuccess: (payslip) => {
      qc.setQueryData(["hr", "payslip", payslip.id], payslip);
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslip"] });
      qc.invalidateQueries({ queryKey: RETRO_KEY });
    },
  });
}

/** Put a corrected line back to what the engine computed. Same invalidation as making the edit. */
export function useRemovePayslipLineCorrection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ payslipId, correctionId }: { payslipId: number; correctionId: number }) =>
      removePayslipLineCorrection(payslipId, correctionId),
    onSuccess: (payslip) => {
      qc.setQueryData(["hr", "payslip", payslip.id], payslip);
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslip"] });
      qc.invalidateQueries({ queryKey: RETRO_KEY });
    },
  });
}

/** Confirm a drifted correction still stands. Same invalidation: the run's notes change. */
export function useAcknowledgePayslipLineCorrection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ payslipId, correctionId }: { payslipId: number; correctionId: number }) =>
      acknowledgePayslipLineCorrection(payslipId, correctionId),
    onSuccess: (payslip) => {
      qc.setQueryData(["hr", "payslip", payslip.id], payslip);
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslip"] });
      qc.invalidateQueries({ queryKey: RETRO_KEY });
    },
  });
}

/** Correct a period on its own payslips. */
export function useAmendPayrollRun() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ runId, ...input }: Parameters<typeof amendPayrollRun>[1] & { runId: number }) =>
      amendPayrollRun(runId, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslip"] });
      qc.invalidateQueries({ queryKey: RETRO_KEY });
    },
  });
}

/** What recomputing a period would change. */
/** One page of a re-run preview. Never cached — it recomputes a period to answer. */
export function useRerunPreview(
  runId: number | undefined,
  enabled: boolean,
  params: { page: number; per_page: number },
) {
  return useQuery({
    queryKey: ["hr", "payroll", "rerun-preview", runId, params] as const,
    queryFn: () => getRerunPreview(runId!, params),
    enabled: !!runId && enabled,
    staleTime: 0,
    gcTime: 0,
  });
}

/** Recompute a settled period. Invalidates everything a run does — it IS a run. */
export function useRerunPayrollRun() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ runId, reason }: { runId: number; reason: string }) =>
      rerunPayrollRun(runId, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslip"] });
      // A recompute recovers arrears and can settle claims and loan instalments, exactly as a
      // first run does.
      qc.invalidateQueries({ queryKey: ARREARS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "reimbursements"] });
    },
  });
}

/** Retroactive pay differences still outstanding. */
export function useRetroFlags() {
  return useQuery({ queryKey: RETRO_KEY, queryFn: getRetroFlags });
}

export function useResolveRetroFlag() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, note }: { id: number; note: string }) => resolveRetroFlag(id, note),
    onSuccess: () => qc.invalidateQueries({ queryKey: RETRO_KEY }),
  });
}

// --- Disbursement ------------------------------------------------------------------

/** Every payment batch, newest first. Pass a run id to narrow it to one payroll. */
/**
 * What the run has been paid, across every batch.
 *
 * Its own query rather than a sum of the rows on screen: the list is paged, so an outstanding
 * balance worked out from one page of the payments is simply a wrong number.
 */
export function usePaymentBatchTotals(payrollRunId?: number) {
  return useQuery({
    queryKey: [...BATCHES_KEY, "totals", payrollRunId ?? "all"] as const,
    queryFn: () => getPaymentBatchTotals(payrollRunId),
  });
}

/**
 * One page of a run's payment batches, with the total. The endpoint always paged at twenty; the
 * screen read page one and showed it as the whole list, so anything past the twentieth batch was
 * invisible with nothing to say so.
 */
export function usePaymentBatches(payrollRunId: number | undefined, params: QueueParams) {
  return useQuery({
    queryKey: [...BATCHES_KEY, payrollRunId ?? "all", params] as const,
    queryFn: () => getPaymentBatches(payrollRunId, params),
    placeholderData: keepPreviousData,
  });
}

/** One batch with its transfers, plus whether it accounts for the whole run. */
export function usePaymentBatch(id: number | undefined) {
  return useQuery({
    queryKey: [...BATCHES_KEY, "detail", id],
    queryFn: () => getPaymentBatch(id as number),
    enabled: typeof id === "number",
  });
}

/**
 * Build the batch for a completed run.
 *
 * Invalidates the runs list as well as the batches: whether a run still has anything left to pay
 * is derived from its payments, so a stale run row would offer to pay it a second time.
 */
export function useCreatePaymentBatch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createPaymentBatch,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: BATCHES_KEY });
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
    },
  });
}

/**
 * Record what the bank did with one transfer.
 *
 * Invalidates payslips and runs as well as batches: settling moves a payslip to `disbursed` and a
 * failure moves it back, so every view of the run's status is stale afterwards.
 */
export function useSettlePayment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...input }: Parameters<typeof settlePayment>[1] & { id: number }) =>
      settlePayment(id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: BATCHES_KEY });
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
      qc.invalidateQueries({ queryKey: ["hr", "reimbursements"] });
    },
  });
}

/** Apply (or preview) the bank's return file. Only invalidates when it actually wrote. */
export function useReconcilePaymentBatch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, file, confirm }: { id: number; file: File; confirm: boolean }) =>
      reconcilePaymentBatch(id, file, confirm),
    onSuccess: (result) => {
      if (!result.applied) return;
      qc.invalidateQueries({ queryKey: BATCHES_KEY });
      qc.invalidateQueries({ queryKey: RUNS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "payroll", "run"] });
      qc.invalidateQueries({ queryKey: ["hr", "payslip"] });
      qc.invalidateQueries({ queryKey: ["hr", "reimbursements"] });
    },
  });
}

/**
 * Uncollected deductions still outstanding.
 *
 * Invalidated by a payroll run, because a run with room recovers them automatically — leaving a
 * stale queue on screen would show HR debts that were just settled.
 */
export function useDeductionArrears() {
  return useQuery({ queryKey: ARREARS_KEY, queryFn: getDeductionArrears });
}
