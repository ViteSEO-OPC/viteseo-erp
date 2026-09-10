import { cn } from "@/lib/utils";
import { initials } from "@/core/nav";

// Categorical avatar colors (like chart series) — picked deterministically so a
// person keeps the same color everywhere.
const AVATAR_COLORS = [
  "bg-brand text-brand-foreground",
  "bg-info text-white",
  "bg-warning text-white",
  "bg-danger text-white",
  "bg-success text-white",
  "bg-teal-ink text-white",
];

const SIZES = {
  sm: "size-8 text-xs",
  md: "size-10 text-sm",
  lg: "size-20 text-2xl",
};

export function Avatar({
  name,
  colorSeed,
  size = "md",
  className,
}: {
  name: string;
  /** Stable seed (e.g. employee id) so the color doesn't change between views. */
  colorSeed?: number;
  size?: keyof typeof SIZES;
  className?: string;
}) {
  const seed =
    colorSeed ??
    name.split("").reduce((acc, ch) => acc + ch.charCodeAt(0), 0);
  const color = AVATAR_COLORS[seed % AVATAR_COLORS.length];

  return (
    <span
      className={cn(
        "inline-flex shrink-0 items-center justify-center rounded-full font-semibold",
        SIZES[size],
        color,
        className,
      )}
    >
      {initials(name)}
    </span>
  );
}
