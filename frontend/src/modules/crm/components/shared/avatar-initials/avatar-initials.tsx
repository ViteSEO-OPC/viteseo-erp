"use client";

import { useCallback, useState } from "react";
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
 * A failed load — an expired link, or a photo whose file is gone — falls back to initials rather than
 * leaving a broken-image glyph in the header.
 *
 * ── Why the failure is not handled with an `onError` prop ──
 * React sets an `<img>`'s `src` while RENDERING, on an element not yet in the page, and the browser
 * starts fetching at that moment. A URL that fails fast (a 404 answers in milliseconds) can fire
 * `error` before React has committed this component, and `onError={() => setBroken(true)}` then
 * updates a component that has not mounted — React's "Can't perform a React state update on a
 * component that hasn't mounted yet" warning, raised from the topbar on every CRM page for an
 * account whose stored photo is missing.
 *
 * So the image is watched from a ref callback instead, which React only calls once the element is
 * committed to the page. A failure that already happened is read from the element itself
 * (`complete` with no intrinsic width means the request is broken); anything later arrives through
 * the listener. The fallback is exactly what it was — only WHEN the state is set has changed.
 */
export function AvatarInitials({ src, initials, size = "sm" }: AvatarInitialsProps) {
  const [broken, setBroken] = useState(false);
  const fallback = initials.slice(0, 2).toUpperCase();

  // Stable on purpose: a new function identity would make React detach and re-attach the ref on
  // every render. The listener stays on the element when only `src` changes, so a later failure is
  // still heard.
  const watchForFailure = useCallback((img: HTMLImageElement | null) => {
    if (!img) return;

    const markBroken = () => setBroken(true);

    if (img.complete && img.naturalWidth === 0) {
      markBroken();
      return;
    }

    img.addEventListener("error", markBroken);
    return () => img.removeEventListener("error", markBroken);
  }, []);

  if (src && !broken) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- signed URL from the API origin, not an optimised Next asset
      <img
        ref={watchForFailure}
        src={src}
        alt={initials}
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
