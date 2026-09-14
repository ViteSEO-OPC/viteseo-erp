"use client";

import { isAxiosError } from "axios";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  EmailTemplate,
  EmailTemplateCatalog,
  EmailTemplatePayload,
} from "@/modules/crm/features/email-templates/types/email-template-types";

/**
 * Settings → Email Templates data. Every route is `role:super_admin|crm_admin` server-side; the page
 * sits under /zerem/settings, which is already management-only, and the server is what refuses a BD.
 */

const ENDPOINT = "/settings/email-templates";

export function useEmailTemplatesAdmin() {
  const query = useQuery({
    queryKey: queryKeys.emailTemplates.admin(),
    queryFn: async (): Promise<{ templates: EmailTemplate[]; catalog: EmailTemplateCatalog }> => {
      const res = await apiClient.get(ENDPOINT);
      return { templates: res.data.data as EmailTemplate[], catalog: res.data.meta as EmailTemplateCatalog };
    },
  });

  return {
    templates: query.data?.templates ?? [],
    catalog: query.data?.catalog ?? null,
    isLoading: query.isLoading,
    isError: query.isError,
    retry: query.refetch,
  };
}

/**
 * A saved template changes what the Outreach composer offers, and the composer caches templates PER
 * LEAD under leads.emailTemplates(id) — so every lead's copy is marked stale along with this list.
 */
function useInvalidateTemplates() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.emailTemplates.all });
    queryClient.invalidateQueries({ predicate: (query) => query.queryKey.at(-1) === "email-templates" });
  };
}

/** The server's first message per rejected field, for placing on the form field it belongs to. */
export function apiFieldErrors(err: unknown): Record<string, string> {
  if (!isAxiosError(err)) return {};
  const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};

  return Object.fromEntries(
    Object.entries(errors).flatMap(([field, messages]) => (messages?.[0] ? [[field, messages[0]]] : [])),
  );
}

export function useCreateEmailTemplate() {
  const invalidate = useInvalidateTemplates();

  return useMutation({
    mutationFn: async (payload: EmailTemplatePayload) => {
      const res = await apiClient.post(ENDPOINT, payload);
      return res.data.data as EmailTemplate;
    },
    onSuccess: () => {
      invalidate();
      toast.success("Template created");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not create the template")),
  });
}

export function useUpdateEmailTemplate() {
  const invalidate = useInvalidateTemplates();

  return useMutation({
    mutationFn: async ({ id, ...body }: { id: string } & Partial<EmailTemplatePayload>) => {
      const res = await apiClient.patch(`${ENDPOINT}/${id}`, body);
      return res.data.data as EmailTemplate;
    },
    onSuccess: (template, variables) => {
      invalidate();
      const toggleOnly = Object.keys(variables).length === 2 && "is_active" in variables;
      toast.success(
        toggleOnly ? (template.isActive ? "Template activated" : "Template deactivated") : "Template saved",
      );
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not update the template")),
  });
}

export function useDeleteEmailTemplate() {
  const invalidate = useInvalidateTemplates();

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`${ENDPOINT}/${id}`);
    },
    onSuccess: () => {
      invalidate();
      toast.success("Template deleted");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not delete the template")),
  });
}
