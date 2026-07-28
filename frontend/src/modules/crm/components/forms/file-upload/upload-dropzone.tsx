"use client";

import { useRef, type ChangeEvent } from "react";
import { Upload } from "lucide-react";
import { Badge } from "@/modules/crm/components/ui/badge";

/**
 * Shared file dropzone.
 *
 * Every prop below is OPTIONAL and defaults to the Leads-import wording it originally hardcoded, so
 * the import modal renders byte-for-byte as before. The overrides exist so a second caller (Lead
 * Attachments, which accepts documents/images/PDFs rather than CSV/XLSX) can reuse this control
 * instead of forking a near-identical component.
 */
type Props = {
  onFile?: (file: File) => void;
  disabled?: boolean;
  /** `accept` attribute for the hidden input. Defaults to the Leads-import types. */
  accept?: string;
  title?: string;
  hint?: string;
  /** Format chips under the hint. Pass an empty array to render none. */
  formats?: readonly string[];
  /** Accessible name for the control — describe what is being uploaded. */
  ariaLabel?: string;
};

export function UploadDropzone({
  onFile,
  disabled,
  accept = ".csv,.xlsx",
  title = "Drag & drop your file here",
  hint = "or click to browse",
  formats = ["CSV", "XLSX"],
  ariaLabel = "Upload CSV or XLSX file",
}: Props) {
  const inputRef = useRef<HTMLInputElement>(null);

  const handleClick = () => inputRef.current?.click();

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) onFile?.(file);
    // Reset so selecting the SAME file twice in a row still fires onChange (the browser suppresses
    // it otherwise) — re-uploading a file you just deleted has to work.
    e.target.value = "";
  };

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={disabled}
      className="flex w-full cursor-pointer flex-col items-center gap-3 rounded-xl border-2 border-dashed border-teal-400 bg-muted px-6 py-8 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
      aria-label={ariaLabel}
    >
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        onChange={handleChange}
        className="hidden"
        aria-hidden
      />

      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--dropzone-icon-tint)]">
        <Upload className="h-5 w-5 text-teal-400" />
      </span>

      <div className="text-center">
        <p className="text-sm font-bold text-card-foreground">{title}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
      </div>

      {formats.length > 0 && (
        <div className="flex items-center gap-2">
          {formats.map((format) => (
            <Badge
              key={format}
              variant="outline"
              className="border-border bg-card text-xs font-medium text-foreground"
            >
              {format}
            </Badge>
          ))}
        </div>
      )}
    </button>
  );
}
