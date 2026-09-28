"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { ApplyToAllSummary, EmailDesign } from "@/modules/crm/features/email-templates/types/email-design-types";

/**
 * Settings → Email Designs data. Every route is `role:super_admin|crm_admin` server-side. The server creates
 * the organization's default design the first time the list is read, so the list is never empty.
 */

const ENDPOINT = "/settings/email-designs";

const NO_DESIGNS: EmailDesign[] = [];

export type EmailDesignPayload = {
  name: string;
  /** The layout as one JSON-encoded string. */
  layout: string;
  /** Also point every designed template in the organization at this design. Plain-text templates are never changed. */
  apply_to_all?: boolean;
  /** The number of designed templates the admin confirmed; the server refuses the save if it has changed. */
  apply_to_all_count?: number;
};

export function useEmailDesigns() {
  const query = useQuery({
    queryKey: queryKeys.emailDesigns.list(),
    queryFn: async (): Promise<EmailDesign[]> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as EmailDesign[];
    },
  });

  const designs = query.data ?? NO_DESIGNS;

  return {
    designs,
    defaultDesign: designs.find((design) => design.isDefault) ?? null,
    isLoading: query.isLoading,
    isError: query.isError,
    retry: query.refetch,
  };
}

/**
 * A design change reaches every template that uses it: the server regenerates their plain-text bodies. So
 * the template list, and the composer's per-lead template copies under leads.emailTemplates(id), are marked
 * stale along with the designs.
 */
function useInvalidateDesigns() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.emailDesigns.all });
    queryClient.invalidateQueries({ queryKey: queryKeys.emailTemplates.all });
    queryClient.invalidateQueries({ predicate: (query) => query.queryKey.at(-1) === "email-templates" });
  };
}

export function useCreateEmailDesign() {
  const invalidate = useInvalidateDesigns();

  return useMutation({
    mutationFn: async (payload: EmailDesignPayload) => {
      const res = await apiClient.post(ENDPOINT, payload);
      return res.data.data as EmailDesign;
    },
    onSuccess: (_design, payload) => {
      invalidate();
      toast.success(payload.apply_to_all ? "Design created and applied to all designed templates" : "Design created");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not create the design")),
  });
}

export function useUpdateEmailDesign() {
  const invalidate = useInvalidateDesigns();

  return useMutation({
    mutationFn: async ({ id, ...body }: { id: string } & Partial<EmailDesignPayload>) => {
      const res = await apiClient.patch(`${ENDPOINT}/${id}`, body);
      return res.data.data as EmailDesign;
    },
    onSuccess: (_design, variables) => {
      invalidate();
      const applyOnly = variables.name === undefined && variables.layout === undefined;
      toast.success(
        !variables.apply_to_all
          ? "Design saved"
          : applyOnly
            ? "Design applied to all designed templates"
            : "Design saved and applied to all designed templates",
      );
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not save the design")),
  });
}

/**
 * What "Apply to all templates" would change, fetched fresh each time the confirmation opens: how many designed
 * templates would use the design, how many switch from another design, and how many plain-text templates stay
 * as they are.
 */
export function useApplyToAllSummary(designId: string | null, enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.emailDesigns.applySummary(designId),
    queryFn: async (): Promise<ApplyToAllSummary> => {
      const res = await apiClient.get(`${ENDPOINT}/apply-to-all-summary`, {
        params: designId === null ? {} : { email_design_id: designId },
      });
      return res.data.data as ApplyToAllSummary;
    },
    enabled,
    staleTime: 0,
    gcTime: 0,
  });
}

export function useDeleteEmailDesign() {
  const invalidate = useInvalidateDesigns();

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`${ENDPOINT}/${id}`);
    },
    onSuccess: () => {
      invalidate();
      toast.success("Design deleted");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not delete the design")),
  });
}
