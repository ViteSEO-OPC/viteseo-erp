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
  uploadEmployeePhoto,
  deleteEmployeePhoto,
  getEmployeeLoans,
  createEmployeeLoan,
  cancelEmployeeLoan,
  reopenEmployeeLoan,
  type EmployeeLoanInput,
} from "@/modules/hr/api/employees";
import type { EmployeeInput, EmployeeUpdateInput } from "@/modules/hr/types/employee";

const EMPLOYEES_KEY = ["hr", "employees"] as const;

export function useEmployees() {
  return useQuery({
    queryKey: EMPLOYEES_KEY,
    queryFn: () => getEmployees(),
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

/** Loans & cash advances for one employee (Slice 11b). HR only. */
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
