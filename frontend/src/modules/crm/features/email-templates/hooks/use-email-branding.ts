"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { CropRect } from "@/modules/crm/features/email-templates/lib/logo-crop";
import type { EmailBranding, LogoInspection } from "@/modules/crm/features/email-templates/types/email-design-types";

/**
 * Settings → Email Designs → Organization logo. Every route is `role:super_admin|crm_admin` server-side.
 *
 * An upload is two requests. `inspect` sends the file and stores nothing: the server decides the format from the
 * bytes and, for an SVG, returns its sanitized version, which is what the cropper shows. `upload` sends the file
 * again with the crop — and for an SVG the PNG drawn from the sanitized SVG — and the server re-checks all of it.
 */

const ENDPOINT = "/settings/email-branding";

// Let the browser set the multipart boundary; a hardcoded Content-Type would omit it.
const MULTIPART = { headers: { "Content-Type": "multipart/form-data" } };

export function useEmailBranding() {
  const query = useQuery({
    queryKey: queryKeys.emailBranding.detail(),
    queryFn: async (): Promise<EmailBranding> => {
      const res = await apiClient.get(ENDPOINT);
      return res.data.data as EmailBranding;
    },
  });

  return {
    logo: query.data?.logo ?? null,
    isLoading: query.isLoading,
    isError: query.isError,
    retry: query.refetch,
  };
}

/**
 * A logo change reaches every design and template preview. Previews are cached by what was sent, which a logo
 * change does not alter, so they are marked stale along with the branding and the designs.
 */
function useInvalidateBranding() {
  const queryClient = useQueryClient();

  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.emailBranding.all });
    queryClient.invalidateQueries({ queryKey: queryKeys.emailTemplates.all });
    queryClient.invalidateQueries({ queryKey: queryKeys.emailDesigns.all });
  };
}

/** Checks a file before cropping. Errors are shown in the upload dialog, so there is no toast. */
export function useInspectLogo() {
  return useMutation({
    mutationFn: async (file: File): Promise<LogoInspection> => {
      const form = new FormData();
      form.append("file", file);
      const res = await apiClient.post(`${ENDPOINT}/logo/inspect`, form, MULTIPART);
      return res.data.data as LogoInspection;
    },
  });
}

export function useUploadLogo() {
  const invalidate = useInvalidateBranding();

  return useMutation({
    mutationFn: async ({ file, crop, rendition }: { file: File; crop: CropRect; rendition?: Blob }): Promise<EmailBranding> => {
      const form = new FormData();
      form.append("file", file);
      form.append("crop", JSON.stringify(crop));
      if (rendition) form.append("rendition", rendition, "rendition.png");
      const res = await apiClient.post(`${ENDPOINT}/logo`, form, MULTIPART);
      return res.data.data as EmailBranding;
    },
    onSuccess: () => {
      invalidate();
      toast.success("Organization logo saved");
    },
  });
}

export function useRemoveLogo() {
  const invalidate = useInvalidateBranding();

  return useMutation({
    mutationFn: async () => {
      await apiClient.delete(`${ENDPOINT}/logo`);
    },
    onSuccess: () => {
      invalidate();
      toast.success("Organization logo removed");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Could not remove the logo")),
  });
}
