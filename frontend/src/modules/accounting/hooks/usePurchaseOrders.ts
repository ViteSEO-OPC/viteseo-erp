import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

export interface PurchaseOrderLine {
  description: string | null;
  quantity: number;
  unit_price: number;
  account_id: number;
  line_total: number;
}

export interface PurchaseOrder {
  id: number;
  po_no: string;
  contact_id: number;
  vendor_name: string | null;
  order_date: string | null;
  expected_date: string | null;
  description: string | null;
  tax_rate: number;
  amount: number;
  status: "open" | "billed" | "cancelled";
  bill_id: number | null;
  lines: PurchaseOrderLine[];
}

export interface NewPurchaseOrder {
  contact_id: number;
  order_date: string;
  expected_date?: string | null;
  description?: string | null;
  tax_rate?: number;
  lines: { description?: string | null; quantity: number; unit_price: number; account_id: number }[];
}

const key = ["accounting", "purchase-orders"] as const;

export function usePurchaseOrders() {
  return useQuery({
    queryKey: key,
    queryFn: async (): Promise<PurchaseOrder[]> => {
      const { data } = await apiClient.get("/accounting/purchase-orders");
      return data.data as PurchaseOrder[];
    },
  });
}

export function useCreatePurchaseOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: NewPurchaseOrder) => {
      const { data } = await apiClient.post("/accounting/purchase-orders", payload);
      return data.data as PurchaseOrder;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });
}

export function useConvertPurchaseOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, issue_date, due_date }: { id: number; issue_date?: string; due_date?: string | null }) => {
      const { data } = await apiClient.post(`/accounting/purchase-orders/${id}/convert-to-bill`, { issue_date, due_date });
      return data.data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: key });
      qc.invalidateQueries({ queryKey: ["accounting", "bills"] });
    },
  });
}

export function useCancelPurchaseOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      const { data } = await apiClient.post(`/accounting/purchase-orders/${id}/cancel`, {});
      return data.data as PurchaseOrder;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });
}

export function useDeletePurchaseOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/accounting/purchase-orders/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });
}
