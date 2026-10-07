/**
 * Email template management gates.
 *
 * Run with:  npm run email-templates:gates        (from frontend/)
 *
 * Same recipe as scripts/email-render-gates/: `frontend/` has no test runner, so Node strips the
 * TypeScript and copilot-gates/loader.mjs bridges "@/". The gates drive the REAL helpers Settings → Email
 * Templates, Settings → Email Designs and their visual editors use.
 *
 * The cases mirror EmailTemplateManagementTest, EmailTemplateContentTest and EmailDesignManagementTest on the
 * backend, so the forms refuse what the server refuses and the editors' vocabulary cannot drift from
 * EmailDesignSchema.
 *
 * A  UNKNOWN PLACEHOLDERS — whitelist keys pass, with or without inner spaces; anything else is named.
 * B  STRAY BRACES — a half-typed `{{first_name}` or `first_name}}` is caught.
 * C  FIELD MESSAGE — null when usable, a sentence when not.
 * D  PREVIEW — known keys take their sample, unknown lowercase keys go blank, anything else is untouched.
 * E  CARET INSERTION — inserts at the caret, replaces a selection, and reports where the caret lands.
 * F  STATIC — no dangerouslySetInnerHTML anywhere in the feature; the plain-text preview renders through
 *    EmailPlainText; the editors never build HTML, preview through EmailHtmlFrame from the two server preview
 *    endpoints, and save only serialized JSON; both pages load their editor on demand so neither list carries
 *    Tiptap; a plain-text template becomes designed content only through "Switch to visual designer"; nothing
 *    still uses the retired per-template design API; this file holds no literal control characters.
 * G  SCHEMA PARITY — block fields, regions, layout / signature / content fields, system style defaults, color
 *    fallbacks, logo link targets, fonts, alignments, ranges and limits equal the PHP EmailDesignSchema, and
 *    the starting design equals EmailDesignService::defaultLayout(), read from their sources.
 * H  DEFAULTS — every new block has exactly its schema fields, in range, with no colors of its own and a
 *    server-valid id; the starting layout and content fit their regions and inherit every style.
 * I  LIST OPERATIONS — move, duplicate (deep, fresh id, avoiding ids used elsewhere), remove, replace.
 * J  PLAIN TEXT → CONTENT — paragraphs, line breaks and placeholders kept; refused characters dropped; long
 *    bodies split under the text block limit; no styles or preview text written; a trailing
 *    "Sign-off,\n{{sender_name}}" becomes the template's own sign-off only when the design has a signature
 *    area (so it is not printed twice), and anything else is left in the body.
 * K  SERVER PROBLEMS — content and layout errors land on the block (by id), the field, or the document as a
 *    whole; errors for the other document or other fields are left alone; save and preview problems merge.
 * L  LINKS — the https / mailto / tel rule the server applies.
 * M  STYLE CHAIN — template overrides → design styles → system defaults, block colors through that chain,
 *    setting and clearing styles, overrides, preview text and sign-off, and a logo's default link target.
 * N  LOGO CROP — the upload and crop limits equal LogoPng / SvgSanitizer (PHP); moving and resizing a crop stays
 *    inside the image and above its minimum; shapes outside 1:4–10:1 are refused; what is sent is whole pixels
 *    for a PNG and four decimals for an SVG; the email image size.
 * O  BRANDING + APPLY TO ALL (static) — uploads go multipart to the branding endpoint; an SVG is inspected by the
 *    server first and only its sanitized version is shown and drawn; the SVG rendition is cropped by viewBox and
 *    drawn to a PNG canvas; no branding file builds HTML; the logo block still has no URL field; "Apply to all"
 *    shows the server's counts and sends the confirmed count.
 * P  CUSTOM PURPOSES AND TONES — the add rules equal EmailTemplateOptionService (PHP): limits, messages, cleaning,
 *    normalization, invalid characters, duplicates however spelled; the composer keeps its fixed purposes and adds
 *    custom ones through the same exact filter; both template editors offer "+ Add custom purpose/tone".
 * Q  CARDS, ROWS, ACTIONS AND ADDITIONAL STYLES — the action kinds, item fields and limit, the link underline style, the additional colors and sizes, signature switches, button variants, card
 *    blocks, rows items and their limits equal EmailDesignSchema (PHP); ids are unique across a card's blocks too,
 *    and duplicating a card gives its blocks fresh ids; the content count includes a card's blocks; the server's
 *    problems for a card's block land on that block and a row's on that row; the additional colors fall back to
 *    the system style they name and the sizes to their defaults; a card is edited with the shared block list.
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import {
  hasStrayBraces,
  insertAt,
  placeholderProblem,
  renderSample,
  unknownPlaceholders,
} from "@/modules/crm/features/email-templates/lib/email-template-text";
import {
  ACTION_BUTTON_OPTIONAL,
  ACTION_ITEM_REQUIRED,
  ACTION_KINDS,
  ADDABLE_BLOCKS,
  ADDABLE_CARD_BLOCKS,
  ADDITIONAL_COLOR_STYLES,
  ALIGNMENTS,
  BLOCK_COLOR_FALLBACKS,
  BOOLEAN_STYLES,
  BUTTON_VARIANTS,
  CARD_BLOCKS,
  HEADING_SIZES,
  MAX_ACTION_ITEMS,
  MAX_CARD_BLOCKS,
  MAX_ROWS_ITEMS,
  NUMERIC_STYLES,
  ROWS_ITEM_FIELDS,
  SIGNATURE_OPTIONS,
  BLOCK_CONTROL_FIELDS,
  BLOCK_FIELDS,
  CONTENT_FIELDS,
  FONT_OPTIONS,
  LAYOUT_FIELDS,
  LOGO_LINK_TARGETS,
  MAX_CONTENT_BLOCKS,
  MAX_REGION_BLOCKS,
  RANGES,
  REGION_BLOCKS,
  SIGNATURE_FIELDS,
  STYLE_DEFAULTS,
  STYLE_FIELDS,
  TEXT_LIMITS,
  blockCount,
  blockIds,
  contentFromPlainText,
  createBlock,
  defaultContent,
  defaultLayout,
  documentProblems,
  documentRegions,
  duplicateBlock,
  effectiveAdditionalColor,
  effectiveBooleanStyle,
  effectiveNumericStyle,
  effectiveStyle,
  inheritedBlockColor,
  inheritedButtonColor,
  isAllowedLinkUrl,
  logoLinkTarget,
  mergeProblems,
  moveBlock,
  newBlockId,
  optionalLink,
  problemCount,
  removeBlock,
  replaceBlock,
  serializeDocument,
  trailingSignOff,
  withPreheader,
  withSignOff,
  withBooleanOverride,
  withBooleanStyle,
  withCardText,
  withNumericOverride,
  withNumericStyle,
  withRowDescription,
  withStyle,
  withStyleOverride,
} from "@/modules/crm/features/email-templates/lib/email-design";
import {
  ASPECT_MESSAGE,
  LOGO_MAX_BYTES,
  MAX_ASPECT,
  MIN_ASPECT,
  MIN_PNG_CROP,
  RENDITION_WIDTH,
  SVG_MAX_BYTES,
  aspectProblem,
  describeRemoved,
  emailImageSize,
  fileProblem,
  fullCrop,
  logoFormatOf,
  minCropSize,
  moveCrop,
  normalizeCrop,
  pngOutputSize,
  renditionSize,
  resizeCrop,
} from "@/modules/crm/features/email-templates/lib/logo-crop";
import {
  ADD_CUSTOM_OPTION,
  OPTION_EMPTY,
  OPTION_INVALID,
  OPTION_LENGTH,
  OPTION_MAX_LENGTH,
  OPTION_MIN_LENGTH,
  cleanOptionValue,
  normalizeOptionValue,
  optionValueProblem,
} from "@/modules/crm/features/email-templates/lib/template-options";
import type {
  ActionsBlock,
  ButtonBlock,
  CardBlock,
  DesignBlock,
  DesignBlockType,
  DesignRegion,
  DesignStyles,
  EmailDesignLayout,
  HeadingBlock,
  LogoBlock,
  RowsBlock,
  TemplateContent,
  TextBlock,
} from "@/modules/crm/features/email-templates/types/email-design-types";

let failed = 0;
function gate(name: string, checks: [string, boolean][]) {
  const failures = checks.filter(([, ok]) => !ok).map(([label]) => label);
  const pass = failures.length === 0;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${name.padEnd(46)} ${checks.length - failures.length}/${checks.length}`);
  for (const f of failures.slice(0, 10)) console.log(`         ${f}`);
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

// The shape GET /settings/email-templates sends as meta.tokens.
const TOKENS = [
  { key: "first_name", label: "Contact first name", sample: "Maria" },
  { key: "contact_name", label: "Contact full name", sample: "Maria Santos" },
  { key: "business_name", label: "Business name", sample: "Santos Bakery" },
  { key: "industry", label: "Industry", sample: "Bakery" },
  { key: "city", label: "City", sample: "Quezon City" },
  { key: "sender_name", label: "Sender name", sample: "Alex Reyes" },
];

// ── A  unknown placeholders ─────────────────────────────────────────────────────────────────
{
  const cases: [string, string[]][] = [
    ["Call me at {{phone}}", ["{{phone}}"]],
    ["Hi {{FirstName}}", ["{{FirstName}}"]],
    ["Hi {{ first name }}", ["{{ first name }}"]],
    ["Hi {{ first_name }}", []],
    [TOKENS.map((t) => `{{${t.key}}}`).join(" "), []],
    ["{{phone}} and {{phone}}", ["{{phone}}"]],
    ["No placeholders at all.", []],
  ];
  gate(
    "A  unknown placeholders",
    cases.map(([text, expected]) => [`${JSON.stringify(text)} → ${JSON.stringify(expected)}`, same(unknownPlaceholders(text, TOKENS), expected)]),
  );
}

// ── B  stray braces ─────────────────────────────────────────────────────────────────────────
{
  const cases: [string, boolean][] = [
    ["Hi {{first_name}", true],
    ["Hi first_name}}", true],
    ["Hi {{first_name}}", false],
    ["Hi {single} braces are fine", false],
    ["", false],
  ];
  gate(
    "B  stray braces",
    cases.map(([text, expected]) => [`${JSON.stringify(text)} → ${expected}`, hasStrayBraces(text) === expected]),
  );
}

// ── C  field message ────────────────────────────────────────────────────────────────────────
gate("C  field message", [
  ["usable text → null", placeholderProblem("Hi {{first_name}}, from {{sender_name}}", TOKENS) === null],
  ["unknown placeholder names it", placeholderProblem("{{phone}}", TOKENS)?.includes("{{phone}}") === true],
  ["stray braces explained", placeholderProblem("Hi {{first_name}", TOKENS)?.includes("missing its opening") === true],
]);

// ── D  preview ──────────────────────────────────────────────────────────────────────────────
gate("D  preview", [
  ["known key takes its sample", renderSample("Hi {{first_name}}", TOKENS) === "Hi Maria"],
  ["inner spaces tolerated, like the renderer", renderSample("{{ business_name }}", TOKENS) === "Santos Bakery"],
  ["unknown lowercase key goes blank", renderSample("x{{unknown_key}}y", TOKENS) === "xy"],
  ["wrong case left as typed", renderSample("Hi {{FirstName}}", TOKENS) === "Hi {{FirstName}}"],
  ["every occurrence replaced", renderSample("{{city}}, {{city}}", TOKENS) === "Quezon City, Quezon City"],
]);

// ── E  caret insertion ──────────────────────────────────────────────────────────────────────
gate("E  caret insertion", [
  ["inserts at the caret", same(insertAt("Hi ,", 3, 3, "{{first_name}}"), { value: "Hi {{first_name}},", caret: 17 })],
  ["replaces a selection", same(insertAt("Hi NAME,", 3, 7, "{{first_name}}"), { value: "Hi {{first_name}},", caret: 17 })],
  ["appends at the end", same(insertAt("Hi", 2, 2, "!"), { value: "Hi!", caret: 3 })],
]);

// ── F  static ───────────────────────────────────────────────────────────────────────────────
{
  const featureDir = new URL("../../src/modules/crm/features/email-templates/", import.meta.url);

  /** Every .ts/.tsx file under `dir`, as [path relative to the feature, source]. */
  function sources(dir: URL, prefix = ""): [string, string][] {
    return readdirSync(dir).flatMap((name): [string, string][] => {
      const url = new URL(name, dir);
      if (statSync(url).isDirectory()) return sources(new URL(`${name}/`, dir), `${prefix}${name}/`);
      return /\.tsx?$/.test(name) ? [[`${prefix}${name}`, readFileSync(url, "utf8")]] : [];
    });
  }

  const files = sources(featureDir);
  const file = (path: string) => files.find(([name]) => name === path)?.[1] ?? "";
  const designer = files.filter(([name]) => name.startsWith("components/designer/"));
  const listSide = [
    "components/email-templates-page.tsx",
    "components/email-designs-page.tsx",
    "components/email-template-table.tsx",
    "components/email-template-editor.tsx",
    "components/email-template-preview.tsx",
    "components/apply-to-all-dialog.tsx",
    "components/branding/organization-logo-card.tsx",
    "components/branding/logo-upload-dialog.tsx",
    "components/branding/logo-cropper.tsx",
  ];
  const templatesPage = file("components/email-templates-page.tsx");
  const designsPage = file("components/email-designs-page.tsx");
  const designEditor = file("components/designer/email-design-editor.tsx");
  const contentEditor = file("components/designer/email-template-content-editor.tsx");
  const converters = files.filter(([name, text]) => name !== "lib/email-design.ts" && text.includes("contentFromPlainText("));
  const onDemand = (source: string, path: string) =>
    new RegExp(`dynamic\\(\\s*\\(\\)\\s*=>\\s*import\\("@/modules/crm/features/email-templates/components/designer/${path}"\\)`).test(source);
  const self = readFileSync(new URL(import.meta.url), "utf8");

  gate("F  static", [
    ...files.map(([name, text]): [string, boolean] => [`no dangerouslySetInnerHTML in ${name}`, !/dangerouslySetInnerHTML\s*[=:]/.test(text)]),
    ["plain-text preview renders through EmailPlainText", file("components/email-template-preview.tsx").includes("<EmailPlainText")],
    [
      "the editors have every component to check",
      [
        "design-block-card.tsx",
        "design-block-list.tsx",
        "design-controls.tsx",
        "design-preview-panel.tsx",
        "design-rich-text.tsx",
        "email-design-editor.tsx",
        "email-template-content-editor.tsx",
      ].every((name) => designer.some(([path]) => path === `components/designer/${name}`)),
    ],
    ...designer.map(([name, text]): [string, boolean] => [
      `${name} builds no HTML (srcDoc, innerHTML, outerHTML, insertAdjacentHTML, getHTML)`,
      !/srcDoc|innerHTML|outerHTML|insertAdjacentHTML|getHTML\s*\(/.test(text),
    ]),
    ["previews render through EmailHtmlFrame", file("components/designer/design-preview-panel.tsx").includes("<EmailHtmlFrame")],
    ["the design editor saves the serialized layout", designEditor.includes("layout: serializeDocument(")],
    ["the template editor saves the serialized content", contentEditor.includes("content: serializeDocument(")],
    ["the design editor previews through the design preview endpoint", designEditor.includes('"/settings/email-designs/preview"')],
    ["the template editor previews through the template preview endpoint", contentEditor.includes('"/settings/email-templates/preview"')],
    ["the preview hook posts to the endpoint it is given", file("hooks/use-email-preview.ts").includes("apiClient.post(endpoint")],
    ["the designs hook talks to /settings/email-designs", file("hooks/use-email-designs.ts").includes('"/settings/email-designs"')],
    ["placeholders enter rich text as text nodes, never HTML strings", file("components/designer/design-rich-text.tsx").includes('insertContent({ type: "text"')],
    ["the templates page loads its editor on demand", onDemand(templatesPage, "email-template-content-editor")],
    ["the designs page loads its editor on demand", onDemand(designsPage, "email-design-editor")],
    ...listSide.map((name): [string, boolean] => [
      `${name} does not import Tiptap or the editors statically`,
      !/from "@tiptap\/|from "@\/modules\/crm\/features\/email-templates\/components\/designer\//.test(file(name)),
    ]),
    [
      "a plain-text template becomes designed content only through Switch to visual designer",
      converters.length === 1 &&
        converters[0][0] === "components/email-templates-page.tsx" &&
        (templatesPage.match(/contentFromPlainText\(/g) ?? []).length === 1 &&
        /onSwitchToDesigner=\{[\s\S]*?contentFromPlainText\(/.test(templatesPage),
    ],
    ...files.map(([name, text]): [string, boolean] => [
      `${name} does not use the retired per-template design API`,
      !/designFromPlainText|serializeDesign|DesignSettings|DesignerSeed|designer\/email-designer"|use-email-design-preview|\bdefaultDesign\(\)/.test(text),
    ]),
    ["the email designs route exists", readFileSync(new URL("../../src/app/zerem/settings/email-designs/page.tsx", import.meta.url), "utf8").includes("<EmailDesignsPage")],
    ["these gates hold no literal control or bidi characters", !/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f\u202a-\u202e\u2066-\u2069]/.test(self)],
  ]);
}

// ── G  schema parity with the PHP ───────────────────────────────────────────────────────────
{
  const php = readFileSync(
    new URL("../../../backend/app/Modules/Crm/Support/EmailDesign/EmailDesignSchema.php", import.meta.url),
    "utf8",
  );
  const service = readFileSync(
    new URL("../../../backend/app/Modules/Crm/Services/Outreach/EmailDesignService.php", import.meta.url),
    "utf8",
  );
  const names = (text: string) => [...text.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
  const listConst = (name: string) => names(php.match(new RegExp(`public const ${name} = \\[([^\\]]*)\\]`))?.[1] ?? "");
  const constant = (name: string) => Number(php.match(new RegExp(`public const ${name} = ([\\d_]+);`))?.[1]?.replaceAll("_", ""));
  const innerWidth = constant("CONTENT_WIDTH") - 2 * constant("CONTENT_PADDING");
  const range = (name: string): [number, number] => {
    const m = php.match(new RegExp(`public const ${name} = \\[(\\d+), ([^\\]]+)\\];`));
    return [Number(m?.[1]), m?.[2] === "self::INNER_WIDTH" ? innerWidth : Number(m?.[2])];
  };

  const phpBlocks = Object.fromEntries(
    [...php.matchAll(/'(\w+)' => \['required' => \[([^\]]*)\], 'optional' => \[([^\]]*)\]\]/g)].map((m) => [
      m[1],
      { required: names(m[2]), optional: names(m[3]) },
    ]),
  );
  const phpRegions = Object.fromEntries(
    [...(php.match(/public const REGION_BLOCKS = \[([\s\S]*?)\];/)?.[1] ?? "").matchAll(/'(\w+)' => \[([^\]]*)\]/g)].map((m) => [m[1], names(m[2])]),
  );
  const contentFields = php.match(/public const CONTENT_FIELDS = \['required' => \[([^\]]*)\], 'optional' => \[([^\]]*)\]\];/);
  const phpFonts = [...(php.match(/public const FONTS = \[([\s\S]*?)\];/)?.[1] ?? "").matchAll(/'(\w+)' =>/g)].map((m) => m[1]);
  const phpDefaults = Object.fromEntries(
    [...(php.match(/public const SYSTEM_DEFAULTS = \[([\s\S]*?)\];/)?.[1] ?? "").matchAll(/'(\w+)' => '([^']*)'/g)].map((m) => [m[1], m[2]]),
  );
  const phpFallbacks = Object.fromEntries(
    [...(php.match(/public const BLOCK_COLOR_FALLBACKS = \[([\s\S]*?)\];/)?.[1] ?? "").matchAll(/'(\w+)' => \[([^\]]*)\]/g)].map((m) => [
      m[1],
      Object.fromEntries([...m[2].matchAll(/'(\w+)' => '(\w+)'/g)].map((pair) => [pair[1], pair[2]])),
    ]),
  );
  const regions = Object.keys(REGION_BLOCKS) as DesignRegion[];
  const start = defaultLayout();

  gate("G  schema parity", [
    ["the PHP schema was read", Object.keys(phpBlocks).length === 11],
    ["block types and fields match BLOCK_FIELDS", same(phpBlocks, BLOCK_FIELDS)],
    ["regions and the blocks they hold match REGION_BLOCKS", same(phpRegions, REGION_BLOCKS)],
    ["layout fields match", same(listConst("LAYOUT_FIELDS"), LAYOUT_FIELDS)],
    ["signature fields match", same(listConst("SIGNATURE_FIELDS"), SIGNATURE_FIELDS)],
    [
      "content fields match",
      contentFields !== null && same({ required: names(contentFields[1]), optional: names(contentFields[2]) }, CONTENT_FIELDS),
    ],
    ["system style defaults match STYLE_DEFAULTS", Object.keys(phpDefaults).length === 9 && same(phpDefaults, STYLE_DEFAULTS)],
    ["style fields keep the PHP order", same(Object.keys(phpDefaults), STYLE_FIELDS)],
    ["block color fallbacks match", Object.keys(phpFallbacks).length === 7 && same(phpFallbacks, BLOCK_COLOR_FALLBACKS)],
    ["logo link targets match", same(listConst("LOGO_LINK_TARGETS"), LOGO_LINK_TARGETS)],
    ["fonts match", same(phpFonts, FONT_OPTIONS.map((font) => font.value))],
    ["alignments match", same(listConst("ALIGNMENTS"), ALIGNMENTS)],
    ["version is 1", constant("VERSION") === 1],
    ["content block limit matches", constant("MAX_CONTENT_BLOCKS") === MAX_CONTENT_BLOCKS],
    ["header / footer block limit matches", constant("MAX_REGION_BLOCKS") === MAX_REGION_BLOCKS],
    ["heading levels", same(range("HEADING_LEVELS"), RANGES.headingLevel)],
    ["text font size", same(range("TEXT_FONT_SIZE"), RANGES.textFontSize)],
    ["footer font size", same(range("FOOTER_FONT_SIZE"), RANGES.footerFontSize)],
    ["image width", same(range("IMAGE_WIDTH"), RANGES.imageWidth)],
    ["logo width", same(range("LOGO_WIDTH"), RANGES.logoWidth)],
    ["spacer height", same(range("SPACER_HEIGHT"), RANGES.spacerHeight)],
    ["divider thickness", same(range("DIVIDER_THICKNESS"), RANGES.dividerThickness)],
    ["button radius", same(range("BUTTON_RADIUS"), RANGES.buttonRadius)],
    ["heading text limit", constant("MAX_HEADING_TEXT") === TEXT_LIMITS.heading],
    ["button label limit", constant("MAX_BUTTON_LABEL") === TEXT_LIMITS.buttonLabel],
    ["alt text limit", constant("MAX_ALT_TEXT") === TEXT_LIMITS.alt],
    ["sign-off limit", constant("MAX_SIGN_OFF") === TEXT_LIMITS.signOff],
    ["preheader limit", constant("MAX_PREHEADER") === TEXT_LIMITS.preheader],
    ["block text limit", constant("MAX_BLOCK_TEXT") === TEXT_LIMITS.blockText],
    ["url limit", constant("MAX_URL") === TEXT_LIMITS.url],
    ["design name limit", constant("MAX_DESIGN_NAME") === TEXT_LIMITS.designName],
    ...regions.map((region): [string, boolean] => [
      `every addable ${region} block is allowed there`,
      ADDABLE_BLOCKS[region].every((type) => REGION_BLOCKS[region].includes(type)),
    ]),
    ["image is not addable anywhere until uploads exist", regions.every((region) => !ADDABLE_BLOCKS[region].includes("image"))],
    [
      "every block control is a field of its block",
      (Object.keys(BLOCK_FIELDS) as DesignBlockType[]).every((type) =>
        BLOCK_CONTROL_FIELDS[type].every((field) => [...BLOCK_FIELDS[type].required, ...BLOCK_FIELDS[type].optional].includes(field)),
      ),
    ],
    [
      "the starting header equals EmailDesignService::defaultLayout()",
      service.includes("'header' => [['type' => 'logo', 'id' => 'header-logo', 'width' => 160, 'align' => 'center']]") &&
        same(start.header, [{ type: "logo", id: "header-logo", width: 160, align: "center" }]),
    ],
    [
      "the starting signature equals EmailDesignService::defaultLayout()",
      service.includes("'signature' => ['enabled' => true, 'sign_off' => 'Best regards,', 'align' => 'left']") &&
        same(start.signature, { enabled: true, sign_off: "Best regards,", align: "left" }),
    ],
    [
      "the starting styles and footer equal EmailDesignService::defaultLayout()",
      service.includes("'styles' => []") && service.includes("'footer' => []") && same(start.styles, {}) && same(start.footer, []),
    ],
  ]);
}

// ── H  defaults ─────────────────────────────────────────────────────────────────────────────
{
  const ID = /^[A-Za-z0-9_-]{1,64}$/;
  const HEX = /^#[0-9a-f]{6}$/i;
  const within = (value: unknown, [min, max]: readonly [number, number]) =>
    Number.isInteger(value) && (value as number) >= min && (value as number) <= max;
  const inRange = (block: DesignBlock): boolean => {
    switch (block.type) {
      case "heading":
        return within(block.level, RANGES.headingLevel);
      case "text":
        return within(block.font_size, RANGES.textFontSize);
      case "footer":
        return within(block.font_size, RANGES.footerFontSize);
      case "image":
        return within(block.width, RANGES.imageWidth);
      case "logo":
        return within(block.width, RANGES.logoWidth);
      case "spacer":
        return within(block.height, RANGES.spacerHeight);
      case "divider":
        return within(block.thickness, RANGES.dividerThickness);
      case "button":
        return within(block.radius, RANGES.buttonRadius);
      case "rows":
        return block.items.length >= 1 && block.items.length <= MAX_ROWS_ITEMS;
      case "actions":
        return block.items.length >= 1 && block.items.length <= MAX_ACTION_ITEMS && within(block.radius, RANGES.buttonRadius);
      case "card":
        return block.blocks.length >= 1 && block.blocks.length <= MAX_CARD_BLOCKS && block.blocks.every((inner) => CARD_BLOCKS.includes(inner.type) && inRange(inner));
    }
  };
  const validIds = (blocks: readonly DesignBlock[]) =>
    new Set(blocks.map((b) => b.id)).size === blocks.length && blocks.every((b) => ID.test(b.id));

  const checks: [string, boolean][] = [];
  for (const type of Object.keys(BLOCK_FIELDS) as DesignBlockType[]) {
    const block = createBlock(type, `${type}-1`);
    const keys = Object.keys(block);
    const { required, optional } = BLOCK_FIELDS[type];
    checks.push([`${type}: every required field`, required.every((key) => keys.includes(key))]);
    checks.push([`${type}: nothing outside the schema`, keys.every((key) => required.includes(key) || optional.includes(key))]);
    checks.push([`${type}: numbers in range`, inRange(block)]);
    checks.push([`${type}: no colors of its own (inherits)`, keys.every((key) => !key.endsWith("color"))]);
  }

  const layout = defaultLayout();
  const content = defaultContent();
  const first = newBlockId("text", new Set());
  const collision = newBlockId("text", new Set(), () => 0.5);
  const avoided = newBlockId("text", new Set([collision]), () => 0.5);

  gate("H  defaults", [
    ...checks,
    ["a new logo links to the website", logoLinkTarget(createBlock("logo", "logo-1") as LogoBlock) === "website"],
    ["starting layout is version 1 with exactly the layout fields", layout.version === 1 && same(Object.keys(layout), LAYOUT_FIELDS)],
    ["starting signature has exactly the signature fields", same(Object.keys(layout.signature), SIGNATURE_FIELDS)],
    ["starting layout inherits every style", same(layout.styles, {})],
    ["starting header and footer hold only their region's blocks", layout.header.every((b) => REGION_BLOCKS.header.includes(b.type)) && layout.footer.every((b) => REGION_BLOCKS.footer.includes(b.type))],
    ["starting layout ids are unique and valid", validIds([...layout.header, ...layout.footer])],
    ["starting layout carries no colors", !JSON.stringify(layout).includes("color")],
    ["starting content is version 1 with only the required fields", content.version === 1 && same(Object.keys(content), CONTENT_FIELDS.required)],
    ["starting content holds only content blocks", content.blocks.every((b) => REGION_BLOCKS.blocks.includes(b.type))],
    ["starting content ids are unique and valid", validIds(content.blocks)],
    ["starting content carries no colors or overrides", !JSON.stringify(content).includes("color")],
    ["starting content has readable content", content.blocks.some((b) => b.type === "heading" && b.text.trim() !== "")],
    [
      "system style defaults are usable values",
      FONT_OPTIONS.some((font) => font.value === STYLE_DEFAULTS.font) &&
        Object.entries(STYLE_DEFAULTS).filter(([key]) => key !== "font").every(([, value]) => HEX.test(value)),
    ],
    ["new ids are server-valid", ID.test(first)],
    ["a new id avoids one already taken", avoided !== collision && ID.test(avoided)],
    ["a serialized layout round-trips", same(JSON.parse(serializeDocument(layout)), layout)],
    ["serialized content round-trips", same(JSON.parse(serializeDocument(content)), content)],
  ]);
}

// ── I  list operations ──────────────────────────────────────────────────────────────────────
{
  const text = createBlock("text", "t") as TextBlock;
  const blocks: DesignBlock[] = [createBlock("heading", "h"), text, createBlock("button", "b")];
  const ids = (list: DesignBlock[]) => list.map((block) => block.id).join(",");

  const duplicated = duplicateBlock(blocks, "t", { random: () => 0.25 });
  const copy = duplicated.blocks[2] as TextBlock;
  copy.content.content.push({ type: "paragraph" });

  // The id a duplicate would get is already used in another part of the document (a design's footer).
  const elsewhere = newBlockId("text", new Set(), () => 0.25);
  const avoiding = duplicateBlock(blocks, "t", { taken: new Set([elsewhere]), random: () => 0.25 });

  const replaced = replaceBlock(blocks, { ...(blocks[0] as HeadingBlock), text: "New" });

  gate("I  list operations", [
    ["move first to last", ids(moveBlock(blocks, 0, 2)) === "t,b,h"],
    ["move last to first", ids(moveBlock(blocks, 2, 0)) === "b,h,t"],
    ["move one down", ids(moveBlock(blocks, 0, 1)) === "t,h,b"],
    ["out of range leaves the list alone", moveBlock(blocks, 2, 3) === blocks && moveBlock(blocks, -1, 0) === blocks],
    ["same position leaves the list alone", moveBlock(blocks, 1, 1) === blocks],
    [
      "duplicate lands right after the original",
      duplicated.blocks.length === 4 && duplicated.blocks[1].id === "t" && duplicated.blocks[2].type === "text",
    ],
    ["duplicate gets a fresh id", duplicated.id !== null && duplicated.id !== "t" && duplicated.blocks[2].id === duplicated.id],
    ["duplicate is a deep copy", text.content.content.length === 1],
    ["duplicate avoids ids used elsewhere in the document", avoiding.id !== null && avoiding.id !== elsewhere && /^[A-Za-z0-9_-]{1,64}$/.test(avoiding.id)],
    ["duplicating an unknown block changes nothing", duplicateBlock(blocks, "nope").blocks === blocks && duplicateBlock(blocks, "nope").id === null],
    ["remove", ids(removeBlock(blocks, "t")) === "h,b"],
    ["replace swaps only that block", (replaced[0] as HeadingBlock).text === "New" && replaced[1] === blocks[1] && replaced[2] === blocks[2]],
    ["block ids span every list given", same([...blockIds(blocks, [createBlock("footer", "f")])], ["h", "t", "b", "f"])],
  ]);
}

// ── J  plain text → content ─────────────────────────────────────────────────────────────────
{
  const docOf = (content: TemplateContent, index = 0) => (content.blocks[index] as TextBlock).content.content;
  const seeded = "Hey {{first_name}},\n\nfollowing up on our recent conversation.\n\nIs there a good time to connect?\n\nCheers,\n{{sender_name}}";
  const converted = contentFromPlainText(seeded, { signatureArea: true });
  const kept = contentFromPlainText(seeded);
  const para = (...inline: object[]) => ({ type: "paragraph", content: inline });
  const t = (text: string) => ({ type: "text", text });
  const br = { type: "hardBreak" };

  const letter = contentFromPlainText("Hi {{first_name}},\n\nLine one\nLine two\n\n\nBest,\n{{sender_name}}");
  const long = contentFromPlainText(["a".repeat(3000), "b".repeat(3000), "c".repeat(3000)].join("\n\n"));
  // A tab, a BEL control character and a right-to-left override — written as escapes, never literally.
  const refusedInput = "\tTabbed bell\u0007\u202e override";

  gate("J  plain text → content", [
    ["one text block", letter.blocks.length === 1 && letter.blocks[0].type === "text"],
    [
      "paragraphs, line breaks and placeholders kept",
      same(docOf(letter), [para(t("Hi {{first_name}},")), para(t("Line one"), br, t("Line two")), para(t("Best,"), br, t("{{sender_name}}"))]),
    ],
    ["Windows line endings", same(docOf(contentFromPlainText("\r\nWindows\r\nlines")), [para(t("Windows"), br, t("lines"))])],
    ["tabs become spaces; refused characters are dropped", same(docOf(contentFromPlainText(refusedInput)), [para(t(" Tabbed bell override"))])],
    ["an empty body gives one empty paragraph", same(docOf(contentFromPlainText("")), [{ type: "paragraph" }])],
    ["long bodies split across text blocks", long.blocks.length === 3 && long.blocks.every((block) => block.type === "text")],
    ["split blocks stay under the text limit", long.blocks.every((block) => JSON.stringify(block).length < TEXT_LIMITS.blockText + 200)],
    ["split block ids are unique", new Set(long.blocks.map((block) => block.id)).size === 3],
    ["only content blocks, within the content limit", long.blocks.length <= MAX_CONTENT_BLOCKS && long.blocks.every((b) => REGION_BLOCKS.blocks.includes(b.type))],
    [
      "no styles, sign-off or preview text written",
      same(Object.keys(letter), ["version", "blocks"]) && !JSON.stringify(letter.blocks).includes("color"),
    ],
    [
      "a trailing sign-off above {{sender_name}} is recognised",
      same(trailingSignOff(seeded), { signOff: "Cheers,", body: "Hey {{first_name}},\n\nfollowing up on our recent conversation.\n\nIs there a good time to connect?" }),
    ],
    ["a lone {{sender_name}} line is a signature with no sign-off", same(trailingSignOff("Hello there\n\n{{ sender_name }}\n"), { signOff: "", body: "Hello there" })],
    [
      "anything else is not a signature",
      [
        "Hello there\n\nThanks for reading\nBye",
        "Cheers,\n{{sender_name}}",
        "Hello\n\nHi {{first_name}}\n{{sender_name}}",
        "Hello\n\nBest,\nregards\n{{sender_name}}",
        "Hello\n\nCheers,\n{{sender_name}}\n{{city}}",
        `Hello\n\n${"x".repeat(TEXT_LIMITS.signOff + 1)}\n{{sender_name}}`,
      ].every((body) => trailingSignOff(body) === null),
    ],
    [
      "in a design with a signature area, the body's sign-off becomes the template's own (printed once)",
      converted.sign_off === "Cheers," && !JSON.stringify(converted.blocks).includes("Cheers,") && !JSON.stringify(converted.blocks).includes("sender_name"),
    ],
    ["the rest of the body is kept", JSON.stringify(converted.blocks).includes("Is there a good time to connect?") && converted.blocks.length === 1],
    [
      "without a signature area the body is converted untouched",
      !("sign_off" in kept) && JSON.stringify(kept.blocks).includes("Cheers,") && JSON.stringify(kept.blocks).includes("{{sender_name}}"),
    ],
  ]);
}

// ── K  server problems ──────────────────────────────────────────────────────────────────────
{
  const content: TemplateContent = { version: 1, blocks: [createBlock("heading", "h"), createBlock("button", "b")] };
  const contentProblems = documentProblems(
    {
      "content.blocks.1.url": "Links must be a full https://, mailto: or tel: address.",
      "content.blocks.0.text": "Unknown placeholder.",
      "content.style_overrides.font": "Choose one of the available fonts.",
      "content.preheader": "This text is too long (limit 150 characters).",
      "content.sign_off": "This text is too long (limit 100 characters).",
      content: "This email is too large for Gmail.",
      "content.blocks.9.text": "A block that no longer exists.",
      "content.version": "This design version is not supported.",
      "content.blocks": "Add at least one block.",
      subject: "Unknown placeholder.",
      email_design_id: "Choose one of your email designs.",
      "layout.header.0.width": "A layout error in a content request.",
    },
    "content",
    documentRegions(content),
  );

  const layout: EmailDesignLayout = {
    ...defaultLayout(),
    header: [createBlock("logo", "hl")],
    footer: [createBlock("footer", "f")],
  };
  const layoutProblems = documentProblems(
    {
      "layout.header.0.link_url": "Links must be a full https://, mailto: or tel: address.",
      "layout.footer.0.content.content.0.text": "Unknown placeholder.",
      "layout.styles.accent_color": "Use a six-digit hex color.",
      "layout.signature.sign_off": "This text is too long (limit 100 characters).",
      layout: "This change would break the template “Hello” (Proposal · Formal): too large.",
      "layout.footer.4.align": "A block that no longer exists.",
      name: "Another design already has this name.",
    },
    "layout",
    documentRegions(layout),
  );

  const saved = documentProblems({ "content.blocks.0.text": "Saved problem.", content: "Shared message." }, "content", documentRegions(content));
  const live = documentProblems({ "content.preheader": "Live problem.", content: "Shared message." }, "content", documentRegions(content));
  const merged = mergeProblems(saved, live);

  gate("K  server problems", [
    ["content regions are its blocks", same(Object.keys(documentRegions(content)), ["blocks"])],
    ["layout regions are its header and footer", same(Object.keys(documentRegions(layout)), ["header", "footer"])],
    [
      "a content block error lands on that block by id",
      same(contentProblems.blocks.b, [{ field: "url", message: "Links must be a full https://, mailto: or tel: address." }]),
    ],
    ["a content field keeps its top field", same(contentProblems.blocks.h, [{ field: "text", message: "Unknown placeholder." }])],
    ["a style override error lands on the override", contentProblems.fields["style_overrides.font"] === "Choose one of the available fonts."],
    ["a preheader error lands on the preview text", contentProblems.fields.preheader === "This text is too long (limit 150 characters)."],
    ["a sign-off error lands on the sign-off", contentProblems.fields.sign_off === "This text is too long (limit 100 characters)."],
    [
      "content-wide, unknown-index and list errors are general",
      same(contentProblems.general, [
        "This email is too large for Gmail.",
        "A block that no longer exists.",
        "This design version is not supported.",
        "Add at least one block.",
      ]),
    ],
    [
      "subject, design and layout errors are left out of content problems",
      !/Choose one of your|layout error/.test(JSON.stringify(contentProblems)) && !JSON.stringify(contentProblems.general).includes("Unknown placeholder"),
    ],
    ["content problems are counted", problemCount(contentProblems) === 9],
    [
      "a header block error lands on that block",
      same(layoutProblems.blocks.hl, [{ field: "link_url", message: "Links must be a full https://, mailto: or tel: address." }]),
    ],
    ["a footer rich-text error keeps its top field", same(layoutProblems.blocks.f, [{ field: "content", message: "Unknown placeholder." }])],
    ["a design style error lands on the style", layoutProblems.fields["styles.accent_color"] === "Use a six-digit hex color."],
    ["a signature error lands on the signature field", layoutProblems.fields["signature.sign_off"] === "This text is too long (limit 100 characters)."],
    [
      "a refusal for a template the design would break is general",
      same(layoutProblems.general, [
        "This change would break the template “Hello” (Proposal · Formal): too large.",
        "A block that no longer exists.",
      ]),
    ],
    ["the design name is left to the form", !JSON.stringify(layoutProblems).includes("Another design")],
    ["no errors, no problems", problemCount(documentProblems({}, "layout", documentRegions(layout))) === 0],
    [
      "save and preview problems merge without repeating a message",
      same(merged.general, ["Shared message."]) && merged.fields.preheader === "Live problem." && merged.blocks.h?.[0]?.message === "Saved problem.",
    ],
  ]);
}

// ── L  links ────────────────────────────────────────────────────────────────────────────────
{
  const allowed = [
    "https://viteseo.ph",
    "https://system.viteseo.ph/book?ref=email&x=1#top",
    "https://example.com:8443/path",
    "mailto:hello@viteseo.ph",
    "mailto:hello@viteseo.ph?subject=Hello%20there",
    "tel:+639171234567",
    "tel:02-8123-4567",
  ];
  const refused = [
    "",
    "javascript:alert(1)",
    "JaVaScRiPt:alert(1)",
    " javascript:alert(1)",
    "data:text/html,<script>alert(1)</script>",
    "http://example.com",
    "//evil.example.com",
    "/zerem/settings",
    "HTTPS://example.com",
    "https://",
    "https://user:pass@example.com",
    "https://viteseo.ph@evil.example.com",
    "https://localhost",
    "https://exämple.com",
    'https://example.com/"onmouseover="alert(1)',
    "https://exa mple.com",
    "https://example.com\n",
    "{{first_name}}",
    "https://example.com/?name={{first_name}}",
    "https://example.com/%7b%7bcity%7d%7d",
    "mailto:not-an-email",
    "mailto:a@example.com?bcc=evil@example.com",
    "tel:call-me",
    `https://example.com/${"a".repeat(2000)}`,
  ];

  gate("L  links", [
    ...allowed.map((url): [string, boolean] => [`allows ${url}`, isAllowedLinkUrl(url)]),
    ...refused.map((url): [string, boolean] => [`refuses ${JSON.stringify(url.slice(0, 60))}`, !isAllowedLinkUrl(url)]),
    ["a blank optional link is null", optionalLink("") === null && optionalLink("   ") === null],
    ["a filled optional link is kept", optionalLink("https://viteseo.ph") === "https://viteseo.ph"],
  ]);
}

// ── M  style chain ──────────────────────────────────────────────────────────────────────────
{
  const logo = (extra: Partial<LogoBlock> = {}): LogoBlock => ({ type: "logo", id: "logo", width: 160, align: "center", ...extra });
  const heading = { ...(createBlock("heading", "h") as HeadingBlock), color: undefined };
  const base = defaultContent();
  const overridden = withStyleOverride(base, "accent_color", "#aa0000");

  gate("M  style chain", [
    ["an unset style is the system default", effectiveStyle([], "accent_color") === STYLE_DEFAULTS.accent_color],
    ["a design style wins over the default", effectiveStyle([{}, { accent_color: "#bb0000" }], "accent_color") === "#bb0000"],
    ["a template override wins over the design", effectiveStyle([{ accent_color: "#aa0000" }, { accent_color: "#bb0000" }], "accent_color") === "#aa0000"],
    ["headings inherit the text color", inheritedBlockColor("heading", "color", [{}, { text_color: "#111111" }]) === "#111111"],
    ["text inherits the text color", inheritedBlockColor("text", "color", []) === STYLE_DEFAULTS.text_color],
    ["footers inherit the footer text color", inheritedBlockColor("footer", "color", [{ muted_text_color: "#222222" }]) === "#222222"],
    ["buttons inherit the accent color through the chain", inheritedBlockColor("button", "background_color", [{}, { accent_color: "#333333" }]) === "#333333"],
    ["button labels inherit the accent text color", inheritedBlockColor("button", "text_color", [{}, {}]) === STYLE_DEFAULTS.accent_text_color],
    ["dividers inherit the divider color", inheritedBlockColor("divider", "color", []) === STYLE_DEFAULTS.divider_color],
    ["setting a style adds it", same(withStyle({}, "text_color", "#444444"), { text_color: "#444444" })],
    ["clearing a style removes it", same(withStyle({ text_color: "#444444", font: "georgia" }, "text_color", undefined), { font: "georgia" })],
    ["a blank style is removed", same(withStyle({ text_color: "#444444" }, "text_color", ""), {})],
    ["an override is written into the content", same(overridden.style_overrides, { accent_color: "#aa0000" })],
    ["clearing the last override removes style_overrides", !("style_overrides" in withStyleOverride(overridden, "accent_color", undefined))],
    ["setting an override leaves the original content alone", !("style_overrides" in base)],
    ["preview text is set", withPreheader(base, "Hello {{first_name}}").preheader === "Hello {{first_name}}"],
    ["blank preview text is removed", !("preheader" in withPreheader(withPreheader(base, "Hi"), ""))],
    ["an empty own sign-off is kept, meaning no sign-off line", withSignOff(base, "").sign_off === ""],
    ["no own sign-off falls back to the design's", !("sign_off" in withSignOff(withSignOff(base, "Cheers,"), undefined))],
    ["an inherited block color is not sent", !serializeDocument({ version: 1, blocks: [heading] }).includes("color")],
    ["a logo with no target links to the website", logoLinkTarget(logo()) === "website"],
    ["a logo with only a link address is custom", logoLinkTarget(logo({ link_url: "https://viteseo.ph" })) === "custom"],
    ["a logo with a null address still links to the website", logoLinkTarget(logo({ link_url: null })) === "website"],
    ["an explicit target wins", logoLinkTarget(logo({ link_target: "none" })) === "none"],
  ]);
}

// ── N  logo crop ────────────────────────────────────────────────────────────────────────────
{
  const backend = (path: string) => readFileSync(new URL(`../../../backend/app/Modules/Crm/Support/EmailAssets/${path}`, import.meta.url), "utf8");
  const pngPhp = backend("LogoPng.php");
  const svgPhp = backend("SvgSanitizer.php");
  const constant = (php: string, name: string) => Number(php.match(new RegExp(`public const ${name} = ([\\d_.]+);`))?.[1]?.replaceAll("_", ""));
  const bounds = { width: 400, height: 200 };
  const min = { width: 8, height: 8 };
  const rect = { x: 10, y: 10, width: 100, height: 50 };

  gate("N  logo crop", [
    ["PHP parity: upload limit", constant(pngPhp, "MAX_BYTES") === LOGO_MAX_BYTES],
    ["PHP parity: SVG limit", constant(svgPhp, "MAX_BYTES") === SVG_MAX_BYTES],
    ["PHP parity: email image width", constant(pngPhp, "RENDITION_WIDTH") === RENDITION_WIDTH],
    ["PHP parity: smallest PNG crop", constant(pngPhp, "MIN_CROP") === MIN_PNG_CROP],
    ["PHP parity: shape limits", constant(pngPhp, "MIN_ASPECT") === MIN_ASPECT && constant(pngPhp, "MAX_ASPECT") === MAX_ASPECT],
    ["PHP parity: shape message", pngPhp.includes(`public const BAD_ASPECT = '${ASPECT_MESSAGE}';`)],
    ["a new crop is the whole image", same(fullCrop(400, 200), { x: 0, y: 0, width: 400, height: 200 })],
    ["moving stays inside the image", same(moveCrop(rect, 1000, -1000, bounds), { x: 300, y: 0, width: 100, height: 50 })],
    ["resizing a corner stops at the image edge", same(resizeCrop(rect, "se", 1000, 1000, bounds, min), { x: 10, y: 10, width: 390, height: 190 })],
    ["resizing never goes below the minimum", same(resizeCrop(rect, "nw", 500, 500, bounds, min), { x: 102, y: 52, width: 8, height: 8 })],
    ["resizing the left edge stops at 0 and keeps the right edge", same(resizeCrop(rect, "w", -50, 0, bounds, min), { x: 0, y: 10, width: 110, height: 50 })],
    ["resizing the top edge changes only the top", same(resizeCrop(rect, "n", 999, 20, bounds, min), { x: 10, y: 30, width: 100, height: 30 })],
    ["minimum crop: 8px for a PNG, 1% of an SVG's longer side", same(minCropSize("png", bounds), { width: 8, height: 8 }) && same(minCropSize("svg", { width: 24, height: 12 }), { width: 0.24, height: 0.24 })],
    [
      "shapes between 1:4 and 10:1 are accepted",
      aspectProblem({ x: 0, y: 0, width: 100, height: 10 }) === null && aspectProblem({ x: 0, y: 0, width: 25, height: 100 }) === null,
    ],
    [
      "shapes outside 1:4–10:1 are refused with the server's message",
      aspectProblem({ x: 0, y: 0, width: 110, height: 10 }) === ASPECT_MESSAGE && aspectProblem({ x: 0, y: 0, width: 20, height: 100 }) === ASPECT_MESSAGE,
    ],
    ["a PNG crop is sent as whole pixels inside the image", same(normalizeCrop({ x: 10.4, y: 0.6, width: 99.7, height: 50.2 }, "png", bounds), { x: 10, y: 1, width: 100, height: 50 })],
    ["a PNG crop is clamped to the image after rounding", same(normalizeCrop({ x: 350.6, y: 0, width: 49.6, height: 200 }, "png", bounds), { x: 351, y: 0, width: 49, height: 200 })],
    ["an SVG crop keeps four decimals", same(normalizeCrop({ x: 1.234567, y: 0, width: 10.00004, height: 5 }, "svg", { width: 24, height: 24 }), { x: 1.2346, y: 0, width: 10, height: 5 })],
    ["an SVG is drawn 600px wide at the crop's shape", same(renditionSize({ x: 0, y: 0, width: 24, height: 12 }), { width: 600, height: 300 })],
    [
      "a PNG email image is at most 600px wide and never enlarged",
      same(pngOutputSize({ x: 0, y: 0, width: 50, height: 20 }), { width: 50, height: 20 }) && same(pngOutputSize({ x: 0, y: 0, width: 1600, height: 400 }), { width: 600, height: 150 }),
    ],
    ["the email image size follows the format", same(emailImageSize({ x: 0, y: 0, width: 50, height: 20 }, "svg"), { width: 600, height: 240 })],
    ["format by extension", logoFormatOf("Logo.PNG") === "png" && logoFormatOf("mark.svg") === "svg" && logoFormatOf("photo.jpg") === null && logoFormatOf("logo.svg.exe") === null],
    [
      "file limits before upload",
      fileProblem("a.png", LOGO_MAX_BYTES + 1) !== null && fileProblem("a.svg", SVG_MAX_BYTES + 1) !== null && fileProblem("a.png", 1000) === null && fileProblem("a.gif", 10) !== null,
    ],
    ["the sanitizer report reads naturally", describeRemoved({ script: 1, "event handler": 2 }) === "1 script, 2 event handlers"],
  ]);
}

// ── O  branding + apply to all (static) ────────────────────────────────────────────────────
{
  const feature = (path: string) => readFileSync(new URL(`../../src/modules/crm/features/email-templates/${path}`, import.meta.url), "utf8");
  const branding = feature("hooks/use-email-branding.ts");
  const upload = feature("components/branding/logo-upload-dialog.tsx");
  const rendition = feature("lib/svg-rendition.ts");
  const cropper = feature("components/branding/logo-cropper.tsx");
  const card = feature("components/branding/organization-logo-card.tsx");
  const apply = feature("components/apply-to-all-dialog.tsx");
  const designs = feature("hooks/use-email-designs.ts");
  const editor = feature("components/designer/email-design-editor.tsx");
  const page = feature("components/email-designs-page.tsx");

  gate("O  branding + apply to all (static)", [
    ["logo requests go to the branding endpoint as multipart", branding.includes('const ENDPOINT = "/settings/email-branding"') && branding.includes("`${ENDPOINT}/logo`") && branding.includes('"multipart/form-data"')],
    ["a file is inspected by the server before it is cropped", branding.includes("`${ENDPOINT}/logo/inspect`") && upload.includes("inspect.mutateAsync(")],
    ["an SVG is shown from the server's sanitized SVG, never the uploaded file", upload.includes("new Blob([inspection.svg") && /format === "svg"\s*\?\s*URL\.createObjectURL\(new Blob\(\[inspection\.svg/.test(upload)],
    ["an SVG is drawn from the sanitized SVG", upload.includes("rasterizeCroppedSvg(inspection.svg")],
    [
      "the SVG rendition is parsed as XML, cropped by viewBox and drawn to a PNG canvas",
      rendition.includes('"image/svg+xml"') && rendition.includes('setAttribute("viewBox"') && rendition.includes("drawImage(") && rendition.includes('"image/png"'),
    ],
    ["logo requests send the crop and, for an SVG, the rendition", branding.includes('form.append("crop", JSON.stringify(crop))') && branding.includes('form.append("rendition"')],
    [
      "no branding or apply-to-all file builds HTML",
      [branding, upload, rendition, cropper, card, apply].every((text) => !/dangerouslySetInnerHTML|innerHTML|outerHTML|insertAdjacentHTML|srcDoc/.test(text)),
    ],
    ["a logo block still has no URL or asset field", same(BLOCK_FIELDS.logo, { required: ["type", "id", "width", "align"], optional: ["link_target", "link_url"] })],
    ["logo changes refresh the cached previews", branding.includes("queryKeys.emailTemplates.all")],
    ["apply to all asks the server for its counts", designs.includes("/apply-to-all-summary") && apply.includes("useApplyToAllSummary(")],
    ["apply to all sends the confirmed count", editor.includes("apply_to_all_count: applyCount") && page.includes("apply_to_all_count: count")],
    ["apply to all is always confirmed first", editor.includes("<ApplyToAllDialog") && page.includes("<ApplyToAllDialog") && apply.includes("<ConfirmDialog")],
    ["the confirmation says plain-text templates do not change", apply.includes("plain-text template") && apply.includes("never converted")],
    ["the designs page shows the organization logo", page.includes("<OrganizationLogoCard")],
  ]);
}

// ── P  custom purposes and tones ────────────────────────────────────────────────────────────
{
  const backend = (path: string) => readFileSync(new URL(`../../../backend/app/Modules/Crm/${path}`, import.meta.url), "utf8");
  const service = backend("Services/Outreach/EmailTemplateOptionService.php");
  const composer = backend("Controllers/EmailTemplateController.php");
  const feature = (path: string) => readFileSync(new URL(`../../src/modules/crm/features/email-templates/${path}`, import.meta.url), "utf8");
  const phpString = (name: string) => service.match(new RegExp(`public const ${name} = '([^']*)';`))?.[1];
  const phpNumber = (name: string) => Number(service.match(new RegExp(`public const ${name} = (\\d+);`))?.[1]);
  const TAB = String.fromCharCode(9);
  const RLO = String.fromCharCode(0x202e);
  const ZWSP = String.fromCharCode(0x200b);
  const purposes = ["Proposal Check-in", "Follow-up", "Introduction", "proposal", "proposal_follow_up", "introduction", "meeting_reminder", "thank_you"];
  const editors = [feature("components/email-template-editor.tsx"), feature("components/designer/email-template-content-editor.tsx")];

  gate("P  custom purposes and tones", [
    ["PHP parity: length limits", phpNumber("MIN_LENGTH") === OPTION_MIN_LENGTH && phpNumber("MAX_LENGTH") === OPTION_MAX_LENGTH],
    ["PHP parity: messages", phpString("EMPTY") === OPTION_EMPTY && phpString("LENGTH") === OPTION_LENGTH && phpString("INVALID") === OPTION_INVALID],
    [
      "PHP: every automation key and the reserved keys are refused as custom purposes",
      service.includes("EmailTemplateCatalog::automationPurposes()") && /RESERVED_PURPOSE_KEYS = \['follow_up', 'closing'\]/.test(service),
    ],
    ["cleaning trims and collapses whitespace", cleanOptionValue("  Sales   Pitch  ") === "Sales Pitch"],
    [
      "normalizing ignores case, spaces, underscores and hyphens",
      ["Sales Pitch", "sales_pitch", "SALES-PITCH", "  sales  -  pitch "].every((value) => normalizeOptionValue(value) === "sales pitch"),
    ],
    ["an empty value", optionValueProblem("   ", "purpose", []) === OPTION_EMPTY],
    [
      "2 to 60 characters",
      optionValueProblem("a", "tone", []) === OPTION_LENGTH &&
        optionValueProblem("x".repeat(61), "tone", []) === OPTION_LENGTH &&
        optionValueProblem("x".repeat(60), "tone", []) === null &&
        optionValueProblem("Hi", "tone", []) === null,
    ],
    [
      "braces, markup, backslashes, backticks, control and invisible characters and punctuation-only values are invalid",
      ["{{first_name}}", "Hi {there}", "<b>Bold</b>", `Tab${TAB}bed`, `Right${RLO}left`, `Zero${ZWSP}width`, "Back\\slash", "Back`tick", "--"].every(
        (value) => optionValueProblem(value, "purpose", []) === OPTION_INVALID,
      ),
    ],
    [
      "a value already in the list is refused however spelled",
      optionValueProblem("follow up", "purpose", purposes) !== null &&
        optionValueProblem("MEETING REMINDER", "purpose", purposes) !== null &&
        optionValueProblem("Proposal", "purpose", purposes) !== null &&
        optionValueProblem("casual", "tone", ["Warm & professional", "Formal", "Casual"]) !== null,
    ],
    ["an acceptable value", optionValueProblem("  Sales   Pitch ", "purpose", purposes) === null],
    ["the add-item sentinel can never be a value", optionValueProblem(ADD_CUSTOM_OPTION, "purpose", []) === OPTION_INVALID],
    [
      "composer: the fixed purposes stay, custom ones are added, and the filter stays exact",
      composer.includes("private const COMPOSER_PURPOSES = ['Proposal Check-in', 'Follow-up', 'Introduction'];") &&
        composer.includes("$purposes = [...self::COMPOSER_PURPOSES, ...$options->customValues(EmailTemplateOptionService::TYPE_PURPOSE)];") &&
        composer.includes("in_array($template->purpose, $purposes, true)"),
    ],
    [
      "both template editors offer + Add custom purpose and + Add custom tone",
      editors.every((text) => text.includes("<CustomPurposeItems") && text.includes("<CustomToneItems") && text.includes("ADD_CUSTOM_OPTION") && text.includes("<AddCustomOptionDialog")),
    ],
    ["the add dialog never submits the editor's form", feature("components/add-custom-option-dialog.tsx").includes("event.stopPropagation()")],
    ["options go to the organization-scoped endpoint", feature("hooks/use-email-template-options.ts").includes('const ENDPOINT = "/settings/email-template-options"')],
  ]);
}

// ── Q  cards, rows and additional styles ────────────────────────────────────────────────────
{
  const php = readFileSync(new URL("../../../backend/app/Modules/Crm/Support/EmailDesign/EmailDesignSchema.php", import.meta.url), "utf8");
  const names = (text: string) => [...text.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
  const listConst = (name: string) => names(php.match(new RegExp(`public const ${name} = \\[([^\\]]*)\\]`))?.[1] ?? "");
  const constant = (name: string) => Number(php.match(new RegExp(`public const ${name} = ([\\d_]+);`))?.[1]?.replaceAll("_", ""));
  const pairs = (name: string) =>
    Object.fromEntries(
      [...(php.match(new RegExp(`public const ${name} = \\[([\\s\\S]*?)\\];`))?.[1] ?? "").matchAll(/'(\w+)' => '(\w+)'/g)].map((m) => [m[1], m[2]]),
    );
  const phpNumeric = Object.fromEntries(
    [...(php.match(/public const NUMERIC_STYLES = \[([\s\S]*?)\n    \];/)?.[1] ?? "").matchAll(/'(\w+)' => \[([\w:_]+), \[(\d+), (\d+)\]\]/g)].map((m) => [
      m[1],
      [m[2] === "self::CONTENT_PADDING" ? constant("CONTENT_PADDING") : Number(m[2]), [Number(m[3]), Number(m[4])]],
    ]),
  );
  const headingRange = php.match(/public const HEADING_FONT_SIZE = \[(\d+), (\d+)\];/);
  const headingSizes = Object.fromEntries([...(php.match(/public const HEADING_SIZES = \[([^\]]*)\]/)?.[1] ?? "").matchAll(/(\d) => (\d+)/g)].map((m) => [m[1], Number(m[2])]));
  const rowsFields = php.match(/public const ROWS_ITEM_FIELDS = \['required' => \[([^\]]*)\], 'optional' => \[([^\]]*)\]\];/);

  // Ids are unique across a card's blocks, and a duplicated card's blocks get fresh ones.
  const card = createBlock("card", "card-1") as CardBlock;
  const list: DesignBlock[] = [createBlock("text", "t"), card];
  const duplicated = duplicateBlock(list, "card-1", { random: () => 0.5 });
  const copy = duplicated.blocks[2] as CardBlock;
  const allIds = [...blockIds(duplicated.blocks)];

  // A card's block's problem lands on that block; a row's on that row.
  const rows = createBlock("rows", "r") as RowsBlock;
  const content: TemplateContent = { version: 1, blocks: [createBlock("heading", "h"), { ...card, blocks: [createBlock("text", "inner-t"), rows] }] };
  const placed = documentProblems(
    {
      "content.blocks.1.blocks.0.content": "Inner text problem.",
      "content.blocks.1.blocks.1.items.0.title": "Row title problem.",
      "content.blocks.1.blocks": "Card blocks problem.",
      "content.blocks.1.title": "Card title problem.",
      "content.blocks.1.blocks.9.url": "A block that is not there.",
    },
    "content",
    documentRegions(content),
  );

  const chain: DesignStyles[] = [{}, { background_color: "#eeeeee", text_color: "#111111", muted_text_color: "#777777" }];
  const secondary = { ...(createBlock("button", "b") as ButtonBlock), variant: "secondary" as const };

  const phpBooleans = Object.fromEntries([...(php.match(/public const BOOLEAN_STYLES = \[([\s\S]*?)\];/)?.[1] ?? "").matchAll(/'(\w+)' => (true|false)/g)].map((m) => [m[1], m[2] === "true"]));
  const actions = createBlock("actions", "a-1") as ActionsBlock;

  gate("Q  cards, rows, actions and additional styles", [
    ["action kinds match", same(listConst("ACTION_KINDS"), ACTION_KINDS)],
    ["action item fields match", same(listConst("ACTION_ITEM_REQUIRED"), ACTION_ITEM_REQUIRED) && same(listConst("ACTION_BUTTON_OPTIONAL"), ACTION_BUTTON_OPTIONAL)],
    ["action item limit matches", constant("MAX_ACTION_ITEMS") === MAX_ACTION_ITEMS],
    ["on/off styles and defaults match", Object.keys(phpBooleans).length === 1 && same(phpBooleans, BOOLEAN_STYLES)],
    ["a new actions row is a button and a link, generic and unlinked until filled in", same(actions.items.map((i) => i.kind), ["button", "link"]) && actions.items.every((i) => i.url === "")],
    ["actions may go in content, a card and the footer, never the header", REGION_BLOCKS.blocks.includes("actions") && CARD_BLOCKS.includes("actions") && REGION_BLOCKS.footer.includes("actions") && !REGION_BLOCKS.header.includes("actions")],
    ["links are underlined unless turned off, nearest first", effectiveBooleanStyle([], "link_underline") === true && effectiveBooleanStyle([{}, { link_underline: false }], "link_underline") === false && effectiveBooleanStyle([{ link_underline: true }, { link_underline: false }], "link_underline") === true],
    ["turning the underline back on removes the key", same(withBooleanStyle({ link_underline: false }, "link_underline", undefined), {}) && !("style_overrides" in withBooleanOverride({ version: 1, blocks: [], style_overrides: { link_underline: false } }, "link_underline", undefined))],
    ["additional colors match the PHP, in order", same(pairs("ADDITIONAL_COLOR_STYLES"), ADDITIONAL_COLOR_STYLES) && same(Object.keys(pairs("ADDITIONAL_COLOR_STYLES")), Object.keys(ADDITIONAL_COLOR_STYLES))],
    ["numeric styles, defaults and ranges match the PHP", Object.keys(phpNumeric).length === 5 && same(phpNumeric, NUMERIC_STYLES)],
    ["signature switches match", same(listConst("SIGNATURE_OPTIONS"), SIGNATURE_OPTIONS)],
    ["button variants match", same(listConst("BUTTON_VARIANTS"), BUTTON_VARIANTS)],
    ["card blocks match", same(listConst("CARD_BLOCKS"), CARD_BLOCKS)],
    ["a card never holds a card or an image", !CARD_BLOCKS.includes("card") && !CARD_BLOCKS.includes("image")],
    ["everything addable in a card is allowed in a card", ADDABLE_CARD_BLOCKS.every((type) => CARD_BLOCKS.includes(type))],
    ["rows item fields match", rowsFields !== null && same({ required: names(rowsFields[1]), optional: names(rowsFields[2]) }, ROWS_ITEM_FIELDS)],
    ["heading font size range matches", headingRange !== null && same([Number(headingRange[1]), Number(headingRange[2])], RANGES.headingFontSize)],
    ["heading level sizes match", same(headingSizes, { "1": HEADING_SIZES[1], "2": HEADING_SIZES[2], "3": HEADING_SIZES[3] })],
    ["card block limit matches", constant("MAX_CARD_BLOCKS") === MAX_CARD_BLOCKS],
    ["rows item limit matches", constant("MAX_ROWS_ITEMS") === MAX_ROWS_ITEMS],
    ["card label limit matches", constant("MAX_CARD_EYEBROW") === TEXT_LIMITS.cardEyebrow],
    ["card title limit matches", constant("MAX_CARD_TITLE") === TEXT_LIMITS.cardTitle],
    ["row title limit matches", constant("MAX_ROW_TITLE") === TEXT_LIMITS.rowTitle],
    ["row description limit matches", constant("MAX_ROW_DESCRIPTION") === TEXT_LIMITS.rowDescription],
    ["a new card holds one rows block whose id differs from the card's", card.blocks.length === 1 && card.blocks[0].type === "rows" && card.blocks[0].id !== card.id],
    ["block ids include a card's blocks", blockIds(list).has(card.blocks[0].id)],
    ["the content count includes a card's blocks", blockCount(list) === 3],
    ["a duplicated card lands after the original with a fresh id", duplicated.id !== null && copy.type === "card" && copy.id !== card.id],
    ["a duplicated card's blocks get fresh ids", copy.blocks[0].id !== card.blocks[0].id && new Set(allIds).size === allIds.length],
    ["a duplicated card is a deep copy", copy.blocks !== card.blocks && card.blocks.length === 1],
    ["a card's block's problem lands on that block", placed.blocks["inner-t"]?.[0]?.field === "content"],
    ["a row's problem keeps its row and field", placed.blocks["r"]?.[0]?.field === "items.0.title"],
    ["a card's own problems stay on the card", same(placed.blocks["card-1"]?.map((p) => p.field), ["blocks", "title", "blocks"])],
    ["card and rows controls are fields of their block", ["card", "rows"].every((type) => BLOCK_CONTROL_FIELDS[type as "card"].every((field) => [...BLOCK_FIELDS[type as "card"].required, ...BLOCK_FIELDS[type as "card"].optional].includes(field)))],
    ["an unset additional color takes the system style it names", effectiveAdditionalColor(chain, "card_background_color") === "#eeeeee" && effectiveAdditionalColor(chain, "eyebrow_color") === "#777777" && effectiveAdditionalColor(chain, "secondary_color") === "#111111"],
    ["a set additional color wins, nearest first", effectiveAdditionalColor([{ card_background_color: "#abcdef" }, { card_background_color: "#000000" }], "card_background_color") === "#abcdef"],
    ["an unset size takes its default", effectiveNumericStyle([{}, {}], "content_padding") === 24 && effectiveNumericStyle([], "card_radius") === 12],
    ["a set size wins, nearest first, zero included", effectiveNumericStyle([{ card_radius: 0 }, { card_radius: 20 }], "card_radius") === 0],
    ["a card's colors inherit the card styles", inheritedBlockColor("card", "background_color", chain) === "#eeeeee"],
    ["a secondary button inherits the secondary colors", inheritedButtonColor(secondary, "background_color", chain) === "#111111"],
    ["a primary button still inherits the accent", inheritedButtonColor({ ...secondary, variant: "primary" }, "background_color", []) === STYLE_DEFAULTS.accent_color],
    ["setting and clearing a size", same(withNumericStyle({}, "card_padding", 30), { card_padding: 30 }) && same(withNumericStyle({ card_padding: 30 }, "card_padding", undefined), {})],
    ["clearing the last size override removes style_overrides", !("style_overrides" in withNumericOverride({ version: 1, blocks: [], style_overrides: { card_radius: 4 } }, "card_radius", undefined))],
    ["a blank description is removed, not stored as empty", same(withRowDescription({ title: "A", description: "x" }, ""), { title: "A" })],
    ["a blank card title is removed", !("title" in withCardText(card, "title", ""))],
    [
      "a card is edited with the shared block list, not a second editor",
      readFileSync(new URL("../../src/modules/crm/features/email-templates/components/designer/design-block-card.tsx", import.meta.url), "utf8").includes("<DesignBlockList"),
    ],
  ]);
}

console.log(failed === 0 ? "\nALL GATES PASS" : `\n${failed} GATE(S) FAILED`);
process.exit(failed === 0 ? 0 : 1);
