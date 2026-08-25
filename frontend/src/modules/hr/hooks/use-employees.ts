"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getEmployees,
  getEmployee,
  createEmployee,
  updateEmployee,
  getSalaryHistory,
  activateEmployee,
  reopenOnboarding,
  setHrRole,
  getEmployeeDocuments,
  getMyDocuments,
  uploadEmployeeDocument,
  deleteEmployeeDocument,
  renameEmployeeDocument,
  uploadEmployeePhoto,
  deleteEmployeePhoto,
  getEmployeeLoans,
  getAllLoans,
  createEmployeeLoan,
  cancelEmployeeLoan,
  reopenEmployeeLoan,
  getMyLoans,
  requestLoan,
  authorizeLoan,
  approveLoan,
  rejectLoan,
  type EmployeeLoanInput,
  separateEmployee,
  reinstateEmployee,
  rehireEmployee,
  createEmploymentHistory,
  updateEmploymentHistory,
  deleteEmploymentHistory,
  createSalaryHistory,
  updateSalaryHistory,
  deleteSalaryHistory,
  type EmploymentHistoryInput,
  type SalaryHistoryInput,
} from "@/modules/hr/api/employees";
import type { EmployeeInput, EmployeeUpdateInput } from "@/modules/hr/types/employee";

const EMPLOYEES_KEY = ["hr", "employees"] as const;

export function useEmployees(status: "active" | "former" | "all" = "active") {
  return useQuery({
    queryKey: [...EMPLOYEES_KEY, { status }] as const,
    queryFn: () => getEmployees(undefined, status),
  });
}

/**
 * The three lifecycle transitions. All invalidate the whole employee key, because each one
 * moves the record between the "active" and "former" lists as well as changing the record itself.
 */
export function useSeparateEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number;
      input: { separation_type: "voluntary" | "involuntary"; separation_date: string; reason?: string };
    }) => separateEmployee(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useReinstateEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reinstateEmployee(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useRehireEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: { hire_date: string; reason?: string } }) =>
      rehireEmployee(id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
      // A rehire seeds fresh leave credits and may park a loan for re-authorisation.
      qc.invalidateQueries({ queryKey: ["hr", "leave-balances"] });
      qc.invalidateQueries({ queryKey: ["hr", "loans"] });
    },
  });
}

export function useEmployee(id: number) {
  return useQuery({
    queryKey: [...EMPLOYEES_KEY, id],
    queryFn: () => getEmployee(id),
    enabled: Number.isFinite(id),
  });
}

export function useCreateEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: EmployeeInput) => createEmployee(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useUpdateEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: EmployeeUpdateInput }) =>
      updateEmployee(id, input),
    // Refresh the list, this employee's detail, and their salary history (a raise
    // appends a row).
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
      qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id] });
      qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "salary-history"] });
      // The employee's own view of their record reads a different key.
      qc.invalidateQueries({ queryKey: ["hr", "me", "employee"] });
    },
  });
}

/** Set or replace the profile picture. HR only. */
export function useUploadEmployeePhoto(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (photo: File) => uploadEmployeePhoto(id, photo),
    onSuccess: () => invalidatePhoto(qc, id),
  });
}

export function useDeleteEmployeePhoto(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => deleteEmployeePhoto(id),
    onSuccess: () => invalidatePhoto(qc, id),
  });
}

// The photo shows on the directory card, the record header and the person's own
// profile, so all three caches have to drop.
function invalidatePhoto(qc: ReturnType<typeof useQueryClient>, id: number) {
  qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
  qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id] });
  qc.invalidateQueries({ queryKey: ["hr", "me", "employee"] });
}

export function useSalaryHistory(id: number) {
  return useQuery({
    queryKey: [...EMPLOYEES_KEY, id, "salary-history"],
    queryFn: () => getSalaryHistory(id),
    enabled: Number.isFinite(id),
  });
}

/** Hand-corrections to the two timelines. */
export function useCreateEmploymentHistory(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: EmploymentHistoryInput) => createEmploymentHistory(employeeId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useUpdateEmploymentHistory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: EmploymentHistoryInput }) =>
      updateEmploymentHistory(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useDeleteEmploymentHistory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteEmploymentHistory(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useCreateSalaryHistory(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: SalaryHistoryInput) => createSalaryHistory(employeeId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useUpdateSalaryHistory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: SalaryHistoryInput }) =>
      updateSalaryHistory(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}

export function useDeleteSalaryHistory() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteSalaryHistory(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: EMPLOYEES_KEY }),
  });
}


export function useEmployeeDocuments(id: number) {
  return useQuery({
    queryKey: [...EMPLOYEES_KEY, id, "documents"],
    queryFn: () => getEmployeeDocuments(id),
    enabled: Number.isFinite(id),
  });
}

export function useMyDocuments() {
  return useQuery({ queryKey: ["hr", "me", "documents"], queryFn: getMyDocuments });
}

export function useUploadEmployeeDocument(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ file, label }: { file: File; label?: "Uploaded" | "Signed" }) =>
      uploadEmployeeDocument(id, file, label),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "documents"] }),
  });
}

export function useRenameEmployeeDocument(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ documentId, name }: { documentId: number; name: string }) =>
      renameEmployeeDocument(id, documentId, name),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "documents"] }),
  });
}

export function useDeleteEmployeeDocument(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (documentId: number) => deleteEmployeeDocument(id, documentId),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "documents"] }),
  });
}

// Grant/revoke HR-officer access. Refresh the list (the directory may badge HR
// officers) and this employee's detail so the toggle reflects the new state.
export function useSetHrRole(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (isHrOfficer: boolean) => setHrRole(id, isHrOfficer),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
      qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id] });
    },
  });
}

export function useActivateEmployee() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => activateEmployee(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
      qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id] });
    },
  });
}

export function useReopenOnboarding() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenOnboarding(id, reason),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
      qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id] });
    },
  });
}

/** Loans & cash advances for one employee. HR only. */
export function useEmployeeLoans(id: number | undefined) {
  return useQuery({
    queryKey: [...EMPLOYEES_KEY, id, "loans"] as const,
    queryFn: () => getEmployeeLoans(id!),
    enabled: !!id,
  });
}

export function useCreateEmployeeLoan(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: EmployeeLoanInput) => createEmployeeLoan(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "loans"] }),
  });
}

export function useCancelEmployeeLoan(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ loanId, reason }: { loanId: number; reason: string }) =>
      cancelEmployeeLoan(loanId, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "loans"] }),
  });
}

export function useReopenEmployeeLoan(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ loanId, reason }: { loanId: number; reason?: string }) =>
      reopenEmployeeLoan(loanId, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, id, "loans"] }),
  });
}

// --- The employee's own loans -----------------------------------------

const MY_LOANS_KEY = ["hr", "me", "loans"] as const;

/** Every employee's loans, for the approvals queue. HR only. */
export function useAllLoans(status = "requested") {
  return useQuery({
    queryKey: ["hr", "loans", { status }] as const,
    queryFn: () => getAllLoans(status),
  });
}

export function useMyLoans() {
  return useQuery({ queryKey: MY_LOANS_KEY, queryFn: getMyLoans });
}

export function useRequestLoan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: requestLoan,
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_LOANS_KEY }),
  });
}

/** The consent that makes the deduction lawful — the employee's own act. */
export function useAuthorizeLoan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (loanId: number) => authorizeLoan(loanId),
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_LOANS_KEY }),
  });
}

/** HR's decision on a request. Neither outcome starts a deduction. */
export function useDecideLoan(employeeId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ loanId, approve, reason }: { loanId: number; approve: boolean; reason?: string }) =>
      approve ? approveLoan(loanId, reason) : rejectLoan(loanId, reason ?? ""),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...EMPLOYEES_KEY, employeeId, "loans"] }),
  });
}
