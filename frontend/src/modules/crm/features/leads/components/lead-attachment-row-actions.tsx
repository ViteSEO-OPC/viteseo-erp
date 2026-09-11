"use client";

import { Download, Eye, Loader2, Trash2 } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { isPreviewable } from "@/modules/crm/features/leads/lib/attachment-preview";
import type { LeadAttachment } from "@/modules/crm/features/leads/hooks/use-lead-attachments";

type Props = {
  file: LeadAttachment;
  onPreview: (file: LeadAttachment) => void;
  onDownload: (file: LeadAttachment) => void;
  onDelete: (file: LeadAttachment) => void;
  previewingId: string | null;
  downloadingId: string | null;
  deleting: boolean;
  /** Padding differs per surface: the dialog rows are tighter than the card's 64px rows. */
  buttonClassName?: string;
};

/**
 * The View · Download · Delete cluster for one attachment row.
 *
 * Extracted so the Attachments modal and the Files & Downloads card offer the SAME actions with the
 * same loading and disabled states — the alternative was copying the three buttons into the card and
 * letting the two drift. The surfaces still own their own layout and their own dialog instances;
 * only the controls are shared.
 *
 * View renders only for types the viewer can actually display, so the control never promises a
 * preview it cannot give. Delete only ever calls back — the confirmation lives with the surface, so
 * no DELETE can leave the client from here.
 */
export function LeadAttachmentRowActions({
  file,
  onPreview,
  onDownload,
  onDelete,
  previewingId,
  downloadingId,
  deleting,
  buttonClassName,
}: Props) {
  const base = cn(
    "rounded-md p-1 text-muted-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50",
    buttonClassName,
  );

  return (
    <div className="flex shrink-0 items-center gap-1">
      {isPreviewable(file.mimeType, file.name, { documentPreview: file.documentPreview }) && (
        <button
          type="button"
          onClick={() => onPreview(file)}
          disabled={previewingId === file.id}
          className={cn(base, "hover:text-teal-400")}
          aria-label={`View ${file.name}`}
        >
          {previewingId === file.id ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <Eye className="h-4 w-4" />
          )}
        </button>
      )}

      <button
        type="button"
        onClick={() => onDownload(file)}
        disabled={downloadingId === file.id}
        className={cn(base, "hover:text-teal-400")}
        aria-label={`Download ${file.name}`}
      >
        {downloadingId === file.id ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <Download className="h-4 w-4" />
        )}
      </button>

      {/* Trash2 rather than an X because this destroys the file on disk permanently; "remove from a
          list" would understate it. */}
      <button
        type="button"
        onClick={() => onDelete(file)}
        disabled={deleting}
        className={cn(base, "hover:text-red-500")}
        aria-label={`Delete ${file.name}`}
      >
        <Trash2 className="h-4 w-4" />
      </button>
    </div>
  );
}
