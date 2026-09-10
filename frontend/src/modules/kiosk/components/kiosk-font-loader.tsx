"use client";

import { useMemo } from "react";

import { useKioskCatalog } from "../hooks";

/**
 * Loads whatever Google Fonts the brand kits reference — so the team can use ANY
 * Google Font in a kit just by naming it, and the preview renders it for real.
 * Builds one css2 request from the distinct heading/body families in the
 * catalog. If the request is blocked, the preview falls back to system fonts
 * (see fontStack in kiosk-preview). React 19 hoists + dedupes these <link>s.
 */
export function KioskFontLoader() {
  const { data } = useKioskCatalog();

  const href = useMemo(() => {
    const families = new Set<string>();
    for (const kit of data?.brand_kits ?? []) {
      if (kit.fonts.heading) families.add(kit.fonts.heading.trim());
      if (kit.fonts.body) families.add(kit.fonts.body.trim());
    }
    // Also load the typography-pairing fonts so the Design step can render each
    // typography option's label in its real font (live preview).
    for (const typo of data?.typographies ?? []) {
      if (typo.heading) families.add(typo.heading.trim());
      if (typo.body) families.add(typo.body.trim());
    }
    if (families.size === 0) return null;

    // Only weights (400/700) that virtually every Google Font ships, so one bad
    // family can't 400 the whole stylesheet.
    const params = [...families]
      .map((f) => `family=${f.replace(/\s+/g, "+")}:wght@400;700`)
      .join("&");
    return `https://fonts.googleapis.com/css2?${params}&display=swap`;
  }, [data]);

  if (!href) return null;

  return (
    <>
      <link rel="preconnect" href="https://fonts.googleapis.com" />
      <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
      <link rel="stylesheet" href={href} />
    </>
  );
}
