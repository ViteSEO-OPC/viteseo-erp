import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { kioskApi } from "./api";

export function useKioskCatalog() {
  return useQuery({
    queryKey: ["kiosk", "catalog"],
    queryFn: () => kioskApi.getCatalog(),
    staleTime: 10 * 60 * 1000,
  });
}

export function useKioskSelections() {
  return useQuery({ queryKey: ["kiosk", "selections"], queryFn: () => kioskApi.listSelections() });
}

export function useCreateSelection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: kioskApi.createSelection,
    onSuccess: () => qc.invalidateQueries({ queryKey: ["kiosk", "selections"] }),
  });
}

export function useDeleteSelection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => kioskApi.deleteSelection(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["kiosk", "selections"] }),
  });
}

// ---- Temp client access ----
export function useKioskAccess() {
  return useQuery({ queryKey: ["kiosk", "access"], queryFn: () => kioskApi.listAccess() });
}

export function useCreateAccess() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (label: string) => kioskApi.createAccess(label),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["kiosk", "access"] }),
  });
}

export function useRevokeAccess() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => kioskApi.revokeAccess(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["kiosk", "access"] }),
  });
}
