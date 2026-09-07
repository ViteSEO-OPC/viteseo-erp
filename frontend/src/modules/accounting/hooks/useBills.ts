import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { Bill } from "@/modules/accounting/types";

export const billKeys = {
  all: ["accounting", "bills"] as const,
};

export function useBills() {
  return useQuery({
    queryKey: billKeys.all,
    queryFn: async (): Promise<Bill[]> => {
      const { data } = await apiClient.get("/accounting/bills");
      return data.data as Bill[];
    },
  });
}

export interface NewBillLine {
  description?: string;
  quantity: number;
  unit_price: number;
  account_id: number;
}

export interface NewBill {
  contact_id: number;
  issue_date: string;
  due_date?: string;
  description?: string;
  tax_rate?: number;
  vat_class?: "vatable" | "exempt";
  lines: NewBillLine[];
}

export function useCreateBill() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewBill): Promise<Bill> => {
      const { data } = await apiClient.post("/accounting/bills", payload);
      return data.data as Bill;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}

export function usePayBill() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, amount }: { id: number; amount?: number }): Promise<Bill> => {
      const { data } = await apiClient.post(
        `/accounting/bills/${id}/pay`,
        amount != null ? { amount } : {},
      );
      return data.data as Bill;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}
