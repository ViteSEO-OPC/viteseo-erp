import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

/** The current org's subscription/billing state (prepaid-suspend model). */
export interface OrgSubscription {
  plan: string;
  status: "pending" | "trialing" | "active" | "suspended" | "cancelled";
  is_active: boolean;
  current_period_end: string | null;
  monthly_price: number;
}

/** The current org's availed functions (see backend GET /me/features). */
export interface OrgFeatures {
  org_id: number | null;
  /** All availed feature keys, e.g. ["accounting", "accounting.invoicing", "hr"]. */
  features: string[];
  /** The subset of enabled module-level keys, e.g. ["hr", "accounting"]. */
  modules: string[];
  /** Billing state — null for orgs with no subscription (e.g. the provider). */
  subscription: OrgSubscription | null;
}

/**
 * Fetches which functions the current org has availed. Used by the launcher to
 * gate module tiles by entitlement (on top of the user's roles/permissions).
 * Entitlements rarely change within a session, so this is cached generously.
 */
export function useFeatures() {
  return useQuery({
    queryKey: ["me", "features"],
    queryFn: async (): Promise<OrgFeatures> => {
      const { data } = await apiClient.get("/me/features");
      return data.data as OrgFeatures;
    },
    staleTime: 5 * 60 * 1000,
  });
}
