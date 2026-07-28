import { FileArchive, FileImage, FileSpreadsheet, FileText, File as FileIcon } from "lucide-react";

const ICON_CLASS = "h-4 w-4 text-teal-400";

/**
 * Small file-type glyph for an attachment row.
 *
 * Chooses from the icon set already used across the CRM rather than shipping per-extension artwork.
 * The MIME type is preferred (it is derived server-side from the file's content), with the filename
 * extension only as a fallback for the generic octet-stream case.
 */
export function FileTypeIcon({ name, mimeType }: { name: string; mimeType?: string }) {
  return (
    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-card">
      {renderIcon(name, mimeType)}
    </span>
  );
}

/** Returns the element directly — never a component reference, which would remount on every render. */
function renderIcon(name: string, mimeType?: string) {
  const mime = mimeType ?? "";
  const ext = name.split(".").pop()?.toLowerCase() ?? "";

  if (mime.startsWith("image/") || ["png", "jpg", "jpeg", "webp", "gif"].includes(ext)) {
    return <FileImage className={ICON_CLASS} aria-hidden />;
  }
  if (mime === "application/zip" || ext === "zip") {
    return <FileArchive className={ICON_CLASS} aria-hidden />;
  }
  if (mime.includes("spreadsheet") || mime === "text/csv" || ["xls", "xlsx", "csv"].includes(ext)) {
    return <FileSpreadsheet className={ICON_CLASS} aria-hidden />;
  }
  if (
    mime === "application/pdf" ||
    mime.startsWith("text/") ||
    ["pdf", "doc", "docx", "txt"].includes(ext)
  ) {
    return <FileText className={ICON_CLASS} aria-hidden />;
  }

  return <FileIcon className={ICON_CLASS} aria-hidden />;
}
