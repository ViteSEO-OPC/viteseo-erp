"use client";

import { useEffect } from "react";

/**
 * Applies the module's shape scale (DESIGN-notion.md § Shapes) for as long as a
 * Projects route is mounted.
 *
 * Renders nothing. It sets `data-design="paper"` on <html> rather than on a
 * wrapper element because Radix portals every dialog, dropdown and popover to
 * document.body — a scope anywhere inside the shell would re-skin the page and
 * leave the modals on the platform radius.
 *
 * Removing it on unmount is what keeps this out of HR and CRM: a client-side
 * navigation from a Projects route into either of those tears this down, and
 * their radii go back to the platform's 10px.
 */
export function PaperTheme(): null {
  useEffect(() => {
    const root = document.documentElement;
    root.dataset.design = "paper";
    return () => {
      delete root.dataset.design;
    };
  }, []);

  return null;
}
