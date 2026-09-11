/**
 * Which attachments the BD vault can show inline, and how.
 *
 * One rule, three consumers: the row decides whether to render a View control, the preview hook
 * decides HOW to fetch (raw bytes for images and PDFs, decoded text for TXT and CSV, a JSON window
 * from the API for spreadsheets, a server-rendered PDF for Office documents), and the viewer decides
 * which element to render. Keeping the predicate here is what stops a row offering a preview the
 * dialog cannot actually display.
 *
 * OFFICE DOCUMENTS DEPEND ON THE SERVER. `doc`/`docx`/`ppt`/`pptx` are previewed by converting them
 * to PDF through a server-side conversion service, which not every deployment has configured. So
 * they are previewable only when the attachment itself says the server can do it
 * (`documentPreview`, from LeadAttachmentResource). Without that flag they stay Download-only,
 * exactly as they were before the converter existed. `zip` is a container with nothing to show and
 * is Download-only everywhere.
 *
 * ── WHY THIS ANSWER IS ONLY AN AFFORDANCE ────────────────────────────────────────────────────
 * Nothing here is a security decision. The kind chosen from a filename and a MIME string decides
 * WHICH UI TO SHOW, nothing more: the spreadsheet and document endpoints re-identify the format from
 * the bytes on disk and refuse anything that is not really what it claims, and text is rendered as
 * text nodes whatever it turns out to contain. A wrong guess here costs a useless View button, never
 * a parse of something unexpected.
 */
export type PreviewKind = "image" | "pdf" | "text" | "csv" | "sheet" | "document";

/** What the server says about one attachment, beyond its name and MIME. */
export type PreviewOptions = {
  /** True when this server can render this attachment's Office format to PDF. */
  documentPreview?: boolean;
};

/** Extension fallback for the uninformative-MIME case, mirroring FileTypeIcon's own fallback. */
const IMAGE_EXTENSIONS = ["png", "jpg", "jpeg", "webp", "gif"];

/**
 * Extension-keyed kinds — see `previewKindFor` for why these do NOT lead with the MIME.
 */
const TEXT_EXTENSIONS = ["txt"];
const CSV_EXTENSIONS = ["csv"];
const SHEET_EXTENSIONS = ["xls", "xlsx"];
const DOCUMENT_EXTENSIONS = ["doc", "docx", "ppt", "pptx"];

/** The two workbook MIMEs, used only when a file arrives with no usable extension. */
const SHEET_MIME_TYPES = [
  "application/vnd.ms-excel",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
];

/**
 * How to render this attachment inline, or null when it is Download-only.
 *
 * IMAGES AND PDFS lead with the MIME type, unchanged from when this file was written: those two
 * kinds were correct in production and there is no reason to disturb them.
 *
 * TEXT, CSV, SPREADSHEETS AND OFFICE DOCUMENTS lead with the FILENAME EXTENSION, which is the
 * opposite rule and is deliberate. `.csv` is routinely labelled `application/vnd.ms-excel` by Windows
 * and `text/plain` by everything else, and fileinfo reports every OOXML file as the ZIP container on
 * some hosts — so the MIME cannot separate these formats, but the extension can, and it is also what
 * the user sees and what `formatFileTypeLabel` already displays.
 *
 * An Office extension answers "document" only with the server's `documentPreview` flag, and null
 * otherwise — never falling through to the MIME rules below, which could hand a `.docx` to the text
 * viewer.
 *
 * MIME is consulted after the extension rules ONLY for a file that has no extension at all. It must
 * not override an extension that simply isn't one of these: `proposal.pdf` carrying a stray
 * `text/csv` MIME has to stay non-previewable rather than being handed to the text viewer, which
 * would try to render PDF bytes as characters. An extensionless Office file stays Download-only: the
 * document endpoint requires the extension too.
 */
export function previewKindFor(
  mimeType?: string | null,
  name?: string | null,
  options: PreviewOptions = {},
): PreviewKind | null {
  const mime = (mimeType ?? "").toLowerCase();
  const ext = extensionOf(name);

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

  if (DOCUMENT_EXTENSIONS.includes(ext)) {
    return options.documentPreview === true ? "document" : null;
  }
  if (CSV_EXTENSIONS.includes(ext)) {
    return "csv";
  }
  if (TEXT_EXTENSIONS.includes(ext)) {
    return "text";
  }
  if (SHEET_EXTENSIONS.includes(ext)) {
    return "sheet";
  }

  // From here on the file has NO extension to go on, so the MIME is all there is. `text/*` is safe
  // to show as text whatever it turns out to be, because it is rendered as text nodes, never markup.
  if (ext === "") {
    if (SHEET_MIME_TYPES.includes(mime)) {
      return "sheet";
    }
    if (mime === "text/csv") {
      return "csv";
    }
    if (mime.startsWith("text/")) {
      return "text";
    }
  }

  return null;
}

/**
 * The filename's extension, or "" when it genuinely has none.
 *
 * `split(".").pop()` — what this file used to do inline — returns the WHOLE NAME for an
 * extensionless file, so "readme" would report an extension of "readme". That was harmless while
 * every use compared against a whitelist, but "" is now load-bearing: it is the signal that the MIME
 * fallback above is allowed to run at all. A leading dot is not an extension either, so `.env`
 * reports "".
 */
function extensionOf(name?: string | null): string {
  const value = name ?? "";
  const dot = value.lastIndexOf(".");

  return dot > 0 ? value.slice(dot + 1).toLowerCase() : "";
}

/** True when the vault can show this attachment without downloading it. */
export function isPreviewable(
  mimeType?: string | null,
  name?: string | null,
  options: PreviewOptions = {},
): boolean {
  return previewKindFor(mimeType, name, options) !== null;
}
