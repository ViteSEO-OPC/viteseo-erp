"use client";

import { useState } from "react";
import { FolderOpen } from "lucide-react";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { FileTypeIcon } from "@/modules/crm/features/leads/components/file-type-icon";
import { LeadAttachmentRowActions } from "@/modules/crm/features/leads/components/lead-attachment-row-actions";
import { LeadAttachmentPreviewDialog } from "@/modules/crm/features/leads/components/lead-attachment-preview-dialog";
import {
  useLeadAttachments,
  useDeleteAttachment,
  useDownloadAttachment,
  usePreviewAttachment,
  type LeadAttachment,
} from "@/modules/crm/features/leads/hooks/use-lead-attachments";
import { formatFileTypeLabel } from "@/modules/crm/features/leads/lib/attachment-format";

/**
 * Five rows, then scroll.
 *
 * Row height (64px) plus the 8px gap between rows, times five, plus one gap of bleed so the sixth
 * row is visibly cut off rather than hidden — which is what tells the reader the list continues.
 * Fixing the max height here is what keeps the CARD height stable and confines scrolling to the
 * list, so a lead with 50 files is exactly as tall as a lead with 5 and the page never runs long.
 */
const FIVE_ROWS = 5 * 64 + 5 * 8;

type Props = {
  leadId: string;
};

/**
 * Quick access to a lead's documents, below the Activity Timeline / Call History panel.
 *
 * Read-only by design: uploading still belongs to the header's Attachment action and its modal, so
 * this card adds a fast path to open or retrieve a file WITHOUT becoming a second management
 * surface. Files are deliberately kept out of the Activity Timeline — a document is not an event.
 *
 * Everything here reuses what the modal already uses: the same per-lead query (so opening the modal
 * costs no extra request and a delete on either surface updates both), the same authorized
 * blob preview and download, and the same shared row actions.
 */
export function LeadFilesCard({ leadId }: Props) {
  const { attachments, isLoading, isError } = useLeadAttachments(leadId);
  const remove = useDeleteAttachment(leadId);
  const { downloadAttachment, downloadingId } = useDownloadAttachment(leadId);
  const { preview, previewingId, openPreview, closePreview } = usePreviewAttachment(leadId);

  // Deleting destroys the bytes on disk for good, so the row control only ever sets this — the
  // mutation is reachable from the dialog's Confirm and nowhere else.
  const [pendingDelete, setPendingDelete] = useState<LeadAttachment | null>(null);

  const files = attachments ?? [];
  const count = files.length;
  const scrolls = count > 5;

  function confirmDelete() {
    if (!pendingDelete) return;
    remove.mutate(pendingDelete.id, { onSettled: () => setPendingDelete(null) });
  }

  return (
    // Its OWN height, not its row's (`xl:self-start`). From xl this card shares a subgrid row with the
    // Email Conversation beside it, and a grid item stretches to its row by default — so it used to
    // grow to whatever height the email history and composer reached, and showed a mostly empty
    // card. Its intended size is its content: the header plus at most five file rows (FIVE_ROWS),
    // after which the list scrolls, so a lead with 50 files is as tall as a lead with 5 and nothing
    // beside it can make it taller. `xl:` ONLY: below xl this column is a flex column, where
    // `self-start` aligns HORIZONTALLY and would shrink the card's width — the opposite of intended.
    //
    // `contain-inline-size`: the card's WIDTH never depends on what is inside it. Without it, a long
    // filename widened the whole right-hand column — the row already truncates correctly, but a
    // `truncate` line's min-content width is the full name, and that width climbed the tree to the
    // page's `lg:grid-cols-[76fr_24fr]`, whose bare `fr` tracks cannot shrink below their content.
    // Containment stops it at the card, at every breakpoint and in both the flex-column (below xl)
    // and subgrid (xl) layouts of that column, so the parent grid alone decides the width and the
    // name, not the card, is what gives way.
    <div className="flex flex-col rounded-2xl border border-border bg-card p-6 shadow-card contain-inline-size xl:self-start">
      <h3 className="mb-5 text-lg font-bold text-card-foreground">
        Files &amp; Downloads
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

      {isError && <ErrorState message="Failed to load files" />}

      {!isLoading && !isError && count === 0 && (
        // The frame keeps its natural size. The card now sizes to its content at every width (see
        // `xl:self-start` above), so this `flex-1` wrapper has no spare height to fill and simply
        // holds the frame; it stays so that, if the card is ever stretched again, the empty state
        // centres in that height instead of becoming one enormous dashed rectangle.
        <div className="flex flex-1 items-center">
          {/* Horizontal padding is what keeps the text off the dashed border — the box used to have
              vertical padding only. The hierarchy is icon → title → one short line of help, and the
              description is capped at `max-w-xs` so on a full-width card (tablet, mobile) it stays a
              readable block rather than one long line. */}
          <div className="flex w-full flex-col items-center rounded-xl border border-dashed border-border px-6 py-10 text-center">
            <span className="flex h-11 w-11 items-center justify-center rounded-full bg-muted">
              <FolderOpen className="h-5 w-5 text-muted-foreground" aria-hidden />
            </span>
            <p className="mt-3 text-sm font-semibold text-card-foreground">No files yet</p>
            {/* "Supported files", deliberately not a list. What the viewer can render is decided by
                previewKindFor in lib/attachment-preview.ts: images, PDF, TXT, CSV and XLS/XLSX
                everywhere, DOC/DOCX/PPT/PPTX only where the SERVER can convert them (the attachment's
                `documentPreview` flag), and ZIP never. Naming Office formats here would promise a
                preview a server without LibreOffice cannot give — only the ZIP rule is universal. */}
            <p className="mt-1.5 max-w-xs text-xs leading-5 text-balance text-muted-foreground">
              Use <span className="font-medium text-card-foreground">Attachment</span> above to add
              proposals, contracts, and screenshots. Supported files open in preview; ZIP files are
              download-only.
            </p>
          </div>
        </div>
      )}

      {!isLoading && !isError && count > 0 && (
        <ul
          className="flex flex-col gap-2 overflow-y-auto"
          style={{ maxHeight: FIVE_ROWS }}
          // Only announce a scrollable region when it actually scrolls, so five-or-fewer files
          // don't present a pointless scroll container to assistive tech.
          {...(scrolls ? { tabIndex: 0, role: "region", "aria-label": "Files list" } : {})}
        >
          {files.map((file) => (
            <li
              key={file.id}
              // shrink-0 is load-bearing: this is a flex column with a max height, so without it the
              // rows compress to fit instead of overflowing — the list would silently show all of
              // them at reduced height rather than five at full height plus a scrollbar.
              className="flex h-16 shrink-0 items-center gap-3 rounded-xl border border-border bg-background px-4"
            >
              <FileTypeIcon name={file.name} mimeType={file.mimeType} />

              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-card-foreground" title={file.name}>
                  {file.name}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                  {formatFileTypeLabel(file.name)} · {file.sizeLabel}
                </p>
              </div>

              <LeadAttachmentRowActions
                file={file}
                onPreview={openPreview}
                onDownload={downloadAttachment}
                onDelete={setPendingDelete}
                previewingId={previewingId}
                downloadingId={downloadingId}
                deleting={remove.isPending}
                buttonClassName="p-2"
              />
            </li>
          ))}
        </ul>
      )}

      <LeadAttachmentPreviewDialog
        preview={preview}
        onClose={closePreview}
        onDownload={() => preview && downloadAttachment(preview)}
        downloading={preview !== null && downloadingId === preview.id}
      />

      {/* Deletion removes the bytes from disk for good — only the metadata row is soft-deleted — so
          the copy says exactly that rather than the softer "this can't be undone". */}
      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(next) => !next && setPendingDelete(null)}
        title="Delete attachment?"
        description={
          pendingDelete
            ? `"${pendingDelete.name}" will be permanently deleted from this lead. This is irreversible — the file cannot be recovered or downloaded again.`
            : undefined
        }
        confirmLabel="Delete File"
        onConfirm={confirmDelete}
        loading={remove.isPending}
        destructive
      />
    </div>
  );
}
