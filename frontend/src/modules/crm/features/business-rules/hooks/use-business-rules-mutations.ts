"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { LEAD_SCORING } from "@/modules/crm/features/business-rules/types/business-rules-types";
import type {
  BusinessRule,
  BusinessRuleVersion,
} from "@/modules/crm/features/business-rules/types/business-rules-types";

const base = (module: string) => `/settings/business-rules/${module}`;

/**
 * All Business Rules writes invalidate the whole module prefix — a single publish/rollback/edit can change
 * the active config, the draft, the version list, and the audit at once, so refreshing them together keeps
 * every panel consistent. Errors surface the backend's `{message}` (the UI never re-implements a rule).
 */
function useInvalidateModule(module: string) {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: queryKeys.businessRules.all(module) });
}

/** Create (or return) the working draft — a copy of the active version on first edit. */
export function useEnsureDraft(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: async (): Promise<BusinessRuleVersion> => {
      const res = await apiClient.post(`${base(module)}/draft`);
      return res.data.data;
    },
    onSuccess: () => invalidate(),
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to open a draft")),
  });
}

/** Edit a rule's point value and/or description on the draft. */
export function useUpdateRule(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: async ({
      ruleKey,
      payload,
    }: {
      ruleKey: string;
      payload: { point_value?: number; description?: string };
    }): Promise<BusinessRule> => {
      const res = await apiClient.patch(`${base(module)}/rules/${ruleKey}`, payload);
      return res.data.data;
    },
    onSuccess: () => invalidate(),
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to update the rule")),
  });
}

/** Enable or disable a rule on the draft (a disabled rule contributes 0). */
export function useSetRuleEnabled(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: async ({
      ruleKey,
      enabled,
    }: {
      ruleKey: string;
      enabled: boolean;
    }): Promise<BusinessRule> => {
      const res = await apiClient.post(`${base(module)}/rules/${ruleKey}/${enabled ? "enable" : "disable"}`);
      return res.data.data;
    },
    onSuccess: () => invalidate(),
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to change the rule state")),
  });
}

/** Set the display order on the draft (presentation only). */
export function useReorderRules(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: async (order: string[]): Promise<BusinessRuleVersion> => {
      const res = await apiClient.patch(`${base(module)}/rules/order`, { order });
      return res.data.data;
    },
    onSuccess: () => invalidate(),
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to reorder the rules")),
  });
}

/** Discard the working draft (nothing published changes). */
export function useDiscardDraft(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: () => apiClient.delete(`${base(module)}/draft`),
    onSuccess: () => {
      invalidate();
      toast.success("Draft discarded");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to discard the draft")),
  });
}

/** Publish the current draft → new active version; recomputation runs in the background. */
export function usePublish(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: async (reason?: string): Promise<BusinessRuleVersion> => {
      const res = await apiClient.post(`${base(module)}/publish`, { reason });
      return res.data.data;
    },
    onSuccess: (version) => {
      invalidate();
      toast.success(`Published version ${version.versionNumber}. Scores are recomputing.`);
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to publish")),
  });
}

/** Forward-only rollback to a previously published version → new active version. */
export function useRollback(module: string = LEAD_SCORING) {
  const invalidate = useInvalidateModule(module);
  return useMutation({
    mutationFn: async ({
      targetVersion,
      reason,
    }: {
      targetVersion: number;
      reason?: string;
    }): Promise<BusinessRuleVersion> => {
      const res = await apiClient.post(`${base(module)}/rollback`, {
        target_version: targetVersion,
        reason,
      });
      return res.data.data;
    },
    onSuccess: (version) => {
      invalidate();
      toast.success(`Rolled back — now active as version ${version.versionNumber}. Scores are recomputing.`);
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to roll back")),
  });
}
