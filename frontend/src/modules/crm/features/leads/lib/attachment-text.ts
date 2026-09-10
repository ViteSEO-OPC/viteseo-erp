/**
 * Decoding and parsing for the TXT and CSV previews.
 *
 * Pure functions over bytes and strings — no DOM, no React, no Blob. That is what lets the same code
 * the dialog runs be driven directly by `npm run preview:gates`, and it is why the byte input is an
 * ArrayBuffer rather than the Blob the hook happens to hold.
 *
 * EVERY OUTPUT IS DATA, NEVER MARKUP. The viewer renders these strings as React text nodes, so a
 * `.txt` full of `<script>` or a CSV cell containing `=cmd|'/c calc'` is displayed literally.
 * Nothing in this module produces HTML and nothing downstream may pass it to
 * `dangerouslySetInnerHTML`.
 */

/**
 * Bytes decoded before anything else looks at the file.
 *
 * 1 MB is far more than a preview needs and small enough that decoding is instant on a phone. The
 * cap is applied to the BYTES, before decoding, so a 200 MB text file costs one slice rather than a
 * 200 MB string — bounding after the decode would be exactly the freeze this prevents.
 */
export const MAX_PREVIEW_BYTES = 1_048_576;

/** Lines rendered for a `.txt`. Beyond this the viewer says so and points at Download. */
export const MAX_TEXT_LINES = 2_000;

/** Rows and columns rendered for a `.csv`. Mirrors the spreadsheet window's spirit, not its numbers. */
export const MAX_CSV_ROWS = 500;
export const MAX_CSV_COLUMNS = 50;

/** One cell cannot dominate the table. Matches SpreadsheetPreviewReader::MAX_CELL_LENGTH. */
export const MAX_CELL_LENGTH = 500;

export type TextEncodingUsed = "utf-8" | "windows-1252";

export type DecodedText = {
  text: string;
  /** True when the file was longer than {@link MAX_PREVIEW_BYTES} and the tail was dropped. */
  truncated: boolean;
  encoding: TextEncodingUsed;
};

/** The UTF-8 byte order mark, which must not survive into the rendered text as a stray glyph. */
const BOM = [0xef, 0xbb, 0xbf];

/**
 * Decode attachment bytes to a string, bounded, with a defined answer for non-UTF-8 files.
 *
 * UTF-8 is tried STRICTLY (`fatal: true`) so that a file which is not UTF-8 fails loudly here rather
 * than rendering as a field of replacement characters. CSVs exported by Excel on a Philippine
 * Windows install are routinely Windows-1252, and that codec is total — every byte maps to some
 * character — so it is the fallback and the recursion terminates.
 *
 * The retry loop exists because the byte cap can land mid-character: slicing at 1 MB can cut a
 * three-byte glyph in half, which strict UTF-8 rejects. Dropping up to three trailing bytes recovers
 * the valid prefix, so a large UTF-8 file is not misdiagnosed as Windows-1252 over one split glyph.
 */
export function decodeAttachmentText(input: ArrayBuffer | Uint8Array): DecodedText {
  const all = input instanceof Uint8Array ? input : new Uint8Array(input);
  const truncated = all.byteLength > MAX_PREVIEW_BYTES;

  let bytes = truncated ? all.subarray(0, MAX_PREVIEW_BYTES) : all;

  if (bytes.length >= 3 && BOM.every((b, i) => bytes[i] === b)) {
    bytes = bytes.subarray(3);
  }

  // Only a truncated slice can end mid-character, so only that case is worth retrying.
  const maxTrim = truncated ? 3 : 0;
  for (let trim = 0; trim <= maxTrim; trim++) {
    const candidate = trim === 0 ? bytes : bytes.subarray(0, bytes.length - trim);
    try {
      const text = new TextDecoder("utf-8", { fatal: true }).decode(candidate);
      return { text, truncated, encoding: "utf-8" };
    } catch {
      // Not valid UTF-8 at this length — try one byte shorter, then give up on UTF-8.
    }
  }

  return {
    text: new TextDecoder("windows-1252").decode(bytes),
    truncated,
    encoding: "windows-1252",
  };
}

export type TextPreview = {
  lines: string[];
  /** True when lines were dropped — by the byte cap, the line cap, or both. */
  truncated: boolean;
  encoding: TextEncodingUsed;
};

/**
 * A decoded file as bounded display lines.
 *
 * A truncated byte slice almost always ends mid-line; that partial last line is dropped rather than
 * shown, so the preview never presents half a record as if it were whole.
 */
export function toTextPreview(decoded: DecodedText, maxLines: number = MAX_TEXT_LINES): TextPreview {
  const all = decoded.text.split(/\r\n|\r|\n/);

  if (decoded.truncated && all.length > 1) {
    all.pop();
  }

  const lines = all.slice(0, maxLines);

  return {
    lines,
    truncated: decoded.truncated || all.length > maxLines,
    encoding: decoded.encoding,
  };
}

export type CsvPreview = {
  rows: string[][];
  /** Rows beyond the cap, or a byte-truncated file. */
  rowsTruncated: boolean;
  /** At least one row had more columns than the cap. */
  columnsTruncated: boolean;
  /** The widest row actually seen, before the column cap — so the viewer can report honestly. */
  totalColumns: number;
};

type CsvOptions = {
  maxRows?: number;
  maxColumns?: number;
  /** Set when the text came from a byte-truncated slice, so the last row is known to be partial. */
  sourceTruncated?: boolean;
};

/**
 * A bounded RFC 4180 parser — comma-delimited, with quoting.
 *
 * Hand-written rather than pulled from a package because the requirement is one bounded read of at
 * most 500 rows for display: quoted fields, doubled quotes, and commas and newlines inside quotes.
 * A parsing library would bring streaming, type coercion, header mapping, worker support and a
 * dependency to keep patched, none of which this screen uses.
 *
 * DELIMITER IS ALWAYS A COMMA. Sniffing between `,`, `;` and tab is guesswork that fails silently
 * and differently per file; a semicolon-delimited export therefore previews as a single column,
 * which is visibly wrong and recoverable by downloading, rather than subtly wrong.
 *
 * `rowStarted` is what makes the trailing-field cases correct: `a,b,` is three fields (the last
 * empty) while `a,b\n` is two, and only tracking whether the row has been entered distinguishes
 * them at end of input.
 */
export function parseCsv(text: string, options: CsvOptions = {}): CsvPreview {
  const maxRows = options.maxRows ?? MAX_CSV_ROWS;
  const maxColumns = options.maxColumns ?? MAX_CSV_COLUMNS;

  const rows: string[][] = [];
  let row: string[] = [];
  let field = "";
  let inQuotes = false;
  let rowStarted = false;
  let columnsInRow = 0;
  let totalColumns = 0;
  let columnsTruncated = false;
  let rowsTruncated = false;

  const addField = () => {
    columnsInRow++;
    if (row.length < maxColumns) {
      row.push(field.length > MAX_CELL_LENGTH ? `${field.slice(0, MAX_CELL_LENGTH)}…` : field);
    } else {
      columnsTruncated = true;
    }
    field = "";
  };

  const endRow = () => {
    addField();
    totalColumns = Math.max(totalColumns, columnsInRow);
    rows.push(row);
    row = [];
    columnsInRow = 0;
    rowStarted = false;
  };

  for (let i = 0; i < text.length; i++) {
    const char = text[i];

    if (inQuotes) {
      if (char === '"') {
        if (text[i + 1] === '"') {
          // A doubled quote inside a quoted field is one literal quote.
          field += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        field += char;
      }
      continue;
    }

    // A quote only opens a quoted field at the START of one; elsewhere it is a literal character,
    // which is how most real-world exports behave and keeps a stray quote from eating the file.
    if (char === '"' && field === "") {
      inQuotes = true;
      rowStarted = true;
      continue;
    }

    if (char === ",") {
      addField();
      rowStarted = true;
      continue;
    }

    if (char === "\n" || char === "\r") {
      if (char === "\r" && text[i + 1] === "\n") {
        i++;
      }
      endRow();

      if (rows.length >= maxRows) {
        rowsTruncated = i < text.length - 1;
        return finish();
      }
      continue;
    }

    field += char;
    rowStarted = true;
  }

  // Trailing content with no final newline is still a row.
  if (rowStarted || field !== "") {
    endRow();
  }

  function finish(): CsvPreview {
    // A byte-truncated file ends mid-row; drop that partial last row rather than showing a record
    // that is missing its right-hand columns.
    if (options.sourceTruncated && rows.length > 1) {
      rows.pop();
    }

    return {
      rows,
      rowsTruncated: rowsTruncated || options.sourceTruncated === true,
      columnsTruncated,
      totalColumns,
    };
  }

  return finish();
}
