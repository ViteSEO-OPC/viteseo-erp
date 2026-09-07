import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type { Invoice } from "@/modules/accounting/types";

export const invoiceKeys = {
  all: ["accounting", "invoices"] as const,
};

export function useInvoices() {
  return useQuery({
    queryKey: invoiceKeys.all,
    queryFn: async (): Promise<Invoice[]> => {
      const { data } = await apiClient.get("/accounting/invoices");
      return data.data as Invoice[];
    },
  });
}

/** A single invoice with its line items + contact (for the detail / print view). */
export function useInvoice(id: number | null) {
  return useQuery({
    queryKey: ["accounting", "invoice", id],
    enabled: id != null,
    queryFn: async (): Promise<Invoice> => {
      const { data } = await apiClient.get(`/accounting/invoices/${id}`);
      return data.data as Invoice;
    },
  });
}

export interface NewDocumentLine {
  description?: string;
  quantity: number;
  unit_price: number;
  account_id: number;
}

export interface NewInvoice {
  contact_id: number;
  issue_date: string;
  due_date?: string;
  description?: string;
  tax_rate?: number;
  vat_class?: "vatable" | "zero_rated" | "exempt";
  lines: NewDocumentLine[];
}

export function useCreateInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewInvoice): Promise<Invoice> => {
      const { data } = await apiClient.post("/accounting/invoices", payload);
      return data.data as Invoice;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}

/** Email the invoice to its contact (422 if the contact has no email). */
export function useSendInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number): Promise<Invoice> => {
      const { data } = await apiClient.post(`/accounting/invoices/${id}/send`);
      return data.data as Invoice;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}

export function usePayInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, amount }: { id: number; amount?: number }): Promise<Invoice> => {
      const { data } = await apiClient.post(
        `/accounting/invoices/${id}/pay`,
        amount != null ? { amount } : {},
      );
      return data.data as Invoice;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["accounting"] }),
  });
}
