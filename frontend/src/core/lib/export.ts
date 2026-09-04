// Client-side report exports — no external dependency, no backend round-trip.
// Excel: an HTML workbook saved as .xls (opens in Excel/Google Sheets, keeps
// numbers numeric). PDF: a clean print window the browser saves as PDF.

type Cell = string | number | null | undefined;

function esc(v: Cell): string {
  const s = v == null ? "" : String(v);
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function isNumber(v: Cell): boolean {
  return typeof v === "number" && Number.isFinite(v);
}

/**
 * Export a table to Excel (.xls). `title` becomes a bold caption row; numeric
 * cells stay numeric so the accountant can total them.
 */
export function downloadExcel(
  filename: string,
  title: string,
  headers: string[],
  rows: Cell[][],
): void {
  const head = headers.map((h) => `<th style="background:#ECFBFA;color:#12756F;font-weight:bold">${esc(h)}</th>`).join("");
  const body = rows
    .map(
      (r) =>
        "<tr>" +
        r
          .map((c) =>
            isNumber(c)
              ? `<td style="mso-number-format:'#,##0.00'" align="right">${c}</td>`
              : `<td>${esc(c)}</td>`,
          )
          .join("") +
        "</tr>",
    )
    .join("");

  const html =
    `<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>` +
    `<table border="1" cellspacing="0"><caption style="font-weight:bold;text-align:left;font-size:14px">${esc(title)}</caption>` +
    `<thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></body></html>`;

  const blob = new Blob(["﻿" + html], { type: "application/vnd.ms-excel" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename.endsWith(".xls") ? filename : `${filename}.xls`;
  a.click();
  URL.revokeObjectURL(url);
}

/**
 * Open a clean, report-only print window and trigger print — the browser's
 * "Save as PDF" produces a tidy PDF (no app chrome). `subtitle` is optional.
 */
export function printTable(
  title: string,
  subtitle: string | null,
  headers: string[],
  rows: Cell[][],
): void {
  const head = headers.map((h) => `<th>${esc(h)}</th>`).join("");
  const body = rows
    .map(
      (r) =>
        "<tr>" +
        r.map((c) => `<td class="${isNumber(c) ? "num" : ""}">${esc(c)}</td>`).join("") +
        "</tr>",
    )
    .join("");

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #16201E; margin: 32px; }
  h1 { font-size: 20px; margin: 0 0 2px; }
  .sub { color: #545F5B; font-size: 12px; margin: 0 0 18px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th { background: #ECFBFA; color: #12756F; text-align: left; padding: 7px 9px; border-bottom: 2px solid #4FDBD7; text-transform: uppercase; font-size: 10px; letter-spacing: .04em; }
  td { padding: 6px 9px; border-bottom: 1px solid #E0E8E6; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  .foot { margin-top: 22px; color: #8A9793; font-size: 10px; }
  @media print { body { margin: 12mm; } }
</style></head><body>
  <h1>${esc(title)}</h1>
  ${subtitle ? `<p class="sub">${esc(subtitle)}</p>` : ""}
  <table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
  <p class="foot">Vite SEO — Accounting System · generated ${new Date().toLocaleString("en-PH")}</p>
</body></html>`;

  const w = window.open("", "_blank", "width=900,height=700");
  if (!w) return;
  w.document.write(html);
  w.document.close();
  w.focus();
  // Give the new window a tick to render before printing.
  setTimeout(() => w.print(), 250);
}
