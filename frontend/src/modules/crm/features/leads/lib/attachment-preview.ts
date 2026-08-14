/**
 * Which attachments the BD vault can show inline, and how.
 *
 * One rule, two consumers: the row decides whether to render a View control, and the viewer decides
 * which element to render. Keeping the predicate here is what stops a row offering a preview the
 * dialog cannot actually display.
 *
 * Scope is deliberately narrow — only what a browser renders natively from a blob URL. Office
 * formats (doc/docx/xls/xlsx/ppt/pptx) and zip archives need a converter or a third-party viewer, so
 * they stay Download-only rather than pulling in a dependency. Plain text and CSV are renderable in
 * principle but need decoding and their own scroll/escape handling; they are left out of this slice.
 */
export type PreviewKind = "image" | "pdf";

/** Extension fallback for the uninformative-MIME case, mirroring FileTypeIcon's own fallback. */
const IMAGE_EXTENSIONS = ["png", "jpg", "jpeg", "webp", "gif"];

/**
 * How to render this attachment inline, or null when it is Download-only.
 *
 * MIME is preferred because the server derives it from the file's content. The filename extension is
 * consulted only when MIME says nothing useful (empty, or the generic octet-stream a few clients
 * send), so a mislabelled upload cannot talk the viewer into the wrong element.
 */
export function previewKindFor(mimeType?: string | null, name?: string | null): PreviewKind | null {
  const mime = (mimeType ?? "").toLowerCase();
  const ext = (name ?? "").split(".").pop()?.toLowerCase() ?? "";

  if (mime === "application/pdf") {
    return "pdf";
  }
  if (mime.startsWith("image/")) {
    return "image";
  }

  if (mime === "" || mime === "application/octet-stream") {
    if (ext === "pdf") {
      return "pdf";
    }
    if (IMAGE_EXTENSIONS.includes(ext)) {
      return "image";
    }
  }

  return null;
}

/** True when the vault can show this attachment without downloading it. */
export function isPreviewable(mimeType?: string | null, name?: string | null): boolean {
  return previewKindFor(mimeType, name) !== null;
}
