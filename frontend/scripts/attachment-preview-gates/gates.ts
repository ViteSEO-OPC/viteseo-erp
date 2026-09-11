/**
 * Lead attachment preview gates.
 *
 * Run with:  npm run preview:gates        (from frontend/)
 *
 * WHY THIS EXISTS AS A SCRIPT AND NOT A TEST. `frontend/` has no test runner, and adding one for
 * three pure modules would be a dependency and a config to keep patched. This follows the recipe
 * scripts/copilot-gates/ already established: Node 24 strips the TypeScript natively and that
 * directory's loader.mjs bridges the "@/" alias, so the gates drive the REAL modules straight from
 * source — never a copy that could drift from what the dialog runs.
 *
 * Exits non-zero if any gate fails, so it can be wired into CI unchanged.
 *
 * ── WHAT EACH GATE VERIFIES ──────────────────────────────────────────────────────────────────
 *
 * A  KIND DETECTION — every previewable format resolves to the viewer that can actually render it.
 * B  THE DOWNLOAD-ONLY SET — doc/docx/ppt/pptx/zip must stay non-previewable WITHOUT the server's
 *    capability flag. This gate is the one that fails if someone widens the predicate without
 *    building the viewer behind it, which is exactly the "UI promises a preview it cannot deliver"
 *    defect this whole slice exists to avoid. Its cases are unchanged: a server without a conversion
 *    service must behave precisely as every server did before the converter existed.
 * C  IMAGE AND PDF REGRESSION — the two kinds that already worked in production answer identically.
 * D  CSV PARSING — quoting, doubled quotes, embedded commas and newlines, CRLF, and the trailing
 *    field cases where `a,b,` (three fields) must not be confused with `a,b\n` (two).
 * E  TEXT DECODING AND BOUNDS — BOM, UTF-8, the Windows-1252 fallback real Excel exports need, and
 *    the byte cap, including a multi-byte character split by the cap.
 */
import {
  previewKindFor,
  isPreviewable,
} from "@/modules/crm/features/leads/lib/attachment-preview";
import {
  decodeAttachmentText,
  parseCsv,
  toTextPreview,
  MAX_PREVIEW_BYTES,
} from "@/modules/crm/features/leads/lib/attachment-text";

let failed = 0;
function report(gate: string, ok: number, total: number, detail: string[] = []) {
  const pass = ok === total;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${gate.padEnd(46)} ${ok}/${total}`);
  for (const d of detail.slice(0, 10)) console.log(`         ${d}`);
  if (detail.length > 10) console.log(`         … and ${detail.length - 10} more`);
}

/** Structural equality for the small arrays these gates compare. */
function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

const bytes = (...values: number[]) => new Uint8Array(values);
const utf8 = (text: string) => new TextEncoder().encode(text);

// ── A  kind detection ───────────────────────────────────────────────────────────────────────
{
  const cases: [string, string, string | null][] = [
    // [mimeType, filename, expected kind]
    ["text/plain", "notes.txt", "text"],
    ["application/octet-stream", "notes.txt", "text"],
    ["text/csv", "export.csv", "csv"],
    // The case that drove extension-first: Windows labels CSVs as Excel.
    ["application/vnd.ms-excel", "export.csv", "csv"],
    ["text/plain", "export.csv", "csv"],
    ["application/vnd.ms-excel", "book.xls", "sheet"],
    [
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "book.xlsx",
      "sheet",
    ],
    // fileinfo reports the zip container for OOXML on some hosts; the extension still decides.
    ["application/zip", "book.xlsx", "sheet"],
    ["", "book.xlsx", "sheet"],
    // Extensionless uploads fall back to the MIME.
    ["text/csv", "export", "csv"],
    ["text/plain", "readme", "text"],
    ["application/vnd.ms-excel", "workbook", "sheet"],
    // Case-insensitive on both signals.
    ["TEXT/CSV", "EXPORT.CSV", "csv"],
    ["", "BOOK.XLSX", "sheet"],
  ];

  const bad = cases
    .filter(([mime, name, expected]) => previewKindFor(mime, name) !== expected)
    .map(([mime, name, expected]) => `${name} (${mime || "no mime"}) → ${previewKindFor(mime, name)}, want ${expected}`);

  report("A  kind detection", cases.length - bad.length, cases.length, bad);
}

// ── B  the download-only set ────────────────────────────────────────────────────────────────
{
  const downloadOnly: [string, string][] = [
    ["application/msword", "contract.doc"],
    ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "contract.docx"],
    ["application/vnd.ms-powerpoint", "deck.ppt"],
    ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "deck.pptx"],
    ["application/zip", "bundle.zip"],
    ["application/octet-stream", "bundle.zip"],
    ["application/octet-stream", "contract.docx"],
    // A docx whose MIME fileinfo reported as the zip container must STILL not be previewable.
    ["application/zip", "contract.docx"],
  ];

  const bad = downloadOnly
    .filter(([mime, name]) => isPreviewable(mime, name))
    .map(([mime, name]) => `${name} (${mime}) is previewable but must not be — kind ${previewKindFor(mime, name)}`);

  report("B  doc/ppt/zip stay download-only", downloadOnly.length - bad.length, downloadOnly.length, bad);
}

// ── C  image and PDF regression ─────────────────────────────────────────────────────────────
{
  const cases: [string, string, string | null][] = [
    ["application/pdf", "proposal.pdf", "pdf"],
    ["application/octet-stream", "proposal.pdf", "pdf"],
    ["", "proposal.pdf", "pdf"],
    ["image/png", "logo.png", "image"],
    ["image/jpeg", "photo.jpg", "image"],
    ["image/webp", "shot.webp", "image"],
    ["image/gif", "anim.gif", "image"],
    ["application/octet-stream", "shot.jpeg", "image"],
    ["application/octet-stream", "shot.webp", "image"],
    // A PDF mislabelled with an unrelated, non-generic MIME stayed non-previewable before and must
    // continue to: the octet-stream fallback is deliberately narrow.
    ["text/csv", "proposal.pdf", null],
    // Regression guard: the MIME fallback must never override a known-but-different extension, or
    // the text viewer is handed PDF bytes.
    ["text/plain", "contract.docx", null],
    ["application/vnd.ms-excel", "bundle.zip", null],
    // A file whose CONTENT is known to be a PDF is a PDF, whatever it is named — content wins where
    // content is what the MIME reports.
    ["application/pdf", "export.csv", "pdf"],
  ];

  const bad = cases
    .filter(([mime, name, expected]) => previewKindFor(mime, name) !== expected)
    .map(([mime, name, expected]) => `${name} (${mime}) → ${previewKindFor(mime, name)}, want ${expected}`);

  report("C  image/pdf unchanged", cases.length - bad.length, cases.length, bad);
}

// ── D  CSV parsing ──────────────────────────────────────────────────────────────────────────
{
  const checks: [string, boolean][] = [
    ["plain rows", same(parseCsv("a,b\nc,d").rows, [["a", "b"], ["c", "d"]])],
    ["comma inside quotes", same(parseCsv('a,"b,c"').rows, [["a", "b,c"]])],
    ["doubled quote is one literal quote", same(parseCsv('"say ""hi"""').rows, [['say "hi"']])],
    ["newline inside quotes", same(parseCsv('"line1\nline2",x').rows, [["line1\nline2", "x"]])],
    ["CRLF row separator", same(parseCsv("a,b\r\nc,d").rows, [["a", "b"], ["c", "d"]])],
    ["bare CR row separator", same(parseCsv("a\rb").rows, [["a"], ["b"]])],
    // The pair `rowStarted` exists to tell apart.
    ["trailing comma is an empty final field", same(parseCsv("a,b,").rows, [["a", "b", ""]])],
    ["trailing newline is not an extra row", same(parseCsv("a,b\n").rows, [["a", "b"]])],
    ["empty input yields no rows", same(parseCsv("").rows, [])],
    ["quoted field then plain field", same(parseCsv('"a",b').rows, [["a", "b"]])],
    ["a stray quote mid-field stays literal", same(parseCsv('a"b,c').rows, [['a"b', "c"]])],
    ["empty fields survive", same(parseCsv(",,").rows, [["", "", ""]])],
    ["row cap applies", parseCsv("1\n2\n3\n4\n5\n", { maxRows: 3 }).rows.length === 3],
    ["row cap reports truncation", parseCsv("1\n2\n3\n4\n5\n", { maxRows: 3 }).rowsTruncated],
    ["under the cap is not truncated", !parseCsv("1\n2\n").rowsTruncated],
    ["column cap applies", same(parseCsv("a,b,c,d", { maxColumns: 2 }).rows, [["a", "b"]])],
    ["column cap reports truncation", parseCsv("a,b,c,d", { maxColumns: 2 }).columnsTruncated],
    ["column cap still reports the real width", parseCsv("a,b,c,d", { maxColumns: 2 }).totalColumns === 4],
    // A byte-truncated source ends mid-row; that partial row must not be shown as complete.
    ["byte-truncated source drops its partial last row", same(parseCsv("a,b\nc,d\ne", { sourceTruncated: true }).rows, [["a", "b"], ["c", "d"]])],
    ["byte-truncated source reports truncation", parseCsv("a,b\nc,d\ne", { sourceTruncated: true }).rowsTruncated],
  ];

  const bad = checks.filter(([, ok]) => !ok).map(([label]) => `failed: ${label}`);
  report("D  CSV parsing", checks.length - bad.length, checks.length, bad);
}

// ── E  text decoding and bounds ─────────────────────────────────────────────────────────────
{
  const bom = decodeAttachmentText(bytes(0xef, 0xbb, 0xbf, 0x68, 0x69));
  const plain = decodeAttachmentText(utf8("hello"));
  const multibyte = decodeAttachmentText(utf8("Piso ₱1,200 — Bacolod"));
  // 0x93 is a Windows-1252 smart quote and an invalid UTF-8 lead byte.
  const cp1252 = decodeAttachmentText(bytes(0x41, 0x93, 0x42));

  const oversize = new Uint8Array(MAX_PREVIEW_BYTES + 10).fill(0x61);
  const capped = decodeAttachmentText(oversize);

  // A three-byte character straddling the cap: bytes MAX-1..MAX+1 are the euro sign.
  const split = new Uint8Array(MAX_PREVIEW_BYTES + 2).fill(0x61);
  split[MAX_PREVIEW_BYTES - 1] = 0xe2;
  split[MAX_PREVIEW_BYTES] = 0x82;
  split[MAX_PREVIEW_BYTES + 1] = 0xac;
  const splitDecoded = decodeAttachmentText(split);

  const checks: [string, boolean][] = [
    ["BOM is stripped", bom.text === "hi"],
    ["UTF-8 decodes", plain.text === "hello" && plain.encoding === "utf-8"],
    ["multi-byte characters survive", multibyte.text === "Piso ₱1,200 — Bacolod"],
    ["small files are not marked truncated", !plain.truncated],
    ["invalid UTF-8 falls back to windows-1252", cp1252.encoding === "windows-1252"],
    // The exact glyph for 0x93 is deliberately NOT asserted. Browsers follow the WHATWG index and
    // give U+201C; this Node build's decoder is latin1-ish and gives U+0093. Only the browser
    // behaviour ships, so the gate asserts the properties that hold in both — every byte decodes to
    // exactly one character, and nothing is lost to a replacement glyph.
    ["the windows-1252 fallback decodes rather than throwing", cp1252.text.length === 3],
    ["the windows-1252 fallback loses nothing", !cp1252.text.includes("\uFFFD") && cp1252.text.startsWith("A") && cp1252.text.endsWith("B")],
    ["oversize input is capped", capped.truncated && capped.text.length <= MAX_PREVIEW_BYTES],
    // Without the trim retry this would be misdiagnosed as windows-1252 over one split glyph.
    ["a character split by the cap stays UTF-8", splitDecoded.encoding === "utf-8"],
    ["a character split by the cap is not half-rendered", !splitDecoded.text.includes("�")],
    ["accepts an ArrayBuffer as well as a view", decodeAttachmentText(utf8("ok").buffer as ArrayBuffer).text === "ok"],
  ];

  const preview = toTextPreview(decodeAttachmentText(utf8("l1\nl2\nl3\nl4")), 2);
  const untruncated = toTextPreview(decodeAttachmentText(utf8("l1\nl2")), 10);
  const partial = toTextPreview({ text: "l1\nl2\npartia", truncated: true, encoding: "utf-8" }, 10);

  checks.push(
    ["the line cap applies", preview.lines.length === 2 && preview.truncated],
    ["a short file is not marked truncated", !untruncated.truncated && untruncated.lines.length === 2],
    ["a byte-truncated file drops its partial last line", same(partial.lines, ["l1", "l2"])],
  );

  const bad = checks.filter(([, ok]) => !ok).map(([label]) => `failed: ${label}`);
  report("E  text decoding and bounds", checks.length - bad.length, checks.length, bad);
}

// ── F  Office documents, only with the server's capability ──────────────────────────────────
{
  const capable = { documentPreview: true };
  const cases: [string, string, { documentPreview?: boolean }, string | null][] = [
    // [mimeType, filename, options, expected kind]
    ["application/msword", "contract.doc", capable, "document"],
    ["application/vnd.openxmlformats-officedocument.wordprocessingml.document", "contract.docx", capable, "document"],
    ["application/vnd.ms-powerpoint", "deck.ppt", capable, "document"],
    ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "deck.pptx", capable, "document"],
    // fileinfo reports OOXML as the zip container on some hosts; the extension still decides.
    ["application/zip", "contract.docx", capable, "document"],
    ["application/octet-stream", "contract.docx", capable, "document"],
    ["", "DECK.PPTX", capable, "document"],
    // ZIP stays Download-only EVEN WHEN the server can convert Office documents.
    ["application/zip", "bundle.zip", capable, null],
    ["application/octet-stream", "bundle.zip", capable, null],
    // No extension, no document preview: the endpoint requires the extension as well as the bytes.
    ["application/msword", "contract", capable, null],
    // The flag must change NOTHING else.
    ["application/pdf", "proposal.pdf", capable, "pdf"],
    ["application/vnd.ms-excel", "book.xls", capable, "sheet"],
    ["text/csv", "export.csv", capable, "csv"],
    ["image/png", "logo.png", capable, "image"],
    // Explicitly false, or absent, is the Download-only answer gate B already pins.
    ["application/msword", "contract.doc", { documentPreview: false }, null],
    ["application/vnd.openxmlformats-officedocument.presentationml.presentation", "deck.pptx", {}, null],
  ];

  const bad = cases
    .filter(([mime, name, options, expected]) => previewKindFor(mime, name, options) !== expected)
    .map(
      ([mime, name, options, expected]) =>
        `${name} (${mime || "no mime"}, ${JSON.stringify(options)}) → ${previewKindFor(mime, name, options)}, want ${expected}`,
    );

  report("F  office docs need the server capability", cases.length - bad.length, cases.length, bad);
}

console.log(failed === 0 ? "\nAll gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
