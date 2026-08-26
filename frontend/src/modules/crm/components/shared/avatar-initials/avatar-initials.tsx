"use client";

import { useState } from "react";
import { cn } from "@/modules/crm/lib/utils";

type AvatarInitialsProps = {
  src?: string | null;
  initials: string;
  size?: "sm" | "md";
};

const sizeMap = {
  sm: "h-8 w-8 text-xs",
  md: "h-10 w-10 text-sm",
};

/**
 * Initials avatar that shows a photo when there is one.
 *
 * The image is a plain `<img>`, not `next/image`, and that is deliberate: the src is a short-lived
 * SIGNED URL served from the API origin, so it is neither a static asset nor a stable remote pattern
 * Next could be configured for — running it through the optimiser would mean caching a link that
 * expires. Same call the HR employee avatar already makes.
 *
 * `onError` is what makes an expired or unreachable link harmless: it falls back to initials rather
 * than leaving a broken-image glyph in the header.
 */
export function AvatarInitials({ src, initials, size = "sm" }: AvatarInitialsProps) {
  const [broken, setBroken] = useState(false);
  const fallback = initials.slice(0, 2).toUpperCase();

  if (src && !broken) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- signed URL from the API origin, not an optimised Next asset
      <img
        src={src}
        alt={initials}
        onError={() => setBroken(true)}
        className={cn("shrink-0 rounded-full object-cover", sizeMap[size])}
      />
    );
  }

  return (
    <span
      className={cn(
        "inline-flex items-center justify-center rounded-full bg-primary font-semibold text-primary-foreground",
        sizeMap[size],
      )}
      aria-label={initials}
    >
      {fallback}
    </span>
  );
}
