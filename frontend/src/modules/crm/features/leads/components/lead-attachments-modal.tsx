"use client";

import { useState } from "react";
import { Loader2, Paperclip } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { UploadDropzone } from "@/modules/crm/components/forms/file-upload/upload-dropzone";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { FileTypeIcon } from "@/modules/crm/features/leads/components/file-type-icon";
import { LeadAttachmentPreviewDialog } from "@/modules/crm/features/leads/components/lead-attachment-preview-dialog";
import { LeadAttachmentRowActions } from "@/modules/crm/features/leads/components/lead-attachment-row-actions";
import {
  useLeadAttachments,
  useUploadAttachment,
  useDeleteAttachment,
  useDownloadAttachment,
  usePreviewAttachment,
  type LeadAttachment,
} from "@/modules/crm/features/leads/hooks/use-lead-attachments";
import { formatAttachmentTimestamp } from "@/modules/crm/features/leads/lib/attachment-format";

/** Extensions the backend accepts (StoreLeadAttachmentRequest::ALLOWED_EXTENSIONS). */
const ACCEPT =
  ".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.png,.jpg,.jpeg,.webp,.gif,.zip";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  leadId: string;
  leadName: string;
};

/**
 * Upload and manage files for one lead.
 *
 * This modal is the only place files are UPLOADED, and it remains the full management surface. The
 * Lead Details page also shows a read-only Files & Downloads card for quick access; both read the
 * same per-lead query and share {@link LeadAttachmentRowActions}, so a delete on either surface
 * updates the other. Download and delete are offered to every role that can open the lead: Admin and
 * BD have identical rights over a lead's files, so there is no permission-driven variation in what
 * the row renders.
 */
export function LeadAttachmentsModal({ open, onOpenChange, leadId, leadName }: Props) {
  const { attachments, isLoading } = useLeadAttachments(leadId);
  const upload = useUploadAttachment(leadId);
  const remove = useDeleteAttachment(leadId);
  const { downloadAttachment, downloadingId } = useDownloadAttachment(leadId);
  const { preview, previewingId, openPreview, closePreview } = usePreviewAttachment(leadId);

  // The file awaiting confirmation. Deleting destroys the bytes on disk for good, so the row's
  // control only ever sets this — the mutation is reachable from the dialog's Confirm and nowhere
  // else, which is what makes an accidental click survivable.
  const [pendingDelete, setPendingDelete] = useState<LeadAttachment | null>(null);

  const files: LeadAttachment[] = attachments ?? [];
  const count = files.length;

  function confirmDelete() {
    if (!pendingDelete) return;
    remove.mutate(pendingDelete.id, { onSettled: () => setPendingDelete(null) });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[560px]">
        <DialogHeader>
          <DialogTitle>Attachments</DialogTitle>
          <DialogDescription>Upload and manage files for {leadName}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-5">
          <UploadDropzone
            onFile={(file) => upload.mutate(file)}
            disabled={upload.isPending}
            accept={ACCEPT}
            title="Choose files to upload"
            hint="or drag & drop them here — documents, images, or PDFs"
            formats={[]}
            ariaLabel={`Upload a file for ${leadName}`}
          />

          {upload.isPending && (
            <p className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              Uploading…
            </p>
          )}

          {isLoading ? (
            <div className="flex flex-col gap-2" aria-hidden>
              {[0, 1].map((i) => (
                <div key={i} className="h-14 animate-pulse rounded-xl bg-muted" />
              ))}
            </div>
          ) : count === 0 ? (
            <div className="flex flex-col items-center gap-2 py-6 text-center">
              <Paperclip className="h-6 w-6 text-muted-foreground" />
              <p className="text-sm font-semibold text-card-foreground">No attachments yet</p>
              <p className="text-xs text-muted-foreground">
                Files you upload for this lead will appear here.
              </p>
            </div>
          ) : (
            <>
              {/* Same five-row window as the card, so a long list never grows the dialog past
                  the viewport. */}
              <ul className="flex max-h-[280px] flex-col gap-2 overflow-y-auto">
                {files.map((file) => (
                  <li
                    key={file.id}
                    // shrink-0: same flex-column-with-max-height trap as the card — without it the
                    // rows compress instead of the list scrolling.
                    className="flex shrink-0 items-center gap-3 rounded-xl bg-muted px-4 py-3"
                  >
                    <FileTypeIcon name={file.name} mimeType={file.mimeType} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-card-foreground" title={file.name}>
                        {file.name}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {file.sizeLabel} · {formatAttachmentTimestamp(file.createdAt)}
                      </p>
                    </div>
                    {/* View · Download · Delete share the trailing slot with the same padding — the
                        row layout and spacing are unchanged. Shared with the Files & Downloads card
                        so both surfaces offer identical actions and states. */}
                    <LeadAttachmentRowActions
                      file={file}
                      onPreview={openPreview}
                      onDownload={downloadAttachment}
                      onDelete={setPendingDelete}
                      previewingId={previewingId}
                      downloadingId={downloadingId}
                      deleting={remove.isPending}
                    />
                  </li>
                ))}
              </ul>

              <p className="text-center text-xs text-muted-foreground">
                {count === 1 ? "1 file attached" : `${count} files attached`}
              </p>
            </>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={() => onOpenChange(false)}>Done</Button>
        </DialogFooter>

        <LeadAttachmentPreviewDialog
          preview={preview}
          onClose={closePreview}
          onDownload={() => preview && downloadAttachment(preview)}
          downloading={preview !== null && downloadingId === preview.id}
        />

        {/* Deletion removes the bytes from disk for good — only the metadata row is soft-deleted —
            so the copy says exactly that rather than the softer "this can't be undone". */}
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
      </DialogContent>
    </Dialog>
  );
}
