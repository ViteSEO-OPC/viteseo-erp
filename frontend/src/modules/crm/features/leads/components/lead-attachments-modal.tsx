"use client";

import { Loader2, Paperclip, X } from "lucide-react";
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
import { FileTypeIcon } from "@/modules/crm/features/leads/components/file-type-icon";
import {
  useLeadAttachments,
  useUploadAttachment,
  useDeleteAttachment,
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
  /** Whether the current user may remove a given file (backend still enforces it). */
  canDelete: boolean;
};

/**
 * Upload and manage files for one lead.
 *
 * Reads the SAME per-lead query as the Attachments card, so the list here and the card below the
 * Lead Information card are always the same data — uploading in the modal updates both at once,
 * with no page refresh and no second source of truth.
 */
export function LeadAttachmentsModal({ open, onOpenChange, leadId, leadName, canDelete }: Props) {
  const { attachments, isLoading } = useLeadAttachments(leadId);
  const upload = useUploadAttachment(leadId);
  const remove = useDeleteAttachment(leadId);

  const files: LeadAttachment[] = attachments ?? [];
  const count = files.length;

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
                    {canDelete && (
                      <button
                        type="button"
                        onClick={() => remove.mutate(file.id)}
                        disabled={remove.isPending}
                        className="rounded-md p-1 text-muted-foreground transition-colors hover:text-red-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                        aria-label={`Remove ${file.name}`}
                      >
                        <X className="h-4 w-4" />
                      </button>
                    )}
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
      </DialogContent>
    </Dialog>
  );
}
