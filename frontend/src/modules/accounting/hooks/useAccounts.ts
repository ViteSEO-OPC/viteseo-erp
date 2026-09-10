import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { Account } from "@/modules/accounting/types";

export const accountKeys = {
  all: ["accounting", "accounts"] as const,
};

export function useCreateAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: {
      code: string;
      name: string;
      type: string;
      is_bank?: boolean;
    }): Promise<Account> => {
      const { data } = await apiClient.post("/accounting/accounts", payload);
      return data.data as Account;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: accountKeys.all }),
  });
}

export function useUpdateAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      id,
      name,
      is_active,
      is_bank,
    }: {
      id: number;
      name: string;
      is_active?: boolean;
      is_bank?: boolean;
    }): Promise<Account> => {
      const { data } = await apiClient.put(`/accounting/accounts/${id}`, { name, is_active, is_bank });
      return data.data as Account;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: accountKeys.all }),
  });
}

export function useDeleteAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<void> => {
      await apiClient.delete(`/accounting/accounts/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: accountKeys.all }),
  });
}

/** Chart of accounts for the current org (GET /accounting/accounts). */
export function useAccounts() {
  return useQuery({
    queryKey: accountKeys.all,
    queryFn: async (): Promise<Account[]> => {
      const { data } = await apiClient.get("/accounting/accounts");
      return data.data as Account[];
    },
  });
}
