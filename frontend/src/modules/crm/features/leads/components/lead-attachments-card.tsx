"use client";

import { Download, Loader2, Paperclip, Trash2 } from "lucide-react";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { FileTypeIcon } from "@/modules/crm/features/leads/components/file-type-icon";
import { formatAttachmentTimestamp } from "@/modules/crm/features/leads/lib/attachment-format";
import {
  useLeadAttachments,
  useDeleteAttachment,
  useDownloadAttachment,
} from "@/modules/crm/features/leads/hooks/use-lead-attachments";

/**
 * Five rows, then scroll.
 *
 * Row height (64px) + the 8px gap between rows, times five, plus one gap of bleed so the sixth row
 * is visibly cut off rather than hidden — which is what tells the user the list continues. Fixing
 * the max height here is what keeps the CARD height stable and confines scrolling to the list, so a
 * lead with 50 files is exactly as tall as a lead with 5.
 */
const FIVE_ROWS = 5 * 64 + 5 * 8;

type Props = {
  leadId: string;
  /** Whether the current user may delete files (backend LeadAttachmentPolicy still enforces it). */
  canDelete: boolean;
};

export function LeadAttachmentsCard({ leadId, canDelete }: Props) {
  const { attachments, isLoading, isError } = useLeadAttachments(leadId);
  const remove = useDeleteAttachment(leadId);
  const { downloadAttachment, downloadingId } = useDownloadAttachment(leadId);

  const files = attachments ?? [];
  const count = files.length;

  return (
    <div className="rounded-2xl border border-border bg-card p-6 shadow-card">
      <h3 className="mb-5 text-lg font-bold text-card-foreground">
        Attachments
        {!isLoading && !isError && (
          <span className="ml-2 text-sm font-normal text-muted-foreground">({count})</span>
        )}
      </h3>

      {isLoading && (
        <div className="flex flex-col gap-2" aria-hidden>
          {[0, 1].map((i) => (
            <div key={i} className="h-16 animate-pulse rounded-xl bg-muted" />
          ))}
        </div>
      )}

      {isError && <ErrorState message="Failed to load attachments" />}

      {!isLoading && !isError && count === 0 && (
        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border py-10 text-center">
          <Paperclip className="h-6 w-6 text-muted-foreground" aria-hidden />
          <p className="text-sm font-semibold text-card-foreground">No attachments uploaded yet</p>
          <p className="text-xs text-muted-foreground">
            Use <span className="font-medium">Attachment</span> above to upload proposals,
            quotations, or permits for this lead.
          </p>
        </div>
      )}

      {!isLoading && !isError && count > 0 && (
        <ul
          className="flex flex-col gap-2 overflow-y-auto"
          style={{ maxHeight: FIVE_ROWS }}
          // Only announce a scrollable region when it actually scrolls, so five-or-fewer files
          // don't present a pointless scroll container to assistive tech.
          {...(count > 5 ? { tabIndex: 0, role: "region", "aria-label": "Attachments list" } : {})}
        >
          {files.map((file) => (
            <li
              key={file.id}
              // shrink-0 is load-bearing: this is a flex column with a max height, so without it
              // the rows compress to fit instead of overflowing — the list would silently show all
              // of them at reduced height rather than five at full height plus a scrollbar.
              className="flex h-16 shrink-0 items-center gap-3 rounded-xl border border-border bg-background px-4"
            >
              <FileTypeIcon name={file.name} mimeType={file.mimeType} />

              <div className="min-w-0 flex-1">
                <p
                  className="truncate text-sm font-medium text-card-foreground"
                  title={file.name}
                >
                  {file.name}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                  {file.sizeLabel} · {formatAttachmentTimestamp(file.createdAt)} · {file.uploadedBy}
                </p>
              </div>

              <div className="flex shrink-0 items-center gap-1">
                <button
                  type="button"
                  onClick={() => downloadAttachment(file)}
                  disabled={downloadingId === file.id}
                  className="rounded-md p-2 text-muted-foreground transition-colors hover:text-teal-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                  aria-label={`Download ${file.name}`}
                >
                  {downloadingId === file.id ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Download className="h-4 w-4" />
                  )}
                </button>

                {canDelete && (
                  <button
                    type="button"
                    onClick={() => remove.mutate(file.id)}
                    disabled={remove.isPending}
                    className="rounded-md p-2 text-muted-foreground transition-colors hover:text-red-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                    aria-label={`Delete ${file.name}`}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
