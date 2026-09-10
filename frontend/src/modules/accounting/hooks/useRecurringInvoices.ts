import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

export interface RecurringLine {
  description: string | null;
  quantity: number;
  unit_price: number;
  account_id: number;
  account_name?: string | null;
}

export interface RecurringInvoice {
  id: number;
  contact_id: number;
  contact_name: string | null;
  description: string | null;
  frequency: "weekly" | "monthly" | "quarterly" | "yearly";
  tax_rate: number;
  due_days: number;
  start_date: string | null;
  next_run_date: string | null;
  end_date: string | null;
  status: "active" | "paused" | "ended";
  occurrences: number;
  amount: number;
  lines: RecurringLine[];
}

export interface NewRecurringInvoice {
  contact_id: number;
  description?: string | null;
  frequency: string;
  tax_rate?: number;
  /** Zero-rated and exempt are both 0%, so the rate alone cannot say which. */
  vat_class?: "vatable" | "zero_rated" | "exempt";
  due_days?: number;
  start_date: string;
  end_date?: string | null;
  lines: { description?: string | null; quantity: number; unit_price: number; account_id: number }[];
  /** Set true to proceed past the "back-dated invoices" confirmation. */
  confirm_backdate?: boolean;
}

const key = ["accounting", "recurring-invoices"] as const;

export function useRecurringInvoices() {
  return useQuery({
    queryKey: key,
    queryFn: async (): Promise<RecurringInvoice[]> => {
      const { data } = await apiClient.get("/accounting/recurring-invoices");
      return data.data as RecurringInvoice[];
    },
  });
}

export function useCreateRecurringInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewRecurringInvoice) => {
      const { data } = await apiClient.post("/accounting/recurring-invoices", payload);
      return data.data as RecurringInvoice;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });
}

export function usePauseRecurringInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, action }: { id: number; action: "pause" | "resume" }) => {
      const { data } = await apiClient.post(`/accounting/recurring-invoices/${id}/${action}`, {});
      return data.data as RecurringInvoice;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });
}

export function useDeleteRecurringInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/accounting/recurring-invoices/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });
}

/** Generate every invoice due now; invalidates recurring + invoices lists. */
export function useRunRecurringInvoices() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<{ generated: number }> => {
      const { data } = await apiClient.post("/accounting/recurring-invoices/run", {});
      return data.data as { generated: number };
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: key });
      qc.invalidateQueries({ queryKey: ["accounting", "invoices"] });
    },
  });
}
