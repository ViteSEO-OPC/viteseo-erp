/**
 * Pure helpers for the visual email designers: the v1 vocabulary, style inheritance, block defaults, list
 * operations, converting a plain-text body into template content, a link check, and placing the server's
 * validation errors on the block or field they belong to. Free of React and the DOM so
 * scripts/email-template-gates can drive them.
 *
 * Nothing here produces HTML. A design layout and a template's content are serialized as JSON and the server
 * composes and renders them — for the previews and, later, for sending — so there is exactly one renderer,
 * and it is not in the browser.
 *
 * The constants mirror EmailDesignSchema (PHP). The gates read that file and fail on any drift; the server
 * re-validates every preview and save regardless.
 */
import type {
  DesignAlignment,
  DesignBlock,
  DesignBlockType,
  DesignFont,
  DesignProblems,
  DesignRegion,
  DesignStyleKey,
  DesignStyles,
  DesignStyleValues,
  EmailDesignLayout,
  LogoBlock,
  LogoLinkTarget,
  RichTextDoc,
  RichTextNode,
  SignatureArea,
  TemplateContent,
} from "@/modules/crm/features/email-templates/types/email-design-types";

export const DESIGN_VERSION = 1;

/** Blocks a template's content may hold. */
export const MAX_CONTENT_BLOCKS = 50;

/** Blocks a design's header, and separately its footer, may hold. */
export const MAX_REGION_BLOCKS = 10;

export const FONT_OPTIONS: readonly { value: DesignFont; label: string }[] = [
  { value: "arial", label: "Arial" },
  { value: "helvetica", label: "Helvetica" },
  { value: "verdana", label: "Verdana" },
  { value: "tahoma", label: "Tahoma" },
  { value: "trebuchet", label: "Trebuchet MS" },
  { value: "georgia", label: "Georgia" },
  { value: "times", label: "Times New Roman" },
];

export const ALIGNMENTS: readonly DesignAlignment[] = ["left", "center", "right"];

/** Inclusive [min, max] whole numbers — EmailDesignSchema's ranges. */
export const RANGES = {
  headingLevel: [1, 3],
  textFontSize: [12, 22],
  footerFontSize: [10, 14],
  imageWidth: [16, 552],
  logoWidth: [40, 300],
  spacerHeight: [4, 96],
  dividerThickness: [1, 4],
  buttonRadius: [0, 24],
} as const satisfies Record<string, readonly [number, number]>;

/** Character limits — EmailDesignSchema's MAX_* text constants. */
export const TEXT_LIMITS = {
  heading: 200,
  buttonLabel: 60,
  alt: 150,
  signOff: 100,
  preheader: 150,
  blockText: 5000,
  url: 2000,
  designName: 100,
} as const;

/**
 * EmailDesignSchema::SYSTEM_DEFAULTS — the end of the inheritance chain. Organization branding will sit
 * between a design's styles and these; until it exists, an unset style shows and renders as this value.
 */
export const STYLE_DEFAULTS: DesignStyleValues = {
  font: "arial",
  background_color: "#f4f4f5",
  content_background_color: "#ffffff",
  text_color: "#18181b",
  muted_text_color: "#71717a",
  link_color: "#0d9488",
  accent_color: "#0d9488",
  accent_text_color: "#ffffff",
  divider_color: "#e4e4e7",
};

/** Every inheritable style, in EmailDesignSchema::SYSTEM_DEFAULTS order. */
export const STYLE_FIELDS = Object.keys(STYLE_DEFAULTS) as DesignStyleKey[];

export const COLOR_STYLE_FIELDS = STYLE_FIELDS.filter((key): key is Exclude<DesignStyleKey, "font"> => key !== "font");

export const STYLE_LABELS: Record<DesignStyleKey, string> = {
  font: "Font",
  background_color: "Page background",
  content_background_color: "Email background",
  text_color: "Text",
  muted_text_color: "Footer text",
  link_color: "Links",
  accent_color: "Buttons",
  accent_text_color: "Button labels",
  divider_color: "Dividers",
};

/** EmailDesignSchema::BLOCK_COLOR_FALLBACKS — block type → color field → the style it inherits. */
export const BLOCK_COLOR_FALLBACKS = {
  heading: { color: "text_color" },
  text: { color: "text_color" },
  footer: { color: "muted_text_color" },
  button: { background_color: "accent_color", text_color: "accent_text_color" },
  divider: { color: "divider_color" },
} as const satisfies Record<string, Record<string, DesignStyleKey>>;

export type ColoredBlockType = keyof typeof BLOCK_COLOR_FALLBACKS;

export const LOGO_LINK_TARGETS: readonly LogoLinkTarget[] = ["website", "custom", "none"];

/** A design layout's fields — EmailDesignSchema::LAYOUT_FIELDS. */
export const LAYOUT_FIELDS: readonly (keyof EmailDesignLayout)[] = ["version", "styles", "header", "signature", "footer"];

/** The signature area's fields — EmailDesignSchema::SIGNATURE_FIELDS. */
export const SIGNATURE_FIELDS: readonly (keyof SignatureArea)[] = ["enabled", "sign_off", "align"];

/** A template content's fields — EmailDesignSchema::CONTENT_FIELDS. */
export const CONTENT_FIELDS: { required: readonly (keyof TemplateContent)[]; optional: readonly (keyof TemplateContent)[] } = {
  required: ["version", "blocks"],
  optional: ["preheader", "sign_off", "style_overrides"],
};

/** Each part of an email → the blocks it may hold — EmailDesignSchema::REGION_BLOCKS. */
export const REGION_BLOCKS: Record<DesignRegion, readonly DesignBlockType[]> = {
  header: ["logo", "heading", "text", "button", "image", "divider", "spacer"],
  footer: ["footer", "text", "logo", "button", "image", "divider", "spacer"],
  blocks: ["heading", "text", "button", "image", "divider", "spacer"],
};

export const REGION_LABELS: Record<DesignRegion, string> = { header: "Header", footer: "Footer", blocks: "Content" };

/** Every field each block holds — EmailDesignSchema::BLOCK_FIELDS, in the same order. */
export const BLOCK_FIELDS: Record<DesignBlockType, { required: readonly string[]; optional: readonly string[] }> = {
  logo: { required: ["type", "id", "width", "align"], optional: ["link_target", "link_url"] },
  heading: { required: ["type", "id", "level", "text", "align"], optional: ["color"] },
  text: { required: ["type", "id", "content", "align", "font_size"], optional: ["color"] },
  button: { required: ["type", "id", "label", "url", "align", "radius"], optional: ["background_color", "text_color"] },
  image: { required: ["type", "id", "asset_id", "alt", "width", "align"], optional: ["link_url"] },
  divider: { required: ["type", "id", "thickness"], optional: ["color"] },
  spacer: { required: ["type", "id", "height"], optional: [] },
  footer: { required: ["type", "id", "content", "align", "font_size"], optional: ["color"] },
};

export const BLOCK_LABELS: Record<DesignBlockType, string> = {
  logo: "Logo",
  heading: "Heading",
  text: "Text",
  button: "Button",
  image: "Image",
  divider: "Divider",
  spacer: "Spacer",
  footer: "Footer text",
};

/**
 * What "Add block" offers in each part. Image waits for image uploads: an image block needs a stored asset id,
 * and there is nowhere to choose one yet. An image already in a document still loads, edits and saves.
 */
export const ADDABLE_BLOCKS: Record<DesignRegion, readonly DesignBlockType[]> = {
  header: ["logo", "heading", "text", "button", "divider", "spacer"],
  footer: ["footer", "text", "logo", "button", "divider", "spacer"],
  blocks: ["heading", "text", "button", "divider", "spacer"],
};

/** The fields each block's settings panel has a control for; any other problem shows at the top of it. */
export const BLOCK_CONTROL_FIELDS: Record<DesignBlockType, readonly string[]> = {
  logo: ["width", "align", "link_target", "link_url"],
  heading: ["text", "level", "align", "color"],
  text: ["content", "align", "font_size", "color"],
  button: ["label", "url", "align", "background_color", "text_color", "radius"],
  image: ["alt", "width", "align", "link_url"],
  divider: ["color", "thickness"],
  spacer: ["height"],
  footer: ["content", "align", "font_size", "color"],
};

// ── Style inheritance ─────────────────────────────────────────────────────────────────────────

/**
 * The value a style shows and renders with, judged from the browser: the first of `chain` that sets it —
 * for a template, its overrides then its design's styles — or the system default. Organization branding,
 * which sits between the design and the default on the server, is not known here yet; the server preview is
 * always the authority on the final look.
 */
export function effectiveStyle(chain: readonly DesignStyles[], key: DesignStyleKey): string {
  for (const styles of chain) {
    const value = styles[key];
    if (value !== undefined) return value;
  }
  return STYLE_DEFAULTS[key];
}

/** What an unset block color inherits: the style BLOCK_COLOR_FALLBACKS names for it, through `chain`. */
export function inheritedBlockColor(type: ColoredBlockType, field: string, chain: readonly DesignStyles[]): string {
  const fallbacks: Record<string, DesignStyleKey> = BLOCK_COLOR_FALLBACKS[type];
  return effectiveStyle(chain, fallbacks[field] ?? "text_color");
}

/** `styles` with `key` set, or removed — inherited again — when `value` is undefined or blank. */
export function withStyle(styles: DesignStyles, key: DesignStyleKey, value: string | undefined): DesignStyles {
  const next: DesignStyles = { ...styles };
  if (value === undefined || value === "") {
    delete next[key];
  } else {
    (next as Record<string, string>)[key] = value;
  }
  return next;
}

/** Template content with one style override set or cleared; no overrides at all removes `style_overrides`. */
export function withStyleOverride(content: TemplateContent, key: DesignStyleKey, value: string | undefined): TemplateContent {
  const overrides = withStyle(content.style_overrides ?? {}, key, value);
  const next: TemplateContent = { ...content };
  if (Object.keys(overrides).length === 0) {
    delete next.style_overrides;
  } else {
    next.style_overrides = overrides;
  }
  return next;
}

/** Template content with its preview text set, or removed when blank. */
export function withPreheader(content: TemplateContent, value: string): TemplateContent {
  const next: TemplateContent = { ...content };
  if (value === "") {
    delete next.preheader;
  } else {
    next.preheader = value;
  }
  return next;
}

/** Template content with its own sign-off, or the design's again when `value` is undefined. "" shows no sign-off line. */
export function withSignOff(content: TemplateContent, value: string | undefined): TemplateContent {
  const next: TemplateContent = { ...content };
  if (value === undefined) {
    delete next.sign_off;
  } else {
    next.sign_off = value;
  }
  return next;
}

/** Where a logo links. No target means `custom` when it carries an address, the website otherwise. */
export function logoLinkTarget(block: LogoBlock): LogoLinkTarget {
  return block.link_target ?? (block.link_url != null ? "custom" : "website");
}

// ── Blocks ────────────────────────────────────────────────────────────────────────────────────

export function emptyRichText(): RichTextDoc {
  return { type: "doc", content: [{ type: "paragraph" }] };
}

/**
 * A block id the server accepts (1–64 letters, digits, dashes or underscores) that no block in `taken`
 * already uses. Not `crypto.randomUUID()`: that is missing outside secure contexts, such as the LAN URL
 * the CRM is tested on.
 */
export function newBlockId(type: DesignBlockType, taken: ReadonlySet<string>, random: () => number = Math.random): string {
  for (let attempt = 0; ; attempt++) {
    const suffix = Math.floor(random() * 36 ** 8)
      .toString(36)
      .padStart(8, "0");
    const id = attempt < 16 ? `${type}-${suffix}` : `${type}-${suffix}-${attempt}`;
    if (!taken.has(id)) return id;
  }
}

/** Every block id in the given lists. */
export function blockIds(...lists: readonly (readonly DesignBlock[])[]): Set<string> {
  return new Set(lists.flatMap((list) => list.map((block) => block.id)));
}

/**
 * A new block of `type` with valid defaults. It sets no colors — every color inherits until someone chooses
 * one — and a logo links to the organization website by default.
 */
export function createBlock(type: DesignBlockType, id: string): DesignBlock {
  switch (type) {
    case "logo":
      return { type, id, width: 160, align: "center" };
    case "heading":
      return { type, id, level: 2, text: "Heading", align: "left" };
    case "text":
      return { type, id, content: emptyRichText(), align: "left", font_size: 14 };
    case "button":
      return { type, id, label: "Book a call", url: "", align: "center", radius: 6 };
    case "image":
      return { type, id, asset_id: "", alt: "", width: 552, align: "center" };
    case "divider":
      return { type, id, thickness: 1 };
    case "spacer":
      return { type, id, height: 24 };
    case "footer":
      return { type, id, content: emptyRichText(), align: "center", font_size: 12 };
  }
}

/** A new design's starting point — the same as the server's default design: the logo, a signature, no footer. */
export function defaultLayout(): EmailDesignLayout {
  return {
    version: DESIGN_VERSION,
    styles: {},
    header: [{ type: "logo", id: "header-logo", width: 160, align: "center" }],
    signature: { enabled: true, sign_off: "Best regards,", align: "left" },
    footer: [],
  };
}

/** A new designed template's starting point: a greeting and a paragraph to write in. Inherits every style. */
export function defaultContent(): TemplateContent {
  return {
    version: DESIGN_VERSION,
    blocks: [
      { type: "heading", id: "heading", level: 2, text: "Hi {{first_name}},", align: "left" },
      {
        type: "text",
        id: "text",
        content: { type: "doc", content: [{ type: "paragraph", content: [{ type: "text", text: "Write your message here." }] }] },
        align: "left",
        font_size: 14,
      },
    ],
  };
}

// ── List operations ───────────────────────────────────────────────────────────────────────────

/** `blocks` with the block at `from` moved to `to`; the same array when either index is out of range. */
export function moveBlock(blocks: DesignBlock[], from: number, to: number): DesignBlock[] {
  if (from === to || from < 0 || to < 0 || from >= blocks.length || to >= blocks.length) return blocks;
  const next = [...blocks];
  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  return next;
}

/** A deep copy of block `id`, with a fresh id unused in `blocks` and in `taken`, placed right after it. */
export function duplicateBlock(
  blocks: DesignBlock[],
  id: string,
  options: { taken?: ReadonlySet<string>; random?: () => number } = {},
): { blocks: DesignBlock[]; id: string | null } {
  const index = blocks.findIndex((block) => block.id === id);
  if (index === -1) return { blocks, id: null };

  const copy = structuredClone(blocks[index]);
  copy.id = newBlockId(copy.type, new Set([...(options.taken ?? []), ...blocks.map((block) => block.id)]), options.random);

  return { blocks: [...blocks.slice(0, index + 1), copy, ...blocks.slice(index + 1)], id: copy.id };
}

export function removeBlock(blocks: DesignBlock[], id: string): DesignBlock[] {
  return blocks.filter((block) => block.id !== id);
}

export function replaceBlock(blocks: DesignBlock[], next: DesignBlock): DesignBlock[] {
  return blocks.map((block) => (block.id === next.id ? next : block));
}

/**
 * What is sent as a request's `layout` or `content` field. The server validates and renders it; nothing else
 * is. `JSON.stringify` drops undefined values, so an inherited style is simply absent from what is sent.
 */
export function serializeDocument(document: EmailDesignLayout | TemplateContent): string {
  return JSON.stringify(document);
}

/** A document's block lists by part, for placing the server's errors. */
export function documentRegions(document: EmailDesignLayout | TemplateContent): Partial<Record<DesignRegion, readonly DesignBlock[]>> {
  return "blocks" in document ? { blocks: document.blocks } : { header: document.header, footer: document.footer };
}

// ── Converting a plain-text template ──────────────────────────────────────────────────────────

/** Characters the server refuses in design text: controls (newline aside), line separators, bidi overrides. */
function isRefusedCharacter(codePoint: number): boolean {
  return (
    (codePoint < 0x20 && codePoint !== 0x0a) ||
    (codePoint >= 0x7f && codePoint <= 0x9f) ||
    codePoint === 0x2028 ||
    codePoint === 0x2029 ||
    (codePoint >= 0x202a && codePoint <= 0x202e) ||
    (codePoint >= 0x2066 && codePoint <= 0x2069)
  );
}

/** Text blocks are held to 5,000 characters; paragraphs are packed into blocks with room to spare. */
const CONVERTED_BLOCK_TARGET = 4500;

function paragraphNode(paragraph: string): RichTextNode {
  const content = paragraph
    .split("\n")
    .flatMap((line, index): RichTextNode[] => [
      ...(index > 0 ? [{ type: "hardBreak" }] : []),
      ...(line !== "" ? [{ type: "text", text: line }] : []),
    ]);

  return content.length > 0 ? { type: "paragraph", content } : { type: "paragraph" };
}

/** The {{sender_name}} line a plain-text signature ends with. */
const SENDER_NAME_LINE = /^\{\{\s*sender_name\s*\}\}$/;

/**
 * The signature a plain-text body ends with, if it has one this can recognise: a final paragraph that is a single
 * short sign-off line above a `{{sender_name}}` line (the shape every seeded template ends with, e.g.
 * "Cheers,\n{{sender_name}}"), or a lone `{{sender_name}}` line. Returns the sign-off ("" for a lone name) and the
 * body without that paragraph — or null when the last paragraph is anything else, or is all there is.
 */
export function trailingSignOff(body: string): { signOff: string; body: string } | null {
  const paragraphs = body.replace(/\r\n?/g, "\n").replace(/\s+$/, "").split(/\n{2,}/);
  if (paragraphs.length < 2) return null;

  const lines = paragraphs[paragraphs.length - 1]
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line !== "");
  const rest = paragraphs.slice(0, -1).join("\n\n");
  if (rest.trim() === "" || lines.length === 0 || lines.length > 2 || !SENDER_NAME_LINE.test(lines[lines.length - 1])) {
    return null;
  }

  const signOff = lines.length === 2 ? lines[0] : "";
  if (signOff.length > TEXT_LIMITS.signOff || signOff.includes("{{") || signOff.includes("}}")) return null;

  return { signOff, body: rest };
}

/** Text with tabs as spaces and the characters the server refuses in design text removed. */
function cleanText(text: string): string {
  return Array.from(text.replace(/\r\n?/g, "\n").replace(/\t/g, " "))
    .filter((character) => !isRefusedCharacter(character.codePointAt(0) ?? 0))
    .join("");
}

/**
 * A plain-text body as template content: blank lines separate paragraphs, single line breaks stay line
 * breaks, and `{{placeholders}}` are kept exactly as written. Tabs become spaces and characters the server
 * would refuse are dropped. Long bodies are split across text blocks so none exceeds its limit. Every style
 * is inherited from the design.
 *
 * `signatureArea`: whether the design the content will be shown in has a signature area. A design's signature
 * area already prints a sign-off and the sender's name after the content, so a body that ends with its own
 * signature would show it twice. When there is a signature area and the body ends with a signature
 * trailingSignOff() recognises, that paragraph becomes the template's own sign-off instead — the author's
 * words, printed once, by the signature area. Anything it does not recognise stays in the body untouched.
 *
 * Only ever called when an admin chooses "Switch to visual designer": a plain-text template is never
 * converted on its own, and nothing is saved until the designer saves.
 */
export function contentFromPlainText(body: string, options: { signatureArea?: boolean } = {}): TemplateContent {
  const signature = options.signatureArea ? trailingSignOff(body) : null;
  const clean = cleanText(signature?.body ?? body);

  const paragraphs = clean
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.replace(/^\n+|\n+$/g, ""))
    .filter((paragraph) => paragraph.trim() !== "");

  const groups: RichTextNode[][] = [];
  let current: RichTextNode[] = [];
  let size = 0;
  for (const paragraph of paragraphs) {
    if (current.length > 0 && size + paragraph.length > CONVERTED_BLOCK_TARGET) {
      groups.push(current);
      current = [];
      size = 0;
    }
    current.push(paragraphNode(paragraph));
    size += paragraph.length;
  }
  if (current.length > 0) groups.push(current);
  if (groups.length === 0) groups.push([{ type: "paragraph" }]);

  return {
    version: DESIGN_VERSION,
    blocks: groups.map((content, index) => ({
      type: "text",
      id: index === 0 ? "text" : `text-${index + 1}`,
      content: { type: "doc", content },
      align: "left",
      font_size: 14,
    })),
    ...(signature ? { sign_off: cleanText(signature.signOff) } : {}),
  };
}

// ── Display ───────────────────────────────────────────────────────────────────────────────────

/** The words in a rich-text document, collapsed to one line — for a block's collapsed summary. */
export function richTextPlain(node: RichTextDoc | RichTextNode): string {
  if (node.type === "text") return "text" in node ? (node.text ?? "") : "";
  return (node.content ?? []).map(richTextPlain).join(" ").replace(/\s+/g, " ").trim();
}

/** A short description of a block for its collapsed header. */
export function blockSummary(block: DesignBlock): string {
  switch (block.type) {
    case "heading":
      return block.text;
    case "text":
    case "footer":
      return richTextPlain(block.content);
    case "button":
      return block.label;
    case "image":
      return block.alt;
    case "logo":
      return "Organization logo";
    case "divider":
      return `${block.thickness}px line`;
    case "spacer":
      return `${block.height}px of space`;
  }
}

/** "4.3 KB" — the preview's HTML size against the Gmail guard. */
export function formatKilobytes(bytes: number): string {
  return `${(bytes / 1000).toFixed(1)} KB`;
}

// ── Links ─────────────────────────────────────────────────────────────────────────────────────

/**
 * The link rule EmailDesignValidator applies: a full https address with a dotted host and no
 * credentials, a mailto address, or a tel number — no placeholders, spaces, quotes or angle brackets.
 * A quick check for the link fields; the server's answer on preview and save is final.
 */
export function isAllowedLinkUrl(url: string): boolean {
  if (url === "" || url.length > TEXT_LIMITS.url) return false;
  if (/\{\{|\}\}|%7b%7b|%7d%7d/i.test(url)) return false;
  if (/[^\x21-\x7E]|["'<>\\`]/.test(url)) return false;

  if (url.startsWith("https://")) {
    try {
      const parsed = new URL(url);
      return (
        parsed.protocol === "https:" &&
        /^[a-z0-9-]+(\.[a-z0-9-]+)+$/i.test(parsed.hostname) &&
        parsed.username === "" &&
        parsed.password === ""
      );
    } catch {
      return false;
    }
  }
  if (url.startsWith("mailto:")) {
    return /^mailto:[^\s@?]+@[^\s@?]+\.[^\s@?]+(\?[A-Za-z0-9%._~=&+-]*)?$/.test(url);
  }
  if (url.startsWith("tel:")) {
    return /^tel:\+?[0-9][0-9().-]{2,31}$/.test(url);
  }
  return false;
}

/** An optional link field's value: null when blank, so the server sees "no link" rather than "". */
export function optionalLink(value: string): string | null {
  return value.trim() === "" ? null : value;
}

// ── Server errors ─────────────────────────────────────────────────────────────────────────────

/** Document-level fields a problem can be placed on, rather than listed as a problem with the whole document. */
const FIELD_ROOTS = new Set(["styles", "style_overrides", "signature", "preheader", "sign_off"]);

/**
 * The server's validation errors for one document, placed where they belong. Keys look like `layout`,
 * `layout.header.0.link_url`, `layout.styles.font`, `content.blocks.3.content.content.0…` or
 * `content.preheader`. A block index is resolved against the document that was SENT, then kept by block id so
 * the message follows its block if the list is reordered. Errors for other fields (subject, email_design_id)
 * are left to the form.
 */
export function documentProblems(
  errors: Record<string, string>,
  prefix: "layout" | "content",
  regions: Partial<Record<DesignRegion, readonly DesignBlock[]>>,
): DesignProblems {
  const problems: DesignProblems = { general: [], fields: {}, blocks: {} };

  for (const [key, message] of Object.entries(errors)) {
    const parts = key.split(".");
    if (parts[0] !== prefix) continue;

    const root = parts[1] ?? "";
    const list = regions[root as DesignRegion];

    if (list && /^\d+$/.test(parts[2] ?? "")) {
      const block = list[Number(parts[2])];
      if (block) {
        (problems.blocks[block.id] ??= []).push({ field: parts[3] ?? "", message });
        continue;
      }
    } else if (FIELD_ROOTS.has(root)) {
      problems.fields[parts.slice(1, 3).join(".")] = message;
      continue;
    }

    if (!problems.general.includes(message)) problems.general.push(message);
  }

  return problems;
}

/** Two sets of problems as one — a save's refusals together with the live preview's. */
export function mergeProblems(first: DesignProblems, second: DesignProblems): DesignProblems {
  return {
    general: [...new Set([...first.general, ...second.general])],
    fields: { ...first.fields, ...second.fields },
    blocks: { ...first.blocks, ...second.blocks },
  };
}

export function problemCount(problems: DesignProblems): number {
  return (
    problems.general.length +
    Object.keys(problems.fields).length +
    Object.values(problems.blocks).reduce((total, list) => total + list.length, 0)
  );
}

export const NO_PROBLEMS: DesignProblems = { general: [], fields: {}, blocks: {} };
