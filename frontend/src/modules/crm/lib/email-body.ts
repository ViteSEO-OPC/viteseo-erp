/**
 * Presentation logic for an email body — plain-text structure, links, and the document an HTML email
 * is rendered in. Free of React and the DOM so scripts/email-render-gates can drive it from source.
 *
 * ── Where safety comes from ──
 * Plain text reaches the page only as React text nodes, plus anchors whose href is built HERE from a
 * scheme allow-list — never from markup. HTML reaches the page only inside the sandboxed, script-less
 * frame whose document {@see buildEmailSrcDoc} builds, with a Content-Security-Policy that blocks every
 * remote load except (after an explicit opt-in) images. The server's EmailHtmlSanitizer is a separate,
 * independent layer in front of that; neither assumes the other ran.
 */

// ── Links ────────────────────────────────────────────────────────────────────────────────────

export type TextToken = { kind: "text"; text: string } | { kind: "link"; text: string; href: string };

/** URL, bare `www.` host, or email address. Candidates only — {@see hrefFor} decides what links. */
const LINK_CANDIDATE =
  /\bhttps?:\/\/[^\s<>"'`]+|\bwww\.[^\s<>"'`]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/gi;

function count(text: string, char: string): number {
  return text.split(char).length - 1;
}

/**
 * Sentence punctuation after a URL belongs to the sentence. A closing bracket is kept only when the URL
 * opened one itself — `https://en.wikipedia.org/wiki/SEO_(marketing)` — and dropped when it closes
 * prose around the link: `(see https://viteseo.ph)`.
 */
function trimTrailing(candidate: string): string {
  let url = candidate;
  for (;;) {
    const last = url.at(-1);
    if (last !== undefined && ".,;:!?".includes(last)) {
      url = url.slice(0, -1);
    } else if (last === ")" && count(url, "(") < count(url, ")")) {
      url = url.slice(0, -1);
    } else if (last === "]" && count(url, "[") < count(url, "]")) {
      url = url.slice(0, -1);
    } else {
      return url;
    }
  }
}

function hrefFor(text: string): string | null {
  const isUrl = /^(?:https?:\/\/|www\.)/i.test(text);
  if (!isUrl) return text.includes("@") ? `mailto:${text}` : null;

  const absolute = /^www\./i.test(text) ? `https://${text}` : text;
  try {
    const url = new URL(absolute);
    return url.protocol === "http:" || url.protocol === "https:" ? absolute : null;
  } catch {
    return null;
  }
}

/** Split text into plain runs and links. Concatenating every token's `text` gives back the input. */
export function linkifyText(text: string): TextToken[] {
  const tokens: TextToken[] = [];
  let cursor = 0;

  for (const match of text.matchAll(LINK_CANDIDATE)) {
    const candidate = trimTrailing(match[0]);
    const href = candidate === "" ? null : hrefFor(candidate);
    if (href === null) continue;

    if (match.index > cursor) tokens.push({ kind: "text", text: text.slice(cursor, match.index) });
    tokens.push({ kind: "link", text: candidate, href });
    cursor = match.index + candidate.length;
  }

  if (cursor < text.length) tokens.push({ kind: "text", text: text.slice(cursor) });
  return tokens;
}

/**
 * Whether an href found in an HTML email may stay clickable. Browsers drop tabs, newlines and leading
 * control characters from a URL before reading its scheme (`java\tscript:` IS `javascript:`), so the
 * same characters are dropped here before the check rather than letting the two readings differ.
 */
export function isSafeEmailHref(href: string): boolean {
  const compact = Array.from(href)
    .filter((char) => char.charCodeAt(0) > 0x20)
    .join("");
  return /^(?:https?:\/\/|mailto:|tel:)/i.test(compact);
}

// ── Plain-text structure ─────────────────────────────────────────────────────────────────────

/** `text` is the message's own words; `quote` is a `>`-quoted run, shown with its markers removed. */
export type PlainBlock = { kind: "text" | "quote"; text: string };

export type ParsedPlainEmail = {
  main: PlainBlock[];
  /** Text below an RFC 3676 `-- ` delimiter, or null when there is none. */
  signature: string | null;
  /** Earlier messages in the thread, collapsed by default. Null when there is nothing to collapse. */
  quoted: PlainBlock[] | null;
};

// "On Mon, Sep 8, 2026 at 10:00 AM Vite SEO <business@viteseo.ph> wrote:" — Gmail, Apple Mail, and
// most mobile clients. Gmail wraps a long one across two or three lines.
const ATTRIBUTION_ONE_LINE = /^\s*On\s.+\swrote:\s*$/i;
const ATTRIBUTION_START = /^\s*On\s/i;
const ATTRIBUTION_END = /\bwrote:\s*$/i;
// Outlook desktop and older clients.
const ORIGINAL_MESSAGE = /^\s*-{2,}\s*Original Message\s*-{2,}\s*$/i;
// Outlook's reply header: "From: …" followed within a few lines by "Sent: …" or "Date: …".
const HEADER_FROM = /^\s*\*?From:\*?\s+\S/i;
const HEADER_DATE = /^\s*\*?(?:Sent|Date):\*?\s+\S/i;
const SEPARATOR = /^\s*(?:_{10,}|-{10,})\s*$/;
const QUOTE_LINE = /^\s*>/;
const SIGNATURE_DELIMITER = /^-- ?$/;

const isBlank = (line: string) => line.trim() === "";

function trimBlankEdges(lines: string[]): string[] {
  let start = 0;
  let end = lines.length;
  while (start < end && isBlank(lines[start])) start++;
  while (end > start && isBlank(lines[end - 1])) end--;
  return lines.slice(start, end);
}

/** Index of the first line of the quoted history, or null when the message has none. */
function historyStart(lines: string[]): number | null {
  let start: number | null = null;

  // A trailing run of `>` lines (blank lines allowed) with no attribution above it.
  let tail = lines.length;
  while (tail > 0 && (isBlank(lines[tail - 1]) || QUOTE_LINE.test(lines[tail - 1]))) tail--;
  const firstQuote = lines.findIndex((line, i) => i >= tail && QUOTE_LINE.test(line));
  if (firstQuote !== -1) start = firstQuote;

  // An attribution or reply header wins when it sits above that run — it belongs to the history.
  const limit = start ?? lines.length;
  for (let i = 0; i < limit; i++) {
    const line = lines[i];

    if (ORIGINAL_MESSAGE.test(line) || ATTRIBUTION_ONE_LINE.test(line)) return i;

    if (ATTRIBUTION_START.test(line)) {
      const next = lines[i + 1] ?? "";
      const after = lines[i + 2] ?? "";
      if (!isBlank(next) && ATTRIBUTION_END.test(next)) return i;
      if (!isBlank(next) && !isBlank(after) && ATTRIBUTION_END.test(after)) return i;
    }

    if (HEADER_FROM.test(line) && lines.slice(i + 1, i + 5).some((l) => HEADER_DATE.test(l))) {
      if (i > 0 && SEPARATOR.test(lines[i - 1])) return i - 1;
      if (i === 0 || isBlank(lines[i - 1])) return i;
    }
  }

  return start;
}

/** Group lines into text and quote runs. A blank line stays with the run it sits in. */
function toBlocks(lines: string[]): PlainBlock[] {
  const runs: { kind: PlainBlock["kind"]; lines: string[] }[] = [];

  for (const line of trimBlankEdges(lines)) {
    const last = runs.at(-1);
    const kind: PlainBlock["kind"] =
      isBlank(line) && last ? last.kind : QUOTE_LINE.test(line) ? "quote" : "text";
    const content = kind === "quote" ? line.replace(/^(?:\s*>)+ ?/, "") : line;

    if (last && last.kind === kind) last.lines.push(content);
    else runs.push({ kind, lines: [content] });
  }

  return runs
    .map((run) => ({ kind: run.kind, text: trimBlankEdges(run.lines).join("\n") }))
    .filter((block) => block.text.trim() !== "");
}

/**
 * Split a plain-text email into the message itself, its signature, and the quoted history below it.
 *
 * Only a TRAILING history is collapsed. Quotes interleaved with the reply (answering point by point)
 * are part of what the sender wrote and stay visible as quote blocks. And a message that is nothing
 * but quoted text is never collapsed — hiding all of it would hide the email.
 */
export function parsePlainTextEmail(body: string): ParsedPlainEmail {
  const lines = body.replace(/\r\n?/g, "\n").split("\n");

  const start = historyStart(lines);
  const collapsible = start !== null && !lines.slice(0, start).every(isBlank);
  const mainLines = collapsible ? lines.slice(0, start) : lines;

  let signature: string | null = null;
  let contentLines = mainLines;
  const delimiter = mainLines.findLastIndex((line) => SIGNATURE_DELIMITER.test(line));
  if (delimiter > 0 && !mainLines.slice(0, delimiter).every(isBlank)) {
    const text = trimBlankEdges(mainLines.slice(delimiter + 1)).join("\n");
    if (text !== "") {
      signature = text;
      contentLines = mainLines.slice(0, delimiter);
    }
  }

  return {
    main: toBlocks(contentLines),
    signature,
    quoted: collapsible ? toBlocks(lines.slice(start)) : null,
  };
}

// ── HTML frame ───────────────────────────────────────────────────────────────────────────────

/**
 * The frame's sandbox. Deliberately WITHOUT `allow-scripts`: nothing the sender wrote can ever run.
 *
 *   allow-same-origin                 lets the Email Conversation read the document's height and
 *                                     toggle quoted history. Safe only because scripts are off — the
 *                                     dangerous pairing is same-origin WITH scripts, never this.
 *   allow-popups (+ -to-escape-…)     links open in a new tab, as a normal, unsandboxed page.
 *
 * No `allow-forms`, `allow-top-navigation`, or `allow-modals`.
 */
export const EMAIL_FRAME_SANDBOX = "allow-same-origin allow-popups allow-popups-to-escape-sandbox";

/** Class on the frame's <html> while the quoted history is hidden. */
export const QUOTES_COLLAPSED_CLASS = "zerem-quotes-collapsed";

/**
 * Where common clients put earlier messages in an HTML reply. Anything not matched here is simply
 * shown — an unrecognised client costs a longer message, never lost content.
 */
export const QUOTED_HISTORY_SELECTOR = [
  ".gmail_quote", // Gmail: attribution line + quoted blockquote
  ".gmail_quote_container",
  'blockquote[type="cite"]', // Apple Mail, Thunderbird
  ".moz-cite-prefix", // Thunderbird's "On … wrote:" line
  ".yahoo_quoted",
  "#appendonsend ~ *", // Outlook on the web: everything after its reply marker
  "#divRplyFwdMsg",
  "#divRplyFwdMsg ~ *",
].join(", ");

/**
 * The frame document's policy. `default-src 'none'` covers scripts, stylesheets, fonts, frames,
 * objects and media, so the only thing that ever varies is whether remote IMAGES may load — and they
 * may not until the viewer asks. A blocked image is never requested, so a tracking pixel learns nothing.
 * `http:` images stay blocked even after the opt-in: the CRM is served over https.
 */
export function emailFrameCsp(showRemoteImages: boolean): string {
  return [
    "default-src 'none'",
    "style-src 'unsafe-inline'",
    showRemoteImages ? "img-src https: data:" : "img-src data:",
    "font-src data:",
    "form-action 'none'",
    "base-uri 'none'",
  ].join("; ");
}

/**
 * Layout guards, deliberately low-specificity so the sender's own styles still win. System colours
 * rather than tokens: the CRM's CSS variables do not reach inside the frame, and email HTML is written
 * for a light page, so the frame stays light in both themes, the way mail clients show it.
 *
 * `html` hides overflow so `body` keeps its own: a newsletter's fixed 600px table scrolls sideways
 * inside the message instead of widening the thread. `object-fit: contain` narrows a wide image to the
 * message without distorting it — and without forcing `height: auto`, which would stretch a 1×1 spacer
 * image into a tall blank block.
 */
const FRAME_BASE_CSS = [
  ":root{color-scheme:light}",
  "html{overflow:hidden;background:Canvas}",
  "body{margin:0;padding:12px 16px;display:flow-root;overflow-x:auto;overflow-y:hidden;background:Canvas;color:CanvasText;" +
    'font:14px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;overflow-wrap:break-word}',
  "img{max-width:100% !important;object-fit:contain}",
  "table{max-width:100%}",
  "pre{white-space:pre-wrap}",
  "a{color:LinkText}",
  "blockquote{margin:.5em 0 .5em .25em;padding-left:.75em;border-left:2px solid GrayText}",
  `html.${QUOTES_COLLAPSED_CLASS} :is(${QUOTED_HISTORY_SELECTOR}){display:none !important}`,
].join("");

/**
 * The complete document for the frame's `srcDoc`.
 *
 * Head order matters. `<base href="about:blank">` comes first so a relative URL in the email resolves
 * to nothing rather than against the CRM's own origin (a srcdoc frame otherwise inherits the parent's
 * base URL); the CSP meta follows before any content can be parsed. Everything the sender wrote lands
 * in <body>, where the parser can no longer add to the head. Their own <meta> tags are removed first:
 * a `refresh` pragma would otherwise still navigate the frame.
 */
export function buildEmailSrcDoc(html: string, options: { showRemoteImages: boolean }): string {
  const content = html.replace(/<meta\b[^>]*>/gi, "");

  return (
    "<!doctype html><html><head>" +
    '<base href="about:blank" target="_blank">' +
    `<meta http-equiv="Content-Security-Policy" content="${emailFrameCsp(options.showRemoteImages)}">` +
    '<meta name="referrer" content="no-referrer">' +
    `<style>${FRAME_BASE_CSS}</style>` +
    "</head><body>" +
    content +
    "</body></html>"
  );
}

/** Remote resources the policy is holding back. Drives only whether "Show images" is offered. */
const REMOTE_REFERENCE = [
  /<(?:img|image|input|video|source)\b[^>]*?\s(?:src|srcset|poster)\s*=\s*["']?\s*(?!data:|cid:)[^\s"'>]/i,
  /\sbackground\s*=\s*["']?\s*(?!data:|cid:)[^\s"'>]/i,
  /url\(\s*["']?\s*(?!data:|cid:)[^\s"')]/i,
  /@import\b/i,
];

/**
 * Whether the email references anything the frame blocks. UX only — a false positive shows a button
 * that loads nothing new; the policy, not this check, is what keeps remote images from loading.
 */
export function hasRemoteContent(html: string): boolean {
  return REMOTE_REFERENCE.some((pattern) => pattern.test(html));
}
