"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { LEAD_SCORING } from "@/modules/crm/features/business-rules/types/business-rules-types";
import type {
  BusinessRuleAudit,
  BusinessRuleVersion,
} from "@/modules/crm/features/business-rules/types/business-rules-types";

const base = (module: string) => `/settings/business-rules/${module}`;

/** The active (published) configuration version and its rules. */
export function useActiveConfig(module: string = LEAD_SCORING) {
  return useQuery({
    queryKey: queryKeys.businessRules.active(module),
    queryFn: async (): Promise<BusinessRuleVersion> => {
      const res = await apiClient.get(`${base(module)}/active`);
      return res.data.data;
    },
  });
}

/** The current working draft, or null when nothing has been edited yet (the API returns {data:null}). */
export function useDraft(module: string = LEAD_SCORING) {
  return useQuery({
    queryKey: queryKeys.businessRules.draft(module),
    queryFn: async (): Promise<BusinessRuleVersion | null> => {
      const res = await apiClient.get(`${base(module)}/draft`);
      return res.data.data ?? null;
    },
  });
}

/** The immutable version timeline (newest first). */
export function useVersions(module: string = LEAD_SCORING) {
  return useQuery({
    queryKey: queryKeys.businessRules.versions(module),
    queryFn: async (): Promise<BusinessRuleVersion[]> => {
      const res = await apiClient.get(`${base(module)}/versions`);
      return res.data.data;
    },
  });
}

/** The append-only audit history (newest first). */
export function useAudit(module: string = LEAD_SCORING) {
  return useQuery({
    queryKey: queryKeys.businessRules.audit(module),
    queryFn: async (): Promise<BusinessRuleAudit[]> => {
      const res = await apiClient.get(`${base(module)}/audit`);
      return res.data.data;
    },
  });
}
