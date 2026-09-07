import type { KioskTheme } from "./site-builder";
import type { KioskCatalog } from "./types";

export interface ThemePicks {
  brand_kit: string | null;
  typography?: string | null;
  type_scale?: string | null;
  design_style?: string | null;
  heading_style?: string | null;
  radius?: string | null;
  cta_hover?: string | null;
}

/**
 * Resolve the four independent design picks (colour kit + typography + size +
 * style) into a single theme for the site builder. Typography falls back to the
 * colour kit's own pairing; size/style default to standard/clean.
 */
export function resolveTheme(catalog: KioskCatalog | undefined, picks: ThemePicks): KioskTheme | null {
  if (!catalog) return null;
  const kit = catalog.brand_kits.find((k) => k.key === picks.brand_kit);
  if (!kit) return null;
  const typo = catalog.typographies.find((t) => t.key === picks.typography);
  return {
    palette: kit.palette,
    fonts: typo ? { heading: typo.heading, body: typo.body } : kit.fonts,
    scale: picks.type_scale || "standard",
    style: picks.design_style || "clean",
    headingStyle: picks.heading_style || "standard",
    radius: picks.radius || "soft",
    ctaHover: picks.cta_hover || "wipe",
    dark: kit.dark ?? false,
  };
}
