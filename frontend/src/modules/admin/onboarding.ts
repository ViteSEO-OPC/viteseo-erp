import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

/** One function in the platform feature catalog (GET /admin/features). */
export interface FeatureCatalogItem {
  key: string;
  label: string;
  module: string;
  requires: string[];
}

export function useFeatureCatalog() {
  return useQuery({
    queryKey: ["admin", "feature-catalog"],
    queryFn: async (): Promise<FeatureCatalogItem[]> => {
      const { data } = await apiClient.get("/admin/features");
      return data.data as FeatureCatalogItem[];
    },
    staleTime: 10 * 60 * 1000,
  });
}

/** A subscription plan (GET /admin/plans). */
export interface Plan {
  key: string;
  label: string;
  price: number;
  features: string[];
}

export function usePlans() {
  return useQuery({
    queryKey: ["admin", "plans"],
    queryFn: async (): Promise<Plan[]> => {
      const { data } = await apiClient.get("/admin/plans");
      return data.data as Plan[];
    },
    staleTime: 10 * 60 * 1000,
  });
}

/** One tier of a system, e.g. Inventory → Pro. */
export interface SystemTier {
  key: string;
  label: string;
  price: number;
  /** Feature keys the tier bundles (the base — add-ons stack on top). */
  features: string[];
}

/** A priced à-la-carte function that stacks on a system's tier. */
export interface SystemAddon {
  key: string;
  label: string;
  price: number;
}

/** A SaaS-ready system a tenant can avail (GET /admin/systems). */
export interface SystemCatalog {
  key: string;
  label: string;
  tiers: SystemTier[];
  addons: SystemAddon[];
}

export function useSystems() {
  return useQuery({
    queryKey: ["admin", "systems"],
    queryFn: async (): Promise<SystemCatalog[]> => {
      const { data } = await apiClient.get("/admin/systems");
      return data.data as SystemCatalog[];
    },
    staleTime: 10 * 60 * 1000,
  });
}

export interface CreateOrgPayload {
  name: string;
  admin_name: string;
  admin_email: string;
  admin_password: string;
  /** Availed systems → their chosen tier, e.g. { accounting: "basic", inventory: "pro" }. */
  systems: Record<string, string>;
  /** Add-on feature keys on top of the tiers (tier bases are added server-side). */
  features: string[];
}

/** Prepaid-suspend subscription summary (GET/POST admin org endpoints). */
export interface OrgSubscription {
  plan: string;
  monthly_price: number;
  /** User seat cap (base 10; more in blocks of 10). */
  seat_limit: number;
  /** Seats currently used (members of the org). */
  seats_used: number;
  status: "pending" | "trialing" | "active" | "suspended" | "cancelled";
  is_active: boolean;
  current_period_end: string | null;
}

export interface CreatedOrg {
  org: { id: number; name: string };
  admin: { id: number; email: string };
  features: string[];
  subscription: OrgSubscription;
}

export function useCreateOrg() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateOrgPayload): Promise<CreatedOrg> => {
      const { data } = await apiClient.post("/admin/orgs", payload);
      return data.data as CreatedOrg;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "orgs"] }),
  });
}

/** A tenant + its subscription, for the billing view (GET /admin/orgs). */
export interface OrgRow {
  id: number;
  name: string;
  subscription: OrgSubscription | null;
}

export function useOrgs() {
  return useQuery({
    queryKey: ["admin", "orgs"],
    queryFn: async (): Promise<OrgRow[]> => {
      const { data } = await apiClient.get("/admin/orgs");
      return data.data as OrgRow[];
    },
  });
}

/** Deferred-revenue recognition schedules + current Unearned Revenue balance. */
export interface RevenueSchedule {
  id: number;
  description: string | null;
  monthly_amount: number;
  months: number;
  recognized_count: number;
  remaining: number;
  next_recognition_date: string | null;
  status: "active" | "completed";
  deferred_remaining: number;
}
export interface RevenueSchedulesResult {
  unearned_balance: number;
  schedules: RevenueSchedule[];
}

export function useRevenueSchedules() {
  return useQuery({
    queryKey: ["admin", "revenue-schedules"],
    queryFn: async (): Promise<RevenueSchedulesResult> => {
      const { data } = await apiClient.get("/admin/revenue-schedules");
      return data.data as RevenueSchedulesResult;
    },
  });
}

/** Record a payment: (re)activate + extend the paid period by `months`. */
export function useActivateOrg() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ orgId, months }: { orgId: number; months: number }) => {
      const { data } = await apiClient.post(`/admin/orgs/${orgId}/activate`, { months });
      return data.data as OrgSubscription;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "orgs"] }),
  });
}

/** Set an org's user seat cap (blocks of 10; base 10). Adjusts monthly price. */
export function useSetSeats() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ orgId, seat_limit }: { orgId: number; seat_limit: number }) => {
      const { data } = await apiClient.post(`/admin/orgs/${orgId}/seats`, { seat_limit });
      return data.data as OrgSubscription;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "orgs"] }),
  });
}

/** Pause a tenant's access. */
export function useSuspendOrg() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (orgId: number) => {
      const { data } = await apiClient.post(`/admin/orgs/${orgId}/suspend`, {});
      return data.data as OrgSubscription;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "orgs"] }),
  });
}

/** Un-pause a suspended tenant WITHOUT charging (only if paid time remains). */
export function useResumeOrg() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (orgId: number) => {
      const { data } = await apiClient.post(`/admin/orgs/${orgId}/resume`, {});
      return data.data as OrgSubscription;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "orgs"] }),
  });
}

/** A recorded subscription payment (for the history / reverse view). */
export interface PaymentRow {
  id: number;
  months: number;
  total_amount: number;
  recorded_at: string | null;
  status: "recorded" | "reversed";
  reversed_at: string | null;
  can_reverse: boolean;
}

export function useOrgPayments(orgId: number | null) {
  return useQuery({
    queryKey: ["admin", "org-payments", orgId],
    enabled: orgId != null,
    queryFn: async (): Promise<PaymentRow[]> => {
      const { data } = await apiClient.get(`/admin/orgs/${orgId}/payments`);
      return data.data as PaymentRow[];
    },
  });
}

/** Reverse (undo) a mistaken/duplicate payment — the most recent one. */
export function useReversePayment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (paymentId: number) => {
      await apiClient.post(`/admin/payments/${paymentId}/reverse`, {});
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "orgs"] });
      qc.invalidateQueries({ queryKey: ["admin", "org-payments"] });
      qc.invalidateQueries({ queryKey: ["admin", "revenue-schedules"] });
    },
  });
}

/** Correct the paid-until date — fixes a mistaken/duplicate payment. */
export function useAdjustOrg() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ orgId, current_period_end }: { orgId: number; current_period_end: string }) => {
      const { data } = await apiClient.post(`/admin/orgs/${orgId}/adjust`, { current_period_end });
      return data.data as OrgSubscription;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "orgs"] }),
  });
}

/**
 * Expand a selected feature set to include all transitive dependencies, using
 * the catalog's `requires` links — mirrors the backend so the UI shows the true
 * set that will be enabled (e.g. picking "Invoicing" pulls in "Accounting").
 */
export function resolveFeatureDeps(
  selected: Set<string>,
  catalog: FeatureCatalogItem[],
): Set<string> {
  const byKey = new Map(catalog.map((f) => [f.key, f]));
  const out = new Set<string>();

  const visit = (key: string) => {
    if (out.has(key) || !byKey.has(key)) return;
    out.add(key);
    for (const dep of byKey.get(key)!.requires) visit(dep);
  };

  for (const key of selected) visit(key);
  return out;
}
