"use client";

import { useMemo } from "react";
import { Info } from "lucide-react";
import {
  parseCsv,
  toTextPreview,
  type DecodedText,
} from "@/modules/crm/features/leads/lib/attachment-text";
import type { SheetPreview } from "@/modules/crm/features/leads/hooks/use-lead-attachments";

/**
 * The three non-media viewers behind {@link LeadAttachmentPreviewDialog}: plain text, CSV, and the
 * server-parsed spreadsheet window.
 *
 * They live together because CSV and spreadsheet are the same picture from two sources — a bounded
 * grid of strings — and share {@link GridPreview} rather than growing two tables that drift. Images
 * and PDFs stay in the dialog itself: those are single elements, not layouts.
 *
 * NOTHING HERE PRODUCES MARKUP. Every value arrives as a string and is rendered as a React text
 * node, so a cell holding `<script>` or a `.txt` full of HTML is displayed literally.
 * `dangerouslySetInnerHTML` appears nowhere in the attachment preview path, by construction.
 */

/** Spreadsheet-style column label: 0 → A, 25 → Z, 26 → AA. */
function columnLabel(index: number): string {
  let label = "";
  let n = index;

  while (n >= 0) {
    label = String.fromCharCode(65 + (n % 26)) + label;
    n = Math.floor(n / 26) - 1;
  }

  return label;
}

/** The "you are not seeing all of it" line. Always paired with Download in the dialog footer. */
function TruncationNotice({ children }: { children: React.ReactNode }) {
  return (
    <p className="flex items-center gap-1.5 px-1 pt-2 text-xs text-muted-foreground">
      <Info className="h-3.5 w-3.5 shrink-0" aria-hidden />
      {children}
    </p>
  );
}

/**
 * A bounded grid of strings, addressed the way a spreadsheet is.
 *
 * Row numbers and column letters rather than treating row 1 as a header: a preview must not assert
 * that the first row is headings, because for plenty of exports it is data, and styling it as a
 * header would quietly misrepresent the file.
 */
function GridPreview({ rows, ariaLabel }: { rows: string[][]; ariaLabel: string }) {
  const columnCount = rows.reduce((widest, row) => Math.max(widest, row.length), 0);

  return (
    <div className="max-h-[60vh] w-full overflow-auto rounded-lg border border-border bg-card">
      <table className="w-max min-w-full border-collapse text-xs" aria-label={ariaLabel}>
        <thead className="sticky top-0 z-10 bg-muted">
          <tr>
            <th
              scope="col"
              className="sticky left-0 z-20 border-b border-r border-border bg-muted px-2 py-1.5 text-right font-medium text-muted-foreground"
            >
              <span className="sr-only">Row</span>
            </th>
            {Array.from({ length: columnCount }, (_, column) => (
              <th
                key={column}
                scope="col"
                className="border-b border-r border-border px-3 py-1.5 text-left font-medium text-muted-foreground"
              >
                {columnLabel(column)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, rowIndex) => (
            <tr key={rowIndex} className="even:bg-muted/40">
              <th
                scope="row"
                className="sticky left-0 z-10 border-b border-r border-border bg-muted px-2 py-1.5 text-right font-normal tabular-nums text-muted-foreground"
              >
                {rowIndex + 1}
              </th>
              {Array.from({ length: columnCount }, (_, column) => (
                <td
                  key={column}
                  className="max-w-[22rem] truncate border-b border-r border-border px-3 py-1.5 text-card-foreground"
                  title={row[column] || undefined}
                >
                  {row[column] ?? ""}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Nothing to show, for a file that decoded or parsed to no content at all. */
function EmptyPreview({ label }: { label: string }) {
  return (
    <div className="flex flex-col items-center gap-1 py-10 text-center">
      <p className="text-sm font-semibold text-card-foreground">{label}</p>
      <p className="text-xs text-muted-foreground">Download it to inspect the original file.</p>
    </div>
  );
}

/** A `.txt` attachment, as bounded plain text. */
export function AttachmentTextPreview({ decoded }: { decoded: DecodedText }) {
  const preview = useMemo(() => toTextPreview(decoded), [decoded]);

  if (preview.lines.length === 0 || preview.lines.every((line) => line === "")) {
    return <EmptyPreview label="This file is empty" />;
  }

  return (
    <div className="w-full">
      <pre className="max-h-[60vh] w-full overflow-auto whitespace-pre-wrap break-words rounded-lg border border-border bg-card p-3 font-mono text-xs leading-relaxed text-card-foreground">
        {preview.lines.join("\n")}
      </pre>
      {preview.truncated && (
        <TruncationNotice>
          Showing the first {preview.lines.length.toLocaleString("en-PH")} lines. Download the file
          for the full text.
        </TruncationNotice>
      )}
    </div>
  );
}

/** A `.csv` attachment, parsed client-side into a bounded table. */
export function AttachmentCsvPreview({ decoded }: { decoded: DecodedText }) {
  const preview = useMemo(
    () => parseCsv(decoded.text, { sourceTruncated: decoded.truncated }),
    [decoded],
  );

  if (preview.rows.length === 0) {
    return <EmptyPreview label="This file is empty" />;
  }

  const columnsShown = preview.rows.reduce((widest, row) => Math.max(widest, row.length), 0);

  return (
    <div className="w-full">
      <GridPreview rows={preview.rows} ariaLabel="CSV preview" />
      {(preview.rowsTruncated || preview.columnsTruncated) && (
        <TruncationNotice>
          Showing the first {preview.rows.length.toLocaleString("en-PH")} rows
          {preview.columnsTruncated
            ? ` and ${columnsShown} of ${preview.totalColumns} columns`
            : ""}
          . Download the file for everything.
        </TruncationNotice>
      )}
    </div>
  );
}

/**
 * An `.xls` / `.xlsx` attachment, from the server's bounded window.
 *
 * The limits come back with the payload rather than being repeated here, so this line cannot drift
 * from what the reader actually returned.
 */
export function AttachmentSheetPreview({ sheet }: { sheet: SheetPreview }) {
  if (sheet.rows.length === 0) {
    return <EmptyPreview label="This sheet is empty" />;
  }

  const rowsShown = sheet.rows.length;
  const columnsShown = sheet.rows.reduce((widest, row) => Math.max(widest, row.length), 0);
  const moreSheets = sheet.sheetCount > 1;

  return (
    <div className="w-full">
      <p className="px-1 pb-2 text-xs text-muted-foreground">
        Sheet <span className="font-medium text-card-foreground">{sheet.sheetName}</span>
        {moreSheets && ` — first of ${sheet.sheetCount}`}
      </p>

      <GridPreview rows={sheet.rows} ariaLabel={`Preview of sheet ${sheet.sheetName}`} />

      {(sheet.truncated || moreSheets) && (
        <TruncationNotice>
          {sheet.truncated
            ? `Showing ${rowsShown.toLocaleString("en-PH")} of ${sheet.totalRows.toLocaleString("en-PH")} rows and ${columnsShown} of ${sheet.totalColumns} columns.`
            : ""}
          {moreSheets ? " Other sheets are not previewed." : ""} Download the file to open it in
          Excel.
        </TruncationNotice>
      )}
    </div>
  );
}
