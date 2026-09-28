"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  CustomTemplateOption,
  TemplateOptionType,
  TemplateOptionsCatalog,
} from "@/modules/crm/features/email-templates/types/email-template-types";

/**
 * Custom email-template purposes and tones (Settings → Email Templates). Every route is `role:super_admin|crm_admin`
 * server-side and organization-scoped.
 */

const ENDPOINT = "/settings/email-template-options";

/** The merged built-in and custom purposes and tones, each custom value with how many templates use it. */
export function useTemplateOptions(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.emailTemplates.options(),
    queryFn: async (): Promise<TemplateOptionsCatalog> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as TemplateOptionsCatalog;
    },
    enabled,
  });
}

/**
 * A new or deleted option changes the template catalog (and its dropdowns) and the Outreach composer's per-lead
 * template lists, so all of them are marked stale.
 */
function useInvalidateOptions() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.emailTemplates.all });
    queryClient.invalidateQueries({ predicate: (query) => query.queryKey.at(-1) === "email-templates" });
  };
}

/** Errors are shown in the add dialog, so there is no error toast. */
export function useCreateTemplateOption() {
  const invalidate = useInvalidateOptions();

  return useMutation({
    mutationFn: async (payload: { type: TemplateOptionType; value: string }): Promise<CustomTemplateOption> => {
      const res = await apiClient.post(ENDPOINT, payload);
      return res.data.data as CustomTemplateOption;
    },
    onSuccess: (option) => {
      invalidate();
      toast.success(`Custom ${option.type} “${option.value}” added`);
    },
  });
}

export function useDeleteTemplateOption() {
  const invalidate = useInvalidateOptions();

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`${ENDPOINT}/${id}`);
    },
    onSuccess: () => {
      invalidate();
      toast.success("Custom value deleted");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not delete the custom value")),
  });
}
