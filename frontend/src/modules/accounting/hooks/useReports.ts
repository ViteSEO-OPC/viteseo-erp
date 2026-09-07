import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";
import type {
  AgedReport,
  CashFlow,
  GeneralLedger,
  VatReport,
} from "@/modules/accounting/types";

export function useAgedReceivables() {
  return useQuery({
    queryKey: ["accounting", "aged-receivables"],
    queryFn: async (): Promise<AgedReport> => {
      const { data } = await apiClient.get("/accounting/reports/aged-receivables");
      return data.data as AgedReport;
    },
  });
}

export function useAgedPayables() {
  return useQuery({
    queryKey: ["accounting", "aged-payables"],
    queryFn: async (): Promise<AgedReport> => {
      const { data } = await apiClient.get("/accounting/reports/aged-payables");
      return data.data as AgedReport;
    },
  });
}

export function useGeneralLedger(accountId: number | null) {
  return useQuery({
    queryKey: ["accounting", "general-ledger", accountId],
    enabled: accountId != null,
    queryFn: async (): Promise<GeneralLedger> => {
      const { data } = await apiClient.get("/accounting/reports/general-ledger", {
        params: { account_id: accountId },
      });
      return data.data as GeneralLedger;
    },
  });
}

export function useCashFlow(period: string) {
  return useQuery({
    queryKey: ["accounting", "cash-flow", period],
    queryFn: async (): Promise<CashFlow> => {
      const { data } = await apiClient.get("/accounting/reports/cash-flow", {
        params: { period },
      });
      return data.data as CashFlow;
    },
  });
}

export function useVatReport(period: string) {
  return useQuery({
    queryKey: ["accounting", "vat", period],
    queryFn: async (): Promise<VatReport> => {
      const { data } = await apiClient.get("/accounting/reports/vat", {
        params: { period },
      });
      return data.data as VatReport;
    },
  });
}

/** One row of a Statement of Account (an invoice, open-item). */
export interface SoaLine {
  invoice_no: string;
  issue_date: string | null;
  due_date: string | null;
  description: string | null;
  amount: number;
  amount_paid: number;
  balance_due: number;
  status: string;
  days_overdue: number;
}

export interface StatementOfAccount {
  contact: { id: number; name: string; email: string | null };
  as_of: string;
  lines: SoaLine[];
  totals: { billed: number; paid: number; outstanding: number };
  aging: { current: number; d1_30: number; d31_60: number; d61_90: number; over_90: number };
}

export interface Bir2550Q {
  year: number;
  quarter: number;
  period_label: string;
  sales: { vatable: number; zero_rated: number; exempt: number; output_vat: number };
  purchases: { vatable: number; exempt: number; input_vat: number };
  net_vat_payable: number;
  is_payable: boolean;
  months: { month: string; label: string; output_vat: number; input_vat: number }[];
}

export function useBir2550Q(year: number, quarter: number) {
  return useQuery({
    queryKey: ["accounting", "bir-2550q", year, quarter],
    queryFn: async (): Promise<Bir2550Q> => {
      const { data } = await apiClient.get("/accounting/reports/bir-2550q", {
        params: { year, quarter },
      });
      return data.data as Bir2550Q;
    },
  });
}

export interface SlspRow {
  contact_id: number;
  name: string;
  tin: string | null;
  address: string | null;
  vatable: number;
  zero_rated: number;
  exempt: number;
  vat: number;
  gross: number;
}

export interface Slsp {
  year: number;
  quarter: number;
  rows: SlspRow[];
  totals: { vatable: number; zero_rated: number; exempt: number; vat: number; gross: number };
}

function useSlsp(kind: "sales" | "purchases", year: number, quarter: number) {
  return useQuery({
    queryKey: ["accounting", "slsp", kind, year, quarter],
    queryFn: async (): Promise<Slsp> => {
      const { data } = await apiClient.get(`/accounting/reports/slsp-${kind}`, {
        params: { year, quarter },
      });
      return data.data as Slsp;
    },
  });
}

export const useSlspSales = (year: number, quarter: number) => useSlsp("sales", year, quarter);
export const useSlspPurchases = (year: number, quarter: number) => useSlsp("purchases", year, quarter);

export function useStatementOfAccount(contactId: number | null, asOf: string) {
  return useQuery({
    queryKey: ["accounting", "statement-of-account", contactId, asOf],
    enabled: contactId != null,
    queryFn: async (): Promise<StatementOfAccount> => {
      const { data } = await apiClient.get("/accounting/reports/statement-of-account", {
        params: { contact_id: contactId, as_of: asOf },
      });
      return data.data as StatementOfAccount;
    },
  });
}
