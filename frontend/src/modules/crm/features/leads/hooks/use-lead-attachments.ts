"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { previewKindFor, type PreviewKind } from "@/modules/crm/features/leads/lib/attachment-preview";
import {
  decodeAttachmentText,
  MAX_PREVIEW_BYTES,
  type DecodedText,
} from "@/modules/crm/features/leads/lib/attachment-text";

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
  /**
   * True when THIS server can render this attachment's Office format (DOC/DOCX/PPT/PPTX) to PDF.
   * Optional so a payload from a backend that predates it simply means "no" — Download-only.
   */
  documentPreview?: boolean;
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

/** The bounded spreadsheet window returned by GET .../attachments/{id}/preview. */
export type SheetPreview = {
  kind: "sheet";
  sheetName: string;
  sheetCount: number;
  /** Row-major, already truncated and stringified server-side. Rendered as text, never as markup. */
  rows: string[][];
  /** The sheet's REAL size, so the viewer can say what it is not showing. */
  totalRows: number;
  totalColumns: number;
  truncated: boolean;
  maxRows: number;
  maxColumns: number;
};

/**
 * The attachment currently open in the viewer.
 *
 * One shape for every kind rather than a union, because the dialog switches on `kind` anyway and a
 * union would push a discriminator check into every consumer for no additional safety. Exactly one
 * of `url` / `text` / `sheet` is populated; which one is determined by `kind`:
 *
 *   image, pdf  → `url`   an object URL over the downloaded bytes
 *   text, csv   → `text`  bytes decoded and bounded client-side
 *   sheet       → `sheet` a bounded JSON window parsed server-side
 */
export type AttachmentPreview = {
  id: string;
  name: string;
  mimeType: string;
  kind: PreviewKind | null;
  url: string | null;
  text: DecodedText | null;
  sheet: SheetPreview | null;
};

/**
 * Open one attachment for inline viewing.
 *
 * THREE STRATEGIES, ONE AUTHORIZATION MODEL. Images and PDFs, and now TXT and CSV, come from the
 * SAME authorized download endpoint as `useDownloadAttachment` — the bytes are on the private disk,
 * so a bare `<img src>` would arrive without the Bearer token and 401. The response's
 * `Content-Disposition: attachment` is irrelevant: that header governs navigation, and reading the
 * body as a blob bypasses it entirely. Spreadsheets instead call the dedicated preview endpoint,
 * which is guarded by exactly the same policy and scope binding, because parsing a workbook belongs
 * on the server and its output is a bounded JSON window rather than a file.
 *
 * No new access path and no public URL was introduced for any of them.
 *
 * TXT AND CSV ARE BOUNDED AT THE BLOB, NOT AFTER DECODING. `blob.slice` caps the ArrayBuffer before
 * a string is ever built, so a huge text file costs one megabyte of memory rather than freezing the
 * tab; the extra byte is what lets the decoder distinguish "exactly at the cap" from "longer than
 * the cap" without a second size parameter.
 *
 * Object-URL lifecycle is the one thing this hook must get right, and it now applies to fewer kinds:
 * only image and pdf allocate a URL. Unlike the download path — which revokes immediately after the
 * click — a preview holds its URL for as long as the viewer is open, so it is revoked on close, when
 * a different attachment replaces it, and on unmount. The ref mirrors the state purely so the
 * unmount cleanup can revoke without re-running on every change.
 */
export function usePreviewAttachment(leadId: string) {
  const [preview, setPreview] = useState<AttachmentPreview | null>(null);
  const [previewingId, setPreviewingId] = useState<string | null>(null);
  const urlRef = useRef<string | null>(null);

  const release = useCallback(() => {
    if (urlRef.current) {
      URL.revokeObjectURL(urlRef.current);
      urlRef.current = null;
    }
  }, []);

  // Revoke on unmount — closing the parent modal unmounts this without a close() call.
  useEffect(() => release, [release]);

  const openPreview = useCallback(
    async (attachment: Pick<LeadAttachment, "id" | "name" | "mimeType" | "documentPreview">) => {
      const kind = previewKindFor(attachment.mimeType, attachment.name, {
        documentPreview: attachment.documentPreview,
      });
      setPreviewingId(attachment.id);

      const base: Omit<AttachmentPreview, "url" | "text" | "sheet"> = {
        id: attachment.id,
        name: attachment.name,
        mimeType: attachment.mimeType,
        kind,
      };

      try {
        if (kind === "sheet") {
          const res = await apiClient.get(
            `/leads/${leadId}/attachments/${attachment.id}/preview`,
          );

          // No object URL for this kind, but a previously open image or PDF still holds one.
          release();
          setPreview({ ...base, url: null, text: null, sheet: res.data.data as SheetPreview });
          return;
        }

        // Office documents come from their own endpoint as the PDF the server rendered; every other
        // kind is the original bytes from the download endpoint. Both are blobs from here on, and a
        // document renders through the PDF path below.
        const res = await apiClient.get(
          kind === "document"
            ? `/leads/${leadId}/attachments/${attachment.id}/document-preview`
            : `/leads/${leadId}/attachments/${attachment.id}/download`,
          { responseType: "blob" },
        );
        const blob = res.data as Blob;

        if (kind === "text" || kind === "csv") {
          // Bound BEFORE decoding: one extra byte over the cap is enough to know the file was
          // longer, without holding the whole thing in memory to find out.
          const buffer = await blob.slice(0, MAX_PREVIEW_BYTES + 1).arrayBuffer();

          release();
          setPreview({ ...base, url: null, text: decodeAttachmentText(buffer), sheet: null });
          return;
        }

        // Replacing an open preview must not strand the previous URL.
        release();
        const url = URL.createObjectURL(blob);
        urlRef.current = url;
        setPreview({ ...base, url, text: null, sheet: null });
      } catch (err) {
        // Both preview endpoints answer a file they cannot show with their own safe sentence — the
        // spreadsheet's "corrupt, password-protected, or an unsupported format", the document's
        // "could not be prepared" or "try again in a moment" — and it tells the user what to do
        // next, so it is surfaced rather than the generic line. It is always a server constant,
        // never an exception message. The document endpoint is requested as a blob, so its error
        // body arrives as a Blob and is read back as JSON first. Only for that kind: an image, PDF,
        // TXT or CSV failure comes from the download endpoint and keeps the generic line it always
        // showed.
        const fallback = "Could not open the file. Please try again.";
        const body = (err as { response?: { data?: unknown } } | null)?.response?.data;
        let message = getApiErrorMessage(err, fallback);
        if (kind === "document" && body instanceof Blob) {
          try {
            const parsed = JSON.parse(await body.text()) as { message?: unknown };
            message =
              typeof parsed.message === "string" && parsed.message.trim() !== ""
                ? parsed.message
                : fallback;
          } catch {
            message = fallback;
          }
        }
        toast.error(message);
      } finally {
        setPreviewingId(null);
      }
    },
    [leadId, release],
  );

  const closePreview = useCallback(() => {
    release();
    setPreview(null);
  }, [release]);

  return { preview, previewingId, openPreview, closePreview };
}
