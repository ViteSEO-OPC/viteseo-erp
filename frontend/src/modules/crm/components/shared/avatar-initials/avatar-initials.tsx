import Image from "next/image";
import { cn } from "@/modules/crm/lib/utils";

type AvatarInitialsProps = {
  src?: string;
  initials: string;
  size?: "sm" | "md";
};

const sizeMap = {
  sm: "h-8 w-8 text-xs",
  md: "h-10 w-10 text-sm",
};

export function AvatarInitials({ src, initials, size = "sm" }: AvatarInitialsProps) {
  const fallback = initials.slice(0, 2).toUpperCase();

  if (src) {
    return (
      <Image
        src={src}
        alt={initials}
        width={32}
        height={32}
        className={cn("rounded-full object-cover", sizeMap[size])}
      />
    );
  }

  return (
    <span
      className={cn(
        "inline-flex items-center justify-center rounded-full bg-primary font-semibold text-card-foreground",
        sizeMap[size],
      )}
      aria-label={initials}
    >
      {fallback}
    </span>
  );
}
