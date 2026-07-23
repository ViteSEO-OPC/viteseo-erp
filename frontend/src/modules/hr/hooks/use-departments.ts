"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getDepartments,
  createDepartment,
  updateDepartment,
  deleteDepartment,
  archiveDepartment,
  restoreDepartment,
} from "@/modules/hr/api/departments";
import type { DepartmentInput } from "@/modules/hr/types/department";

// One query key so every mutation can invalidate the list — that refetch IS how
// the table refreshes after create/edit/delete (plan §5.5a).
const DEPARTMENTS_KEY = ["hr", "departments"] as const;
// Assigning employees to roles edits employee records, so their directory must
// refetch too.
const EMPLOYEES_KEY = ["hr", "employees"] as const;

export function useDepartments() {
  return useQuery({
    queryKey: DEPARTMENTS_KEY,
    queryFn: getDepartments,
  });
}

export function useCreateDepartment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: DepartmentInput) => createDepartment(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: DEPARTMENTS_KEY });
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
    },
  });
}

export function useUpdateDepartment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: DepartmentInput }) =>
      updateDepartment(id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: DEPARTMENTS_KEY });
      qc.invalidateQueries({ queryKey: EMPLOYEES_KEY });
    },
  });
}

export function useDeleteDepartment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteDepartment(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: DEPARTMENTS_KEY }),
  });
}

// Archive = keep the record but mark it inactive (drops out of dropdowns);
// restore flips it back. One hook, `restore` picks the direction.
export function useToggleDepartmentArchive() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, restore }: { id: number; restore: boolean }) =>
      restore ? restoreDepartment(id) : archiveDepartment(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: DEPARTMENTS_KEY }),
  });
}
