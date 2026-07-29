"use client";

import { useState } from "react";

import { cn } from "@/lib/utils";
import { Avatar } from "@/core/components/avatar";

const SIZES = {
  sm: "size-8",
  md: "size-10",
  lg: "size-20",
};

/**
 * An employee's face: their uploaded photo when there is one, otherwise the shared
 * initials avatar. A photo that fails to load (moved or deleted file) falls back to
 * initials too, so the layout never shows a broken image.
 */
export function EmployeeAvatar({
  name,
  photoUrl,
  colorSeed,
  size = "md",
  className,
}: {
  name: string;
  photoUrl?: string | null;
  /** Stable seed (e.g. employee id) so the fallback color doesn't change between views. */
  colorSeed?: number;
  size?: keyof typeof SIZES;
  className?: string;
}) {
  const [broken, setBroken] = useState(false);

  if (!photoUrl || broken) {
    return <Avatar name={name} colorSeed={colorSeed} size={size} className={className} />;
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element -- served from the API origin, not an optimised Next asset
    <img
      src={photoUrl}
      alt={name}
      onError={() => setBroken(true)}
      className={cn(
        "shrink-0 rounded-full border border-border object-cover",
        SIZES[size],
        className,
      )}
    />
  );
}
