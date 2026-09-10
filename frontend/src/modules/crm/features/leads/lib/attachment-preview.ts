/**
 * Which attachments the BD vault can show inline, and how.
 *
 * One rule, three consumers: the row decides whether to render a View control, the preview hook
 * decides HOW to fetch (raw bytes for images and PDFs, decoded text for TXT and CSV, a JSON window
 * from the API for spreadsheets), and the viewer decides which element to render. Keeping the
 * predicate here is what stops a row offering a preview the dialog cannot actually display.
 *
 * Still deliberately narrow. `doc`/`docx`/`ppt`/`pptx` need a converter — LibreOffice or an
 * equivalent server-side rendering step — and `zip` is a container with nothing to show, so all five
 * stay Download-only rather than being half-supported. That set is the next slice's problem, not a
 * gap to paper over here.
 *
 * ── WHY THIS ANSWER IS ONLY AN AFFORDANCE ────────────────────────────────────────────────────
 * Nothing here is a security decision. The kind chosen from a filename and a MIME string decides
 * WHICH UI TO SHOW, nothing more: the spreadsheet endpoint re-identifies the format from the bytes
 * on disk and refuses anything that is not really a workbook, and text is rendered as text nodes
 * whatever it turns out to contain. A wrong guess here costs a useless View button, never a parse
 * of something unexpected.
 */
export type PreviewKind = "image" | "pdf" | "text" | "csv" | "sheet";

/** Extension fallback for the uninformative-MIME case, mirroring FileTypeIcon's own fallback. */
const IMAGE_EXTENSIONS = ["png", "jpg", "jpeg", "webp", "gif"];

/**
 * Extension-keyed kinds — see `previewKindFor` for why these three do NOT lead with the MIME.
 */
const TEXT_EXTENSIONS = ["txt"];
const CSV_EXTENSIONS = ["csv"];
const SHEET_EXTENSIONS = ["xls", "xlsx"];

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
 * TEXT, CSV AND SPREADSHEETS lead with the FILENAME EXTENSION, which is the opposite rule and is
 * deliberate. `.csv` is routinely labelled `application/vnd.ms-excel` by Windows and `text/plain` by
 * everything else, so the MIME cannot separate a CSV from a real workbook — but the extension can,
 * and it is also what the user sees and what `formatFileTypeLabel` already displays. Getting this
 * pair the wrong way round would send every Windows-exported CSV to the spreadsheet endpoint, which
 * would correctly refuse it as not-a-workbook and leave the user with a broken View button.
 *
 * MIME is consulted after the extension rules ONLY for a file that has no extension at all. It must
 * not override an extension that simply isn't one of these three: `proposal.pdf` carrying a stray
 * `text/csv` MIME has to stay non-previewable rather than being handed to the text viewer, which
 * would try to render PDF bytes as characters.
 */
export function previewKindFor(mimeType?: string | null, name?: string | null): PreviewKind | null {
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
export function isPreviewable(mimeType?: string | null, name?: string | null): boolean {
  return previewKindFor(mimeType, name) !== null;
}
