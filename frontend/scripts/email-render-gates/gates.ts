/**
 * Email body rendering gates.
 *
 * Run with:  npm run email:gates        (from frontend/)
 *
 * Same recipe as scripts/attachment-preview-gates/: `frontend/` has no test runner, so Node strips the
 * TypeScript natively and copilot-gates/loader.mjs bridges the "@/" alias. The gates drive the REAL
 * module the Email Conversation renders with — never a copy that could drift from it.
 *
 * Exits non-zero if any gate fails.
 *
 * ── WHAT EACH GATE VERIFIES ──────────────────────────────────────────────────────────────────
 *
 * A  LINKS — http(s), www. and email addresses link; trailing punctuation and wrapping brackets stay
 *    text; `javascript:`/`data:` never link; the tokens always reassemble into the original text.
 * B  QUOTED HISTORY (plain text) — Gmail, wrapped-attribution, Outlook and "Original Message" histories
 *    collapse; interleaved quotes and an all-quoted message do NOT; CRLF is handled.
 * C  SIGNATURES — the `-- ` delimiter splits the signature off, but never swallows the whole message.
 * D  FRAME DOCUMENT — remote images blocked by default and allowed only by the opt-in; no script source
 *    is ever permitted; the policy precedes the sender's content; the sender's <meta> tags are removed.
 * E  REMOTE CONTENT DETECTION — which emails offer "Show images".
 * F  LINK SCHEMES — the href allow-list used inside the frame, including obfuscated `javascript:`.
 * G  STATIC — no dangerouslySetInnerHTML in the renderer or the thread; the frame is sandboxed without
 *    scripts, forms, top navigation or modals; only CRM-sent (outbound) messages get a trusted source.
 * H  TRUSTED IMAGE SOURCE — the CRM's own email-asset path is the ONLY thing a sent design's frame
 *    trusts: an exact path prefix, never a host or scheme, and nothing that could break out of the
 *    policy attribute or add a directive.
 */
import { readFileSync, readdirSync } from "node:fs";
import {
  EMAIL_FRAME_SANDBOX,
  QUOTES_COLLAPSED_CLASS,
  buildEmailSrcDoc,
  emailAssetImageSource,
  emailFrameCsp,
  hasRemoteContent,
  isSafeEmailHref,
  linkifyText,
  parsePlainTextEmail,
  type TextToken,
} from "@/modules/crm/lib/email-body";

let failed = 0;
function report(gate: string, ok: number, total: number, detail: string[] = []) {
  const pass = ok === total;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${gate.padEnd(46)} ${ok}/${total}`);
  for (const d of detail.slice(0, 10)) console.log(`         ${d}`);
  if (detail.length > 10) console.log(`         … and ${detail.length - 10} more`);
}

/** Run named boolean checks as one gate. */
function gate(name: string, checks: [string, boolean][]) {
  const failures = checks.filter(([, ok]) => !ok).map(([label]) => label);
  report(name, checks.length - failures.length, checks.length, failures);
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
const links = (tokens: TextToken[]) =>
  tokens.flatMap((t) => (t.kind === "link" ? [[t.text, t.href]] : []));
const reassembles = (text: string) => linkifyText(text).map((t) => t.text).join("") === text;

// ── A  links ────────────────────────────────────────────────────────────────────────────────
{
  const cases: [string, string[][]][] = [
    ["See https://viteseo.ph/pricing.", [["https://viteseo.ph/pricing", "https://viteseo.ph/pricing"]]],
    ["(see https://viteseo.ph/a)", [["https://viteseo.ph/a", "https://viteseo.ph/a"]]],
    [
      "https://en.wikipedia.org/wiki/SEO_(marketing)",
      [["https://en.wikipedia.org/wiki/SEO_(marketing)", "https://en.wikipedia.org/wiki/SEO_(marketing)"]],
    ],
    ["Visit www.example.com today", [["www.example.com", "https://www.example.com"]]],
    ["Mail maria@example.ph, thanks", [["maria@example.ph", "mailto:maria@example.ph"]]],
    ["Proposal <https://viteseo.ph/p/42>", [["https://viteseo.ph/p/42", "https://viteseo.ph/p/42"]]],
    ["http://a.test/x?y=1&z=2!", [["http://a.test/x?y=1&z=2", "http://a.test/x?y=1&z=2"]]],
    ["javascript:alert(1)", []],
    ["data:text/html,<script>alert(1)</script>", []],
    ["https:// nothing", []],
    ["No links here at all.", []],
    ["", []],
  ];

  gate(
    "A  links",
    cases.flatMap(([text, expected]): [string, boolean][] => [
      [`links in ${JSON.stringify(text)} → ${JSON.stringify(links(linkifyText(text)))}`, same(links(linkifyText(text)), expected)],
      [`reassembles ${JSON.stringify(text)}`, reassembles(text)],
    ]),
  );
}

// ── B  quoted history ───────────────────────────────────────────────────────────────────────
{
  const gmail = parsePlainTextEmail(
    "Sounds good, let's talk Friday.\n\nOn Mon, Sep 8, 2026 at 10:00 AM Vite SEO <business@viteseo.ph> wrote:\n> Hi Maria,\n> Are you free?",
  );
  const wrapped = parsePlainTextEmail(
    "Yes.\n\nOn Mon, Sep 8, 2026 at 10:00 AM Vite SEO <\nbusiness@viteseo.ph> wrote:\n\n> Hi",
  );
  const outlook = parsePlainTextEmail(
    "Thanks!\n\n________________________________\nFrom: Vite SEO <business@viteseo.ph>\nSent: Monday, September 8, 2026 10:00 AM\nTo: Maria\nSubject: Proposal\n\nHi Maria",
  );
  const original = parsePlainTextEmail("Noted.\n-----Original Message-----\nFrom: x\nhello");
  const trailing = parsePlainTextEmail("Great\n> earlier line\n> more");
  const allQuoted = parsePlainTextEmail("> only quoted\n> text");
  const interleaved = parsePlainTextEmail("> Can you do Friday?\nYes, Friday works.\n> And the price?\nThat is fine.");
  const none = parsePlainTextEmail("Hello\n\nThanks");
  const crlf = parsePlainTextEmail("Hi\r\n\r\nOn Tue, Sep 9, 2026 Maria wrote:\r\n> x");
  const empty = parsePlainTextEmail("");
  const prose = parsePlainTextEmail("On Monday we can start.\nSee you then.");

  gate("B  quoted history (plain text)", [
    ["gmail: main is the reply", same(gmail.main, [{ kind: "text", text: "Sounds good, let's talk Friday." }])],
    ["gmail: history is attribution + quote", same(gmail.quoted?.map((b) => b.kind), ["text", "quote"])],
    ["gmail: quote markers removed", gmail.quoted?.[1]?.text === "Hi Maria,\nAre you free?"],
    ["wrapped attribution collapses", wrapped.quoted !== null && same(wrapped.main, [{ kind: "text", text: "Yes." }])],
    ["outlook header collapses from the separator", outlook.quoted?.[0]?.text.startsWith("____") === true],
    ["outlook: main is the reply", same(outlook.main, [{ kind: "text", text: "Thanks!" }])],
    ["original message collapses", same(original.main, [{ kind: "text", text: "Noted." }]) && original.quoted !== null],
    ["trailing > run collapses", same(trailing.main, [{ kind: "text", text: "Great" }]) && trailing.quoted?.[0]?.kind === "quote"],
    ["all-quoted message is NOT collapsed", allQuoted.quoted === null && allQuoted.main[0]?.kind === "quote"],
    ["interleaved quotes are NOT collapsed", interleaved.quoted === null],
    ["interleaved blocks keep their order", same(interleaved.main.map((b) => b.kind), ["quote", "text", "quote", "text"])],
    ["no history → nothing collapsed", none.quoted === null && same(none.main, [{ kind: "text", text: "Hello\n\nThanks" }])],
    ["CRLF history collapses", same(crlf.main, [{ kind: "text", text: "Hi" }]) && crlf.quoted !== null],
    ["empty body", same(empty, { main: [], signature: null, quoted: null })],
    ["prose starting with 'On' is NOT history", prose.quoted === null],
  ]);
}

// ── C  signatures ───────────────────────────────────────────────────────────────────────────
{
  const signed = parsePlainTextEmail("Thanks,\nMaria\n-- \nMaria Santos\nOwner, Santos Bakery");
  const onlySignature = parsePlainTextEmail("-- \nonly sig");
  const emptySignature = parsePlainTextEmail("Hi\n--");
  const signedReply = parsePlainTextEmail("Ok\n-- \nMaria\n\nOn Mon, Sep 8, 2026 Vite SEO wrote:\n> y");

  gate("C  signatures", [
    ["signature split off", signed.signature === "Maria Santos\nOwner, Santos Bakery"],
    ["main keeps the message", same(signed.main, [{ kind: "text", text: "Thanks,\nMaria" }])],
    ["a message that is only a signature stays content", onlySignature.signature === null && onlySignature.main.length === 1],
    ["an empty signature is not split", emptySignature.signature === null && emptySignature.main[0]?.text === "Hi\n--"],
    ["signature above quoted history", signedReply.signature === "Maria" && signedReply.quoted !== null],
  ]);
}

// ── D  frame document ───────────────────────────────────────────────────────────────────────
{
  const blocked = emailFrameCsp(false);
  const shown = emailFrameCsp(true);
  const content = '<p id="sender-content">Hello</p>';
  const doc = buildEmailSrcDoc(content, { showRemoteImages: false });
  const docShown = buildEmailSrcDoc(content, { showRemoteImages: true });
  const withMeta = buildEmailSrcDoc('<meta http-equiv="refresh" content="0;url=https://evil.test"><p>x</p>', {
    showRemoteImages: false,
  });
  const directives = (csp: string) => new Map(csp.split("; ").map((d) => [d.split(" ")[0], d] as const));

  gate("D  frame document & policy", [
    ["blocked: img-src is data: only", directives(blocked).get("img-src") === "img-src data:"],
    ["shown: img-src allows https and data only", directives(shown).get("img-src") === "img-src https: data:"],
    ["http images stay blocked after opt-in", !shown.includes("http:")],
    ["default-src 'none' in both", blocked.includes("default-src 'none'") && shown.includes("default-src 'none'")],
    ["no script-src ever granted", !/script-src/.test(blocked + shown)],
    ["'unsafe-inline' only for styles", [...(blocked + "; " + shown).matchAll(/'unsafe-inline'/g)].length === 2 && !/unsafe-eval/.test(blocked + shown)],
    ["forms and base URIs refused", blocked.includes("form-action 'none'") && blocked.includes("base-uri 'none'")],
    ["document carries the blocked policy", doc.includes(`content="${blocked}"`)],
    ["opt-in document carries the shown policy", docShown.includes(`content="${shown}"`)],
    ["base precedes the policy", doc.indexOf("<base") < doc.indexOf("Content-Security-Policy")],
    ["policy precedes the sender's content", doc.indexOf("Content-Security-Policy") < doc.indexOf("sender-content")],
    ["sender's content is inside <body>", doc.indexOf("</head><body>") < doc.indexOf("sender-content")],
    ["relative URLs cannot resolve to the CRM origin", doc.includes('<base href="about:blank" target="_blank">')],
    ["sender's meta refresh removed", !withMeta.includes("evil.test")],
    ["collapse rule present", doc.includes(`html.${QUOTES_COLLAPSED_CLASS} :is(`)],
  ]);
}

// ── E  remote content detection ─────────────────────────────────────────────────────────────
{
  const cases: [string, boolean][] = [
    ['<img src="https://t.example/p.gif" width="1" height="1">', true],
    ["<img src=//cdn.example/x.png>", true],
    ['<img alt="logo" srcset="https://a.example/x.png 2x">', true],
    ['<td background="https://a.example/bg.png">', true],
    ["<div style=\"background:url('https://a.example/b.png')\">", true],
    ["<style>@import url(x.css);</style>", true],
    ['<img src="data:image/png;base64,AAAA">', false],
    ['<img src="cid:logo@viteseo">', false],
    ["<p>Hello, no images.</p>", false],
  ];
  const own = "https://api.viteseo.ph/api/v1/crm/email-assets/";
  const logo = `<img src="${own}0b6c9a3e-1111-4c4c-9d9d-123456789abc.png" width="160">`;
  gate("E  remote content detection", [
    ...cases.map(([html, expected]): [string, boolean] => [`${html} → ${!expected}`, hasRemoteContent(html) === expected]),
    ["own logo without trust → offers Show images", hasRemoteContent(logo) === true],
    ["own logo with trust → nothing held back", hasRemoteContent(logo, [own]) === false],
    ["own logo + a tracker → still offers Show images", hasRemoteContent(logo + '<img src="https://t.example/p.gif">', [own]) === true],
    ["another path on the same host is not trusted", hasRemoteContent('<img src="https://api.viteseo.ph/api/v1/crm/leads/1.png">', [own]) === true],
    ["an invalid trusted source trusts nothing", hasRemoteContent(logo, ["https:"]) === true],
  ]);
}

// ── F  link schemes ─────────────────────────────────────────────────────────────────────────
{
  const cases: [string, boolean][] = [
    ["https://viteseo.ph", true],
    ["http://viteseo.ph", true],
    ["mailto:business@viteseo.ph", true],
    ["tel:+639171234567", true],
    ["javascript:alert(1)", false],
    ["  javascript:alert(1)", false],
    ["java\tscript:alert(1)", false],
    ["JAVASCRIPT:alert(1)", false],
    ["data:text/html,<script>alert(1)</script>", false],
    ["vbscript:msgbox(1)", false],
    ["/api/v1/leads", false],
    ["#top", false],
    ["", false],
  ];
  gate(
    "F  link schemes",
    cases.map(([href, expected]) => [`${JSON.stringify(href)} → ${!expected}`, isSafeEmailHref(href) === expected]),
  );
}

// ── G  static ───────────────────────────────────────────────────────────────────────────────
{
  const src = new URL("../../src/", import.meta.url);
  const dir = new URL("modules/crm/components/shared/email-body/", src);
  const rendererFiles = readdirSync(dir).map((name) => [name, readFileSync(new URL(name, dir), "utf8")] as const);
  const thread = readFileSync(new URL("modules/crm/features/leads/components/email-thread.tsx", src), "utf8");
  const frame = rendererFiles.find(([name]) => name === "email-html-frame.tsx")?.[1] ?? "";
  const tokens = EMAIL_FRAME_SANDBOX.split(" ");
  // The prop as JSX (`=`) or as a props object (`:`) — a comment that names it is not a use of it.
  const usesInnerHtml = (text: string) => /dangerouslySetInnerHTML\s*[=:]/.test(text);

  gate("G  static", [
    ...rendererFiles.map(([name, text]): [string, boolean] => [`no dangerouslySetInnerHTML in ${name}`, !usesInnerHtml(text)]),
    ["no dangerouslySetInnerHTML in email-thread.tsx", !usesInnerHtml(thread)],
    ["thread renders through EmailBody", thread.includes("<EmailBody")],
    ["frame uses the shared sandbox", frame.includes("sandbox={EMAIL_FRAME_SANDBOX}")],
    ["frame content is srcDoc", frame.includes("srcDoc={srcDoc}")],
    ["sandbox has no allow-scripts", !tokens.includes("allow-scripts")],
    ["sandbox has no allow-forms", !tokens.includes("allow-forms")],
    ["sandbox has no top navigation", !tokens.some((t) => t.startsWith("allow-top-navigation"))],
    ["sandbox has no allow-modals", !tokens.includes("allow-modals")],
    ["thread trusts its own assets for outbound messages only", thread.includes("trustedImageSources={inbound ? undefined : ownAssetSources()}")],
  ]);
}

// ── H  trusted image source ─────────────────────────────────────────────────────────────────────
{
  const own = emailAssetImageSource("https://api.viteseo.ph/api/v1/crm");
  const ownSlash = emailAssetImageSource("https://api.viteseo.ph/api/v1/crm/");
  const local = emailAssetImageSource("http://192.168.68.73:8001/api/v1/crm");
  const imgSrc = (csp: string) => csp.split("; ").find((d) => d.startsWith("img-src ")) ?? "";
  const trustedBlocked = emailFrameCsp(false, own === null ? [] : [own]);
  const trustedShown = emailFrameCsp(true, own === null ? [] : [own]);
  const hostile = [
    "https:",
    "*",
    "https://api.viteseo.ph",
    "https://api.viteseo.ph/",
    "https://api.viteseo.ph/x/; script-src *",
    "https://api.viteseo.ph/x/ https:",
    'https://api.viteseo.ph/x/"><script>alert(1)</script>',
    "https://api.viteseo.ph/x/?a=1",
    "https://user:pw@api.viteseo.ph/x/",
    "javascript:alert(1)//x/",
    "data:image/png/",
    "https://api.viteseo.ph/x",
  ];
  const doc = buildEmailSrcDoc("<p>x</p>", { showRemoteImages: false, trustedImageSources: own === null ? [] : [own] });

  gate("H  trusted image source", [
    ["own asset path from the CRM base", own === "https://api.viteseo.ph/api/v1/crm/email-assets/"],
    ["trailing slash on the base is the same path", ownSlash === own],
    ["local http base keeps its own scheme and port", local === "http://192.168.68.73:8001/api/v1/crm/email-assets/"],
    ["unusable base → null", emailAssetImageSource("") === null && emailAssetImageSource("not a url") === null],
    ["non-http base → null", emailAssetImageSource("javascript:alert(1)") === null],
    ["blocked + trusted: img-src is data: and the path only", imgSrc(trustedBlocked) === `img-src data: ${own}`],
    ["shown + trusted: https, data and the path", imgSrc(trustedShown) === `img-src https: data: ${own}`],
    ["no trust → policy unchanged", emailFrameCsp(false, []) === emailFrameCsp(false) && emailFrameCsp(true, []) === emailFrameCsp(true)],
    ["trusted policy still grants no script", !/script-src/.test(trustedBlocked + trustedShown)],
    ["trusted document carries the trusted policy", doc.includes(`content="${trustedBlocked}"`)],
    ...hostile.map((source): [string, boolean] => [
      `hostile source dropped: ${JSON.stringify(source)}`,
      emailFrameCsp(false, [source]) === emailFrameCsp(false),
    ]),
  ]);
}

console.log(failed === 0 ? "\nALL GATES PASS" : `\n${failed} GATE(S) FAILED`);
process.exit(failed === 0 ? 0 : 1);
