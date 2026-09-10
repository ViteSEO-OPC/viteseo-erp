"use client";

import { type ReactNode } from "react";

import { useTheme as usePlatformTheme } from "@/core/theme-provider";

/**
 * Zerem's theme wrapper — now a thin adapter over the PLATFORM theme.
 *
 * It used to own a second, independent theme: its own `zerem-theme` storage key,
 * its own state defaulting to "light", applied to this wrapper instead of <html>.
 * Two switches for one lamp, and they disagreed in both directions:
 *
 *  - **Arriving in dark, the toggle did nothing.** The platform had already put
 *    `.dark` on <html>, and the shared dark variant is `&:is(.dark *)` — every
 *    element inside is a descendant, so every `dark:` utility fired no matter what
 *    this wrapper said. Flipping the wrapper could not undo a class set two levels
 *    above it, so the button looked broken.
 *
 *  - **Arriving in light, the page flipped itself to dark.** This provider read
 *    `zerem-theme` from a previous visit and dressed its own subtree dark while the
 *    rest of the platform stayed light.
 *
 * So the state is gone and the platform is the only source of truth. What stays is
 * the WRAPPER: `styles/zerem-theme.css` keys on `.zerem-theme-scope[data-theme="dark"]`,
 * so the element still has to be here and still has to carry the attribute — it just
 * mirrors the platform now instead of competing with it.
 *
 * `useTheme()` keeps its shape, so the toggle, the settings page and the Nearby map
 * import the same thing they always did; they now read and write the platform theme.
 *
 * The old `zerem-theme` key is simply left behind in whatever browsers still hold
 * one. Nothing reads it any more.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const { theme } = usePlatformTheme();

  return (
    <div
      className={theme === "dark" ? "zerem-theme-scope dark" : "zerem-theme-scope"}
      data-theme={theme}
    >
      {children}
    </div>
  );
}

/** The platform theme, under the name the CRM already imports. */
export function useTheme() {
  const { theme, setTheme } = usePlatformTheme();

  return { theme, setTheme };
}
