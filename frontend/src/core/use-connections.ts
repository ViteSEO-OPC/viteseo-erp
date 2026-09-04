import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

/** A cross-module connection ("integration"), decorated for the current org. */
export interface Connection {
  key: string;
  label: string;
  description: string;
  from: string;
  to: string;
  from_label: string;
  to_label: string;
  requires: string[];
  /** feature key => human label (what must be availed to unlock). */
  requires_labels: Record<string, string>;
  requires_met: boolean;
  status: "available" | "coming_soon";
  /** True when registered, shippable, and both systems are availed. */
  available: boolean;
  /** True when available AND not turned off. */
  enabled: boolean;
}

/**
 * The current org's connection matrix (see backend GET /connections). Platform-
 * level and cross-cutting (like {@link useFeatures}), so it lives in core — the
 * sidebar reads it to hide bridge links whose connection an admin switched off.
 */
export function useConnections() {
  return useQuery({
    queryKey: ["connections"],
    queryFn: async (): Promise<Connection[]> => {
      const { data } = await apiClient.get("/connections");
      return data.data as Connection[];
    },
    staleTime: 5 * 60 * 1000,
  });
}

/** Toggle one connection on/off, then refresh the matrix + the features launcher. */
export function useToggleConnection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { key: string; enabled: boolean }): Promise<Connection> => {
      const { data } = await apiClient.put(`/connections/${payload.key}`, { enabled: payload.enabled });
      return data.data as Connection;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["connections"] });
      qc.invalidateQueries({ queryKey: ["me", "features"] });
    },
  });
}
