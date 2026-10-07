/**
 * Email design v1 — the two documents a designed email is made of, mirroring the server's EmailDesignSchema.
 *
 *   EmailDesignLayout  a reusable email design's look: styles, header, signature area, footer
 *   TemplateContent    one template's message: blocks, preview text, sign-off and style overrides
 *
 * The designers build and edit ONLY these shapes. Each is sent to the server as one JSON string and the
 * server alone composes and renders them into HTML (EmailDesignRenderer), for the previews and for sending;
 * nothing in the browser renders or submits markup. scripts/email-template-gates compares the field lists
 * with the PHP.
 *
 * Every style is optional. An absent style is inherited — block color → template override → design style →
 * organization email branding → system default — so a design stores only what it sets and a template only
 * what it deliberately overrides.
 */

export type DesignAlignment = "left" | "center" | "right";

export type DesignFont = "arial" | "helvetica" | "verdana" | "tahoma" | "trebuchet" | "georgia" | "times";

/** A Tiptap node as JSON. The server holds text and footer documents to an email-safe subset. */
export type RichTextNode = {
  type: string;
  attrs?: Record<string, unknown>;
  content?: RichTextNode[];
  marks?: { type: string; attrs?: Record<string, unknown> }[];
  text?: string;
};

export type RichTextDoc = { type: "doc"; content: RichTextNode[] };

/** The inheritable system styles (EmailDesignSchema::SYSTEM_DEFAULTS). Every one optional. */
export type SystemStyles = {
  font?: DesignFont;
  background_color?: string;
  content_background_color?: string;
  text_color?: string;
  /** Footers. */
  muted_text_color?: string;
  link_color?: string;
  /** Buttons. */
  accent_color?: string;
  /** Button labels. */
  accent_text_color?: string;
  divider_color?: string;
};

/**
 * Optional colors beside the system styles (EmailDesignSchema::ADDITIONAL_COLOR_STYLES). Unset, each takes the
 * resolved system style it names — a card's background is the page background, and so on.
 */
export type AdditionalColorStyles = {
  card_background_color?: string;
  card_border_color?: string;
  eyebrow_color?: string;
  secondary_color?: string;
  secondary_text_color?: string;
};

/** Optional sizes in px (EmailDesignSchema::NUMERIC_STYLES). Unset, each takes its default. */
export type NumericStyles = {
  container_radius?: number;
  content_padding?: number;
  card_radius?: number;
  card_padding?: number;
  card_border_width?: number;
  card_eyebrow_size?: number;
  card_title_size?: number;
  row_title_size?: number;
  row_description_size?: number;
  row_padding?: number;
  /** The signature's sign-off and sender name. */
  signature_size?: number;
  /** The signature's other lines: company, email and phone. */
  signature_detail_size?: number;
  button_padding_y?: number;
  button_padding_x?: number;
};

/** Optional on/off styles (EmailDesignSchema::BOOLEAN_STYLES). Unset, each takes its default. */
export type BooleanStyles = {
  /** Links in text, footers and actions are underlined. Unset: true, as they always were. */
  link_underline?: boolean;
};

/** A design's styles, or a template's style overrides. Every one optional. */
export type DesignStyles = SystemStyles & AdditionalColorStyles & NumericStyles & BooleanStyles;

/** The system styles: the font and the nine colors every design has always had. */
export type DesignStyleKey = keyof SystemStyles;

export type AdditionalColorStyleKey = keyof AdditionalColorStyles;

export type NumericStyleKey = keyof NumericStyles;

export type BooleanStyleKey = keyof BooleanStyles;

export type DesignStyleValues = Required<SystemStyles>;

/** Where a logo links: the organization website (the default), a custom address, or nowhere. */
export type LogoLinkTarget = "website" | "custom" | "none";

/** The organization's logo, placed. Its image comes from email branding; a design never holds a logo URL. */
export type LogoBlock = {
  type: "logo";
  id: string;
  width: number;
  align: DesignAlignment;
  link_target?: LogoLinkTarget;
  link_url?: string | null;
};

export type HeadingBlock = {
  type: "heading";
  id: string;
  level: 1 | 2 | 3;
  text: string;
  align: DesignAlignment;
  color?: string;
  /** A size of its own in px, instead of its level's. */
  font_size?: number;
};

export type TextBlock = {
  type: "text";
  id: string;
  content: RichTextDoc;
  align: DesignAlignment;
  font_size: number;
  color?: string;
};

export type ButtonBlock = {
  type: "button";
  id: string;
  label: string;
  url: string;
  align: DesignAlignment;
  background_color?: string;
  text_color?: string;
  radius: number;
  /** A secondary button takes the design's secondary colors instead of its accent. */
  variant?: ButtonVariant;
};

export type ButtonVariant = "primary" | "secondary";

/** Image blocks reference a stored asset by id. Uploads arrive in a later slice; existing ones round-trip. */
export type ImageBlock = {
  type: "image";
  id: string;
  asset_id: string;
  alt: string;
  width: number;
  align: DesignAlignment;
  link_url?: string | null;
};

export type DividerBlock = { type: "divider"; id: string; color?: string; thickness: number };

export type SpacerBlock = { type: "spacer"; id: string; height: number };

export type FooterBlock = {
  type: "footer";
  id: string;
  content: RichTextDoc;
  align: DesignAlignment;
  font_size: number;
  color?: string;
};

/** One line of a rows block: a title, and a description under it. */
export type RowsItem = { title: string; description?: string };

/** Title and description lines — a quotation's items, a package's features — optionally ruled apart. */
export type RowsBlock = {
  type: "rows";
  id: string;
  items: RowsItem[];
  align: DesignAlignment;
  dividers: boolean;
  title_color?: string;
  description_color?: string;
  divider_color?: string;
};

/** One call to action in an actions row: a button (primary or secondary) or a link. */
export type ActionItem =
  | { kind: "button"; label: string; url: string; variant?: ButtonVariant }
  | { kind: "link"; label: string; url: string };

/** Calls to action side by side — a button beside a "See more ›" link, two buttons — wrapping on a narrow screen. */
export type ActionsBlock = {
  type: "actions";
  id: string;
  items: ActionItem[];
  align: DesignAlignment;
  /** The corner radius of every button in the row. */
  radius: number;
};

/**
 * A framed panel holding its own blocks (CARD_BLOCKS: never a card, never an image). Its frame — background,
 * border, corners, padding — is the design's; a card may set its own two colors.
 */
export type CardBlock = {
  type: "card";
  id: string;
  blocks: DesignBlock[];
  align: DesignAlignment;
  eyebrow?: string | null;
  title?: string | null;
  background_color?: string;
  border_color?: string;
};

export type DesignBlock =
  | LogoBlock
  | HeadingBlock
  | TextBlock
  | ButtonBlock
  | ImageBlock
  | DividerBlock
  | SpacerBlock
  | FooterBlock
  | CardBlock
  | RowsBlock
  | ActionsBlock;

export type DesignBlockType = DesignBlock["type"];

/** The parts of an email that hold blocks: a design's header and footer, and a template's content. */
export type DesignRegion = "header" | "footer" | "blocks";

/**
 * A design's signature area. The sender's name is filled in when the email is rendered; the optional switches add
 * the organization's name, its CRM mailbox and the sending person's own phone number.
 */
export type SignatureArea = {
  enabled: boolean;
  sign_off: string;
  align: DesignAlignment;
  show_company?: boolean;
  show_email?: boolean;
  show_phone?: boolean;
};

export type SignatureOption = "show_company" | "show_email" | "show_phone";

export type EmailDesignLayout = {
  version: 1;
  styles: DesignStyles;
  header: DesignBlock[];
  signature: SignatureArea;
  footer: DesignBlock[];
};

export type TemplateContent = {
  version: 1;
  blocks: DesignBlock[];
  /** The inbox preview line shown beside the subject. Placeholders allowed. */
  preheader?: string;
  /** Replaces the design's sign-off for this template; an empty string shows no sign-off line. */
  sign_off?: string;
  style_overrides?: DesignStyles;
};

/** A reusable email design, as GET /settings/email-designs returns it. */
export type EmailDesign = {
  id: string;
  name: string;
  isDefault: boolean;
  layout: EmailDesignLayout;
  templatesCount: number;
  createdAt: string | null;
  updatedAt: string | null;
};

/** POST …/preview → `data`: the server's rendering with sample CRM data. */
export type EmailDesignPreview = {
  subject: string;
  html: string;
  text: string;
  htmlBytes: number;
  maxHtmlBytes: number;
};

/** What the template content editor opens with: a saved designed template, a new one, or a converted plain one. */
export type TemplateEditorSeed = {
  purpose: string;
  tone: string;
  subject: string;
  isActive: boolean;
  content: TemplateContent;
  /** The design the template is shown in; null means the organization's default. */
  emailDesignId: string | null;
};

/** A server validation message placed on the block field it belongs to. */
export type DesignProblem = { field: string; message: string };

export type DesignProblems = {
  /** Problems with the document as a whole (size, no readable content, version). */
  general: string[];
  /** Problems with document-level fields, keyed like `styles.font`, `signature.sign_off` or `preheader`. */
  fields: Record<string, string>;
  /** Keyed by block id, so a message stays on its block after the list is reordered. */
  blocks: Record<string, DesignProblem[]>;
};

/** An email asset as Settings shows it. `url` is the public PNG email clients receive; a design never stores it. */
export type EmailAsset = {
  id: string;
  /** What was uploaded. An SVG is kept as vector on the server; every email still gets the PNG. */
  sourceFormat: "png" | "svg";
  url: string;
  /** The PNG's size in pixels. */
  width: number;
  height: number;
  bytes: number;
  originalName: string | null;
  /** What the SVG sanitizer removed, by kind — empty for a PNG or a clean SVG. */
  removed: Record<string, number>;
  createdAt: string | null;
};

/** GET /settings/email-branding → `data`. Every design's logo block shows `logo`. */
export type EmailBranding = { logo: EmailAsset | null; updatedAt: string | null };

/** POST /settings/email-branding/logo/inspect → `data`: an upload checked by the server, before it is cropped. */
export type LogoInspection = {
  format: "png" | "svg";
  /** Pixels for a PNG; user units of the sanitized viewBox for an SVG. */
  width: number;
  height: number;
  /** The SERVER-SANITIZED SVG — what is shown, cropped and drawn. Never the file as uploaded. */
  svg?: string;
  removed?: Record<string, number>;
};

/** GET /settings/email-designs/apply-to-all-summary → `data`: what "Apply to all templates" would change. */
export type ApplyToAllSummary = {
  designedTemplates: number;
  alreadyUsing: number;
  switching: number;
  plainTextTemplates: number;
};
