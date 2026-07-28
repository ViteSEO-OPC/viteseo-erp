"use client";

import { useCallback, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Raw API shape from GET /leads/{lead}/attachments (LeadAttachmentResource).
 *
 * `sizeLabel` is formatted server-side so every surface shows the same units from the same rule,
 * rather than each component reimplementing byte maths.
 */
export type LeadAttachment = {
  id: string;
  leadId: string;
  name: string;
  mimeType: string;
  size: number;
  sizeLabel: string;
  uploadedById: string | null;
  uploadedBy: string;
  createdAt: string | null;
};

/**
 * A lead's attachments, newest first (backend-ordered).
 *
 * The query key is nested under the lead, so the cache is partitioned BY LEAD — opening Lead B can
 * never read Lead A's entry. The request is likewise per-lead; there is no "all attachments" call.
 */
export function useLeadAttachments(leadId: string) {
  const query = useQuery({
    queryKey: queryKeys.leads.attachments(leadId),
    queryFn: () =>
      apiClient
        .get(`/leads/${leadId}/attachments`)
        .then((r) => r.data.data as LeadAttachment[]),
    enabled: !!leadId,
  });

  return {
    attachments: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}

/**
 * Invalidation after an attachment write, scoped to the CURRENT lead only.
 *
 * Only the attachment list is invalidated: uploading a file does not change the lead record, its
 * notes, or the global feeds, so a broader invalidation would refetch data that cannot have
 * changed. This is what keeps the card "refresh only after upload or delete".
 */
function useInvalidateAttachments(leadId: string) {
  const queryClient = useQueryClient();
  return () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.leads.attachments(leadId) });
  };
}

/** Upload a file against this lead. Sent as multipart; the backend derives all metadata. */
export function useUploadAttachment(leadId: string) {
  const invalidate = useInvalidateAttachments(leadId);

  return useMutation({
    mutationFn: (file: File) => {
      const form = new FormData();
      form.append("file", file);

      return apiClient
        .post(`/leads/${leadId}/attachments`, form, {
          // Let the browser set the multipart boundary; a hardcoded Content-Type would omit it.
          headers: { "Content-Type": "multipart/form-data" },
        })
        .then((r) => r.data.data as LeadAttachment);
    },
    onSuccess: () => {
      invalidate();
      toast.success("File uploaded");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to upload the file")),
  });
}

/** Delete an attachment (uploader/Admin/SA — enforced by the backend LeadAttachmentPolicy). */
export function useDeleteAttachment(leadId: string) {
  const invalidate = useInvalidateAttachments(leadId);

  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/leads/${leadId}/attachments/${id}`),
    onSuccess: () => {
      invalidate();
      toast.success("Attachment deleted");
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to delete the attachment")),
  });
}

/**
 * Download one attachment.
 *
 * Goes through apiClient rather than a bare <a href> because the file lives on the PRIVATE disk —
 * the request must carry auth headers for the backend to authorize it against the lead. Mirrors the
 * blob mechanics of useExportLeadPdf; the filename comes from Content-Disposition with the stored
 * display name as the fallback.
 */
export function useDownloadAttachment(leadId: string) {
  const [downloadingId, setDownloadingId] = useState<string | null>(null);

  const downloadAttachment = useCallback(
    async (attachment: Pick<LeadAttachment, "id" | "name">) => {
      setDownloadingId(attachment.id);
      try {
        const res = await apiClient.get(
          `/leads/${leadId}/attachments/${attachment.id}/download`,
          { responseType: "blob" },
        );

        const disposition = res.headers["content-disposition"] as string | undefined;
        const name = disposition?.match(/filename="?([^"]+)"?/)?.[1] ?? attachment.name;

        const href = URL.createObjectURL(res.data as Blob);
        const link = document.createElement("a");
        link.href = href;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(href);
      } catch {
        toast.error("Could not download the file. Please try again.");
      } finally {
        setDownloadingId(null);
      }
    },
    [leadId],
  );

  return { downloadAttachment, downloadingId };
}
