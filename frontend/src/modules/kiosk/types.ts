// Kiosk catalog types — mirror App\Modules\Kiosk\Support\KioskCatalog.

export interface KioskBusinessType {
  key: string;
  label: string;
  description: string;
}

export interface KioskWebsiteType {
  key: string;
  label: string;
  description: string;
}

export interface KioskSectionVariant {
  key: string;
  label: string;
}

/** A fixed single-select slot (header / hero / footer) with layout variants. */
export interface KioskSectionSlot {
  slot: string;
  label: string;
  variants: KioskSectionVariant[];
}

/** A toggleable content section (About, Services, …), each with layout variants. */
export interface KioskPageSection {
  key: string;
  label: string;
  variants: KioskSectionVariant[];
}

export interface KioskBrandKit {
  key: string;
  label: string;
  palette: { primary: string; accent: string; bg: string; text: string };
  fonts: { heading: string; body: string };
  /** true → a dark-surface theme. */
  dark?: boolean;
}

/** A heading + body font pairing, chosen independently of colour. */
export interface KioskTypography {
  key: string;
  label: string;
  heading: string;
  body: string;
}

/** Overall type-size scale (compact / standard / spacious). */
export interface KioskTypeScale {
  key: string;
  label: string;
  description: string;
}

/** Design style / vibe (clean / playful). */
export interface KioskDesignStyle {
  key: string;
  label: string;
  description: string;
}

/** Heading treatment (standard / handwritten). */
export interface KioskHeadingStyle {
  key: string;
  label: string;
  description: string;
}

/** Corner style / border-radius personality (sharp / soft / pill). */
export interface KioskRadius {
  key: string;
  label: string;
  description: string;
}

/** CTA button hover effect (none / wipe / flip / gradient / glow / shine). */
export interface KioskCtaHover {
  key: string;
  label: string;
  description: string;
}

export interface KioskCatalog {
  business_types: KioskBusinessType[];
  website_types: KioskWebsiteType[];
  sections: KioskSectionSlot[];
  page_sections: KioskPageSection[];
  brand_kits: KioskBrandKit[];
  typographies: KioskTypography[];
  type_scales: KioskTypeScale[];
  styles: KioskDesignStyle[];
  heading_styles: KioskHeadingStyle[];
  radii: KioskRadius[];
  cta_hovers: KioskCtaHover[];
  page_blueprints: KioskPageBlueprint[];
}

/** One chosen content section + its layout variant. `id` is a client-side
 *  instance id (stable React key + drag/duplicate); optional in stored data. */
export interface KioskPageChoice {
  id?: string;
  section: string;
  variant: string;
}

/** A single page of the site — its own optional hero + ordered content sections.
 *  Header + footer are site-wide (see KioskSectionChoice). */
export interface KioskSitePage {
  id?: string;
  name: string;
  slug: string;
  hero: string | null;
  sections: KioskPageChoice[];
}

/** The chosen building blocks — site-wide header/footer + a list of pages. */
export interface KioskSectionChoice {
  header: string | null;
  footer: string | null;
  pages: KioskSitePage[];
}

/** An addable page template (Home / About / Contact / …) from the catalog. */
export interface KioskPageBlueprint {
  key: string;
  name: string;
  slug: string;
  hero: string | null;
  sections: KioskPageChoice[];
}

/** Payload to save a selection (POST /kiosk/selections). */
export interface KioskSelectionInput {
  prospect_name: string;
  prospect_contact?: string;
  notes?: string;
  business_type: string;
  website_type: string;
  brand_kit: string;
  typography?: string;
  type_scale?: string;
  design_style?: string;
  heading_style?: string;
  radius?: string;
  cta_hover?: string;
  /** Prospect logo as a base64 data:image URI (optional). */
  logo?: string;
  sections: KioskSectionChoice;
}

/** A temp client access grant (24h demo login). */
export interface KioskAccessGrant {
  id: number;
  label: string;
  email: string | null;
  status: "active" | "expired" | "revoked";
  expires_at: string | null;
  created_at: string | null;
}

/** Freshly-created credentials — the password is returned ONCE. */
export interface KioskAccessCredentials {
  id: number;
  label: string;
  email: string;
  password: string;
  expires_at: string | null;
}

/** A saved selection (a captured lead). */
export interface KioskSelection {
  id: number;
  prospect_name: string;
  prospect_contact: string | null;
  notes: string | null;
  business_type: string;
  business_type_label: string;
  website_type: string;
  website_type_label: string;
  sections: Partial<KioskSectionChoice>;
  brand_kit: string;
  typography: string | null;
  type_scale: string | null;
  design_style: string | null;
  heading_style: string | null;
  radius: string | null;
  cta_hover: string | null;
  logo: string | null;
  created_by: string | null;
  created_at: string | null;
}
