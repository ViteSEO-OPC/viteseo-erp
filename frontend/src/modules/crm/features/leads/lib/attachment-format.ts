/**
 * Attachment timestamp — "Jul 17, 10:23 AM".
 *
 * Attachments show an absolute date AND time (the mockup's file line), which none of the existing
 * shared formatters produce: formatActivityTimestamp collapses today to a bare time and
 * formatDateLong omits the time. Kept beside the feature rather than added to the shared formatters
 * module, since this exact shape has one consumer.
 */
export function formatAttachmentTimestamp(date: string | null | undefined): string {
  if (!date) return "—";
  const d = new Date(date);
  if (Number.isNaN(d.getTime())) return "—";

  const day = d.toLocaleDateString("en-US", { month: "short", day: "numeric" });
  const time = d.toLocaleTimeString("en-US", {
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });

  return `${day}, ${time}`;
}

/** Friendly names for the formats whose extension is not the obvious label. */
const TYPE_LABELS: Record<string, string> = {
  doc: "Word",
  docx: "Word",
  xls: "Excel",
  xlsx: "Excel",
  ppt: "PowerPoint",
  pptx: "PowerPoint",
  txt: "Text",
  jpeg: "JPG",
};

/**
 * Short format label for a file row — "PDF", "PNG", "Excel".
 *
 * Reads the extension rather than the MIME type: the Office MIME strings are long enough to be
 * useless for this, and the extension is what a BD recognises. Anything unmapped falls back to its
 * own uppercased extension, so a new accepted format still renders something sensible.
 */
export function formatFileTypeLabel(name: string): string {
  const ext = name.split(".").pop()?.toLowerCase() ?? "";
  if (!ext || ext === name.toLowerCase()) return "File";
  return TYPE_LABELS[ext] ?? ext.toUpperCase();
}
