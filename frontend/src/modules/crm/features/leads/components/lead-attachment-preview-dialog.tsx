"use client";

import { Download, Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { formatFileTypeLabel } from "@/modules/crm/features/leads/lib/attachment-format";
import {
  AttachmentCsvPreview,
  AttachmentSheetPreview,
  AttachmentTextPreview,
} from "@/modules/crm/features/leads/components/attachment-preview-views";
import type { AttachmentPreview } from "@/modules/crm/features/leads/hooks/use-lead-attachments";

type Props = {
  /** The open attachment, or null when the viewer is closed. Owns the blob URL being rendered. */
  preview: AttachmentPreview | null;
  onClose: () => void;
  onDownload: () => void;
  downloading?: boolean;
};

/**
 * Inline viewer for a lead attachment — the read half of the BD vault.
 *
 * Renders from the blob URL the preview hook already fetched through the authorized endpoint, so
 * nothing here re-requests the file or knows about auth. `z-[60]` on both the content and the
 * backdrop stacks it above the attachments modal that opened it — the same pattern the niche modals
 * use, since both surfaces must clear the parent dialog's `z-50`.
 *
 * Download stays available inside the viewer: preview covers images, PDFs, plain text, CSV,
 * spreadsheets and — where the server can convert them — Office documents, and for everything else —
 * or when a browser declines to render what it was given — downloading is still the answer.
 *
 * OFFICE DOCUMENTS ARE SHOWN AS THE PDF THE SERVER MADE OF THEM, through the same `<iframe>` as a
 * PDF attachment. The original DOC/DOCX/PPT/PPTX never reaches the browser for viewing, so nothing in
 * it is interpreted client-side.
 *
 * The kind is decided ONCE, by the hook, and carried on the preview. This component used to re-derive
 * it from the MIME and filename, which was harmless while both answers came from the same pure
 * function but would now be a real hazard: the hook picks a FETCH STRATEGY from that answer, so a
 * second derivation here could render a text viewer over a payload fetched as a spreadsheet.
 *
 * MOBILE PDF, UNCHANGED AND UNRESOLVED. `<iframe>` renders PDFs reliably on desktop Chrome, Edge,
 * Firefox and Safari, but Android Chrome and mobile Safari frequently show a blank frame or offer a
 * download instead. That predates this slice and is deliberately not addressed here — fixing it
 * means pdf.js and a new dependency. Download is the working path on those browsers, and the same
 * limitation now applies to Office documents, which render through that same frame.
 */
export function LeadAttachmentPreviewDialog({ preview, onClose, onDownload, downloading }: Props) {
  const kind = preview?.kind ?? null;

  return (
    <Dialog open={preview !== null} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="z-[60] sm:max-w-[840px]" overlayClassName="z-[60]">
        <DialogHeader>
          <DialogTitle className="truncate" title={preview?.name}>
            {preview?.name ?? "Attachment"}
          </DialogTitle>
          <DialogDescription>
            {preview ? `${formatFileTypeLabel(preview.name)} file. ` : ""}
            Close to return to the attachment list.
          </DialogDescription>
        </DialogHeader>

        {/* A fixed viewport for the media: the dialog's own size must not depend on the intrinsic
            size of whatever was uploaded, or a tall PDF or a wide screenshot would resize it. */}
        <div className="flex min-h-[320px] items-center justify-center overflow-auto rounded-xl bg-muted p-2">
          {preview === null ? null : kind === "image" ? (
            // A blob: URL cannot go through next/image: the optimizer needs a static import or a
            // remote pattern, and these bytes are fetched from the private disk at runtime.
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={preview.url ?? ""}
              alt={preview.name}
              className="max-h-[60vh] max-w-full object-contain"
            />
          ) : kind === "pdf" || kind === "document" ? (
            <iframe
              src={preview.url ?? ""}
              title={preview.name}
              className="h-[60vh] w-full rounded-lg border-0 bg-card"
            />
          ) : kind === "text" && preview.text ? (
            <AttachmentTextPreview decoded={preview.text} />
          ) : kind === "csv" && preview.text ? (
            <AttachmentCsvPreview decoded={preview.text} />
          ) : kind === "sheet" && preview.sheet ? (
            <AttachmentSheetPreview sheet={preview.sheet} />
          ) : (
            <div className="flex flex-col items-center gap-2 py-10 text-center">
              <p className="text-sm font-semibold text-card-foreground">Preview unavailable</p>
              <p className="text-xs text-muted-foreground">
                This file type can’t be shown here — download it to open it.
              </p>
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose}>
            Close
          </Button>
          <Button size="sm" onClick={onDownload} disabled={downloading}>
            {downloading ? (
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
            ) : (
              <Download className="h-4 w-4" aria-hidden />
            )}
            Download
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
