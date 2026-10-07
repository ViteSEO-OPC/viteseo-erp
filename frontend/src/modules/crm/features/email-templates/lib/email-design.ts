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
  ActionItem,
  AdditionalColorStyleKey,
  BooleanStyleKey,
  ButtonBlock,
  ButtonVariant,
  CardBlock,
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
  NumericStyleKey,
  RichTextDoc,
  RichTextNode,
  SignatureArea,
  SignatureOption,
  TemplateContent,
} from "@/modules/crm/features/email-templates/types/email-design-types";

export const DESIGN_VERSION = 1;

/** Blocks a template's content may hold. */
export const MAX_CONTENT_BLOCKS = 50;

/** Blocks a design's header, and separately its footer, may hold. */
export const MAX_REGION_BLOCKS = 10;

/** Blocks one card may hold. They count toward MAX_CONTENT_BLOCKS too. */
export const MAX_CARD_BLOCKS = 20;

/** Items one rows block may hold. */
export const MAX_ROWS_ITEMS = 30;

/** Calls to action one actions block may hold — EmailDesignSchema::MAX_ACTION_ITEMS. */
export const MAX_ACTION_ITEMS = 3;

/** EmailDesignSchema::ACTION_KINDS. */
export const ACTION_KINDS: readonly ActionItem["kind"][] = ["button", "link"];

/** The fields every action item has (ACTION_ITEM_REQUIRED); a button may also have these (ACTION_BUTTON_OPTIONAL). */
export const ACTION_ITEM_REQUIRED = ["kind", "label", "url"] as const;
export const ACTION_BUTTON_OPTIONAL = ["variant"] as const;

/** EmailDesignSchema::BOOLEAN_STYLES — optional on/off styles → their default. */
export const BOOLEAN_STYLES = { link_underline: true } as const satisfies Record<BooleanStyleKey, boolean>;

export const BOOLEAN_STYLE_LABELS: Record<BooleanStyleKey, string> = { link_underline: "Underline links" };

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
  headingFontSize: [14, 48],
} as const satisfies Record<string, readonly [number, number]>;

/** Heading level → its font size in px — EmailDesignSchema::HEADING_SIZES. */
export const HEADING_SIZES = { 1: 28, 2: 22, 3: 18 } as const;

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
  cardEyebrow: 100,
  cardTitle: 200,
  rowTitle: 200,
  rowDescription: 1000,
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

/**
 * EmailDesignSchema::ADDITIONAL_COLOR_STYLES — optional colors → the system style each takes when unset, in the
 * PHP order.
 */
export const ADDITIONAL_COLOR_STYLES = {
  card_background_color: "background_color",
  card_border_color: "divider_color",
  eyebrow_color: "muted_text_color",
  secondary_color: "text_color",
  secondary_text_color: "content_background_color",
} as const satisfies Record<AdditionalColorStyleKey, DesignStyleKey>;

export const ADDITIONAL_COLOR_FIELDS = Object.keys(ADDITIONAL_COLOR_STYLES) as AdditionalColorStyleKey[];

/** EmailDesignSchema::NUMERIC_STYLES — optional sizes in px → [default, [min, max]], in the PHP order. */
export const NUMERIC_STYLES = {
  container_radius: [0, [0, 32]],
  content_padding: [24, [8, 48]],
  card_radius: [12, [0, 32]],
  card_padding: [24, [8, 40]],
  card_border_width: [0, [0, 4]],
  card_eyebrow_size: [12, [10, 18]],
  card_title_size: [20, [14, 32]],
  row_title_size: [15, [12, 22]],
  row_description_size: [14, [11, 20]],
  row_padding: [10, [4, 24]],
  signature_size: [14, [12, 22]],
  signature_detail_size: [14, [11, 20]],
  button_padding_y: [12, [6, 24]],
  button_padding_x: [24, [12, 48]],
} as const satisfies Record<NumericStyleKey, readonly [number, readonly [number, number]]>;

export const NUMERIC_STYLE_FIELDS = Object.keys(NUMERIC_STYLES) as NumericStyleKey[];

export const ADDITIONAL_STYLE_LABELS: Record<AdditionalColorStyleKey | NumericStyleKey, string> = {
  card_background_color: "Card background",
  card_border_color: "Card border",
  eyebrow_color: "Card label",
  secondary_color: "Secondary buttons",
  secondary_text_color: "Secondary button labels",
  container_radius: "Email corner radius",
  content_padding: "Side padding",
  card_radius: "Card corner radius",
  card_padding: "Card padding",
  card_border_width: "Card border width",
  card_eyebrow_size: "Card label size",
  card_title_size: "Card title size",
  row_title_size: "Row title size",
  row_description_size: "Row description size",
  row_padding: "Space between rows",
  signature_size: "Sign-off and name size",
  signature_detail_size: "Signature details size",
  button_padding_y: "Button height padding",
  button_padding_x: "Button side padding",
};

/** EmailDesignSchema::BLOCK_COLOR_FALLBACKS — block type → color field → the style it inherits. */
export const BLOCK_COLOR_FALLBACKS = {
  heading: { color: "text_color" },
  text: { color: "text_color" },
  footer: { color: "muted_text_color" },
  button: { background_color: "accent_color", text_color: "accent_text_color" },
  divider: { color: "divider_color" },
  card: { background_color: "card_background_color", border_color: "card_border_color" },
  rows: { title_color: "text_color", description_color: "muted_text_color", divider_color: "divider_color" },
} as const satisfies Record<string, Record<string, DesignStyleKey | AdditionalColorStyleKey>>;

/** A secondary button's colors — what `variant: "secondary"` takes instead of the accent ones. */
export const SECONDARY_BUTTON_COLORS = {
  background_color: "secondary_color",
  text_color: "secondary_text_color",
} as const satisfies Record<string, AdditionalColorStyleKey>;

/** EmailDesignSchema::BUTTON_VARIANTS. */
export const BUTTON_VARIANTS: readonly ButtonVariant[] = ["primary", "secondary"];

export type ColoredBlockType = keyof typeof BLOCK_COLOR_FALLBACKS;

export const LOGO_LINK_TARGETS: readonly LogoLinkTarget[] = ["website", "custom", "none"];

/** A design layout's fields — EmailDesignSchema::LAYOUT_FIELDS. */
export const LAYOUT_FIELDS: readonly (keyof EmailDesignLayout)[] = ["version", "styles", "header", "signature", "footer"];

/** The signature area's fields — EmailDesignSchema::SIGNATURE_FIELDS. */
export const SIGNATURE_FIELDS: readonly (keyof SignatureArea)[] = ["enabled", "sign_off", "align"];

/** The signature area's optional switches, off when absent — EmailDesignSchema::SIGNATURE_OPTIONS. */
export const SIGNATURE_OPTIONS: readonly SignatureOption[] = ["show_company", "show_email", "show_phone"];

/** A template content's fields — EmailDesignSchema::CONTENT_FIELDS. */
export const CONTENT_FIELDS: { required: readonly (keyof TemplateContent)[]; optional: readonly (keyof TemplateContent)[] } = {
  required: ["version", "blocks"],
  optional: ["preheader", "sign_off", "style_overrides"],
};

/** Each part of an email → the blocks it may hold — EmailDesignSchema::REGION_BLOCKS. */
export const REGION_BLOCKS: Record<DesignRegion, readonly DesignBlockType[]> = {
  header: ["logo", "heading", "text", "button", "image", "divider", "spacer"],
  footer: ["footer", "text", "logo", "button", "image", "divider", "spacer", "actions"],
  blocks: ["heading", "text", "button", "image", "divider", "spacer", "card", "rows", "actions"],
};

/** The blocks a card may hold — EmailDesignSchema::CARD_BLOCKS. Never a card, never an image. */
export const CARD_BLOCKS: readonly DesignBlockType[] = ["heading", "text", "rows", "divider", "button", "spacer", "actions"];

/** A rows item's fields — EmailDesignSchema::ROWS_ITEM_FIELDS. */
export const ROWS_ITEM_FIELDS = { required: ["title"], optional: ["description"] } as const;

export const REGION_LABELS: Record<DesignRegion, string> = { header: "Header", footer: "Footer", blocks: "Content" };

/** Every field each block holds — EmailDesignSchema::BLOCK_FIELDS, in the same order. */
export const BLOCK_FIELDS: Record<DesignBlockType, { required: readonly string[]; optional: readonly string[] }> = {
  logo: { required: ["type", "id", "width", "align"], optional: ["link_target", "link_url"] },
  heading: { required: ["type", "id", "level", "text", "align"], optional: ["color", "font_size"] },
  text: { required: ["type", "id", "content", "align", "font_size"], optional: ["color"] },
  button: { required: ["type", "id", "label", "url", "align", "radius"], optional: ["background_color", "text_color", "variant"] },
  image: { required: ["type", "id", "asset_id", "alt", "width", "align"], optional: ["link_url"] },
  divider: { required: ["type", "id", "thickness"], optional: ["color"] },
  spacer: { required: ["type", "id", "height"], optional: [] },
  footer: { required: ["type", "id", "content", "align", "font_size"], optional: ["color"] },
  card: { required: ["type", "id", "blocks", "align"], optional: ["eyebrow", "title", "background_color", "border_color"] },
  rows: { required: ["type", "id", "items", "align", "dividers"], optional: ["title_color", "description_color", "divider_color"] },
  actions: { required: ["type", "id", "items", "align", "radius"], optional: [] },
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
  card: "Card",
  rows: "Rows",
  actions: "Actions",
};

/**
 * What "Add block" offers in each part. Image waits for image uploads: an image block needs a stored asset id,
 * and there is nowhere to choose one yet. An image already in a document still loads, edits and saves.
 */
export const ADDABLE_BLOCKS: Record<DesignRegion, readonly DesignBlockType[]> = {
  header: ["logo", "heading", "text", "button", "divider", "spacer"],
  footer: ["footer", "text", "logo", "button", "divider", "spacer", "actions"],
  blocks: ["heading", "text", "button", "divider", "spacer", "card", "rows", "actions"],
};

/** What "Add block" offers inside a card: everything a card may hold. */
export const ADDABLE_CARD_BLOCKS: readonly DesignBlockType[] = CARD_BLOCKS;

/** The fields each block's settings panel has a control for; any other problem shows at the top of it. */
export const BLOCK_CONTROL_FIELDS: Record<DesignBlockType, readonly string[]> = {
  logo: ["width", "align", "link_target", "link_url"],
  heading: ["text", "level", "align", "color", "font_size"],
  text: ["content", "align", "font_size", "color"],
  button: ["label", "url", "align", "background_color", "text_color", "radius", "variant"],
  image: ["alt", "width", "align", "link_url"],
  divider: ["color", "thickness"],
  spacer: ["height"],
  footer: ["content", "align", "font_size", "color"],
  card: ["eyebrow", "title", "align", "background_color", "border_color", "blocks"],
  rows: ["items", "align", "dividers", "title_color", "description_color", "divider_color"],
  actions: ["items", "align", "radius"],
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

/** An optional color's value: the first of `chain` that sets it, otherwise the system style it names. */
export function effectiveAdditionalColor(chain: readonly DesignStyles[], key: AdditionalColorStyleKey): string {
  for (const styles of chain) {
    const value = styles[key];
    if (value !== undefined) return value;
  }
  return effectiveStyle(chain, ADDITIONAL_COLOR_STYLES[key]);
}

/** An on/off style's value: the first of `chain` that sets it, otherwise its default. */
export function effectiveBooleanStyle(chain: readonly DesignStyles[], key: BooleanStyleKey): boolean {
  for (const styles of chain) {
    const value = styles[key];
    if (value !== undefined) return value;
  }
  return BOOLEAN_STYLES[key];
}

/** `styles` with an on/off style set, or removed — back to its default — when `value` is undefined. */
export function withBooleanStyle(styles: DesignStyles, key: BooleanStyleKey, value: boolean | undefined): DesignStyles {
  const next: DesignStyles = { ...styles };
  if (value === undefined) {
    delete next[key];
  } else {
    next[key] = value;
  }
  return next;
}

/** An optional size's value in px: the first of `chain` that sets it, otherwise its default. */
export function effectiveNumericStyle(chain: readonly DesignStyles[], key: NumericStyleKey): number {
  for (const styles of chain) {
    const value = styles[key];
    if (value !== undefined) return value;
  }
  return NUMERIC_STYLES[key][0];
}

function isAdditionalColor(key: string): key is AdditionalColorStyleKey {
  return key in ADDITIONAL_COLOR_STYLES;
}

/** What an unset block color inherits: the style BLOCK_COLOR_FALLBACKS names for it, through `chain`. */
export function inheritedBlockColor(type: ColoredBlockType, field: string, chain: readonly DesignStyles[]): string {
  const fallbacks: Record<string, DesignStyleKey | AdditionalColorStyleKey> = BLOCK_COLOR_FALLBACKS[type];
  const key = fallbacks[field] ?? "text_color";
  return isAdditionalColor(key) ? effectiveAdditionalColor(chain, key) : effectiveStyle(chain, key);
}

/** What an unset button color inherits: the accent colors, or the secondary ones for a secondary button. */
export function inheritedButtonColor(block: ButtonBlock, field: "background_color" | "text_color", chain: readonly DesignStyles[]): string {
  return block.variant === "secondary"
    ? effectiveAdditionalColor(chain, SECONDARY_BUTTON_COLORS[field])
    : inheritedBlockColor("button", field, chain);
}

/** `styles` with `key` set, or removed — inherited again — when `value` is undefined or blank. */
export function withStyle(styles: DesignStyles, key: DesignStyleKey | AdditionalColorStyleKey, value: string | undefined): DesignStyles {
  const next: DesignStyles = { ...styles };
  if (value === undefined || value === "") {
    delete next[key];
  } else {
    (next as Record<string, string>)[key] = value;
  }
  return next;
}

/** `styles` with a size set, or removed — back to its default — when `value` is undefined. */
export function withNumericStyle(styles: DesignStyles, key: NumericStyleKey, value: number | undefined): DesignStyles {
  const next: DesignStyles = { ...styles };
  if (value === undefined) {
    delete next[key];
  } else {
    next[key] = value;
  }
  return next;
}

/** Template content with its style overrides replaced; none at all removes `style_overrides`. */
function withOverrides(content: TemplateContent, overrides: DesignStyles): TemplateContent {
  const next: TemplateContent = { ...content };
  if (Object.keys(overrides).length === 0) {
    delete next.style_overrides;
  } else {
    next.style_overrides = overrides;
  }
  return next;
}

/** Template content with one on/off override set or cleared. */
export function withBooleanOverride(content: TemplateContent, key: BooleanStyleKey, value: boolean | undefined): TemplateContent {
  return withOverrides(content, withBooleanStyle(content.style_overrides ?? {}, key, value));
}

/** Template content with one size override set or cleared. */
export function withNumericOverride(content: TemplateContent, key: NumericStyleKey, value: number | undefined): TemplateContent {
  return withOverrides(content, withNumericStyle(content.style_overrides ?? {}, key, value));
}

/** Template content with one style override set or cleared; no overrides at all removes `style_overrides`. */
export function withStyleOverride(content: TemplateContent, key: DesignStyleKey | AdditionalColorStyleKey, value: string | undefined): TemplateContent {
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

/** Every block id in the given lists, a card's own blocks included: ids are unique across a whole document. */
export function blockIds(...lists: readonly (readonly DesignBlock[])[]): Set<string> {
  return new Set(lists.flatMap((list) => list.flatMap((block) => (block.type === "card" ? [block.id, ...block.blocks.map((inner) => inner.id)] : [block.id]))));
}

/** How many blocks a list holds, a card's own blocks included — what MAX_CONTENT_BLOCKS counts. */
export function blockCount(blocks: readonly DesignBlock[]): number {
  return blocks.reduce((total, block) => total + 1 + (block.type === "card" ? block.blocks.length : 0), 0);
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
    case "rows":
      return { type, id, items: [{ title: "Item", description: "What it includes." }], align: "left", dividers: true };
    case "actions":
      return {
        type,
        id,
        items: [
          { kind: "button", label: "Get in touch", url: "" },
          { kind: "link", label: "Learn more ›", url: "" },
        ],
        align: "center",
        radius: 24,
      };
    case "card":
      // A card starts with one rows block inside, its id derived from the card's — unique, as the card's is.
      return {
        type,
        id,
        blocks: [{ type: "rows", id: `${id}-rows`, items: [{ title: "Item", description: "What it includes." }], align: "center", dividers: true }],
        align: "center",
        title: "Summary",
      };
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
  const taken = new Set([...(options.taken ?? []), ...blockIds(blocks)]);
  copy.id = newBlockId(copy.type, taken, options.random);
  taken.add(copy.id);
  // A card's blocks are copied too, and each needs an id of its own: ids are unique across the document.
  if (copy.type === "card") {
    for (const inner of copy.blocks) {
      inner.id = newBlockId(inner.type, taken, options.random);
      taken.add(inner.id);
    }
  }

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
    case "rows":
      return block.items.length === 1 ? `1 row · ${block.items[0].title}` : `${block.items.length} rows`;
    case "actions":
      return block.items.map((item) => item.label).join(" · ");
    case "card":
      return [block.title || block.eyebrow, `${block.blocks.length} ${block.blocks.length === 1 ? "block" : "blocks"} inside`].filter(Boolean).join(" · ");
  }
}

// ── Rows ──────────────────────────────────────────────────────────────────────────────────────

/** A rows item with its description set, or removed when blank — "no description" is absent, not "". */
export function withRowDescription(item: { title: string; description?: string }, description: string): { title: string; description?: string } {
  return description === "" ? { title: item.title } : { ...item, description };
}

/** A card with its eyebrow or title set, or removed when blank. */
export function withCardText(card: CardBlock, field: "eyebrow" | "title", value: string): CardBlock {
  const next: CardBlock = { ...card };
  if (value === "") {
    delete next[field];
  } else {
    next[field] = value;
  }
  return next;
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
      let block: DesignBlock | undefined = list[Number(parts[2])];
      let rest = parts.slice(3);
      // A card's own block: `blocks.1.blocks.0.url` belongs to the card's first block, by its id.
      if (block?.type === "card" && rest[0] === "blocks" && /^\d+$/.test(rest[1] ?? "") && block.blocks[Number(rest[1])]) {
        block = block.blocks[Number(rest[1])];
        rest = rest.slice(2);
      }
      if (block) {
        // A rows item keeps its index and field (`items.2.title`) so the message lands on that row.
        const field = rest[0] === "items" && /^\d+$/.test(rest[1] ?? "") ? rest.slice(0, 3).join(".") : (rest[0] ?? "");
        (problems.blocks[block.id] ??= []).push({ field, message });
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
