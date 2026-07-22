import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";

type SectionHeaderProps = {
  title: string;
  linkText?: string;
  linkHref?: string;
  className?: string;
  /**
   * Optional override for the link's colour. Defaults to `text-primary-accent` so every existing
   * caller is unchanged; a caller may pass e.g. `text-dashboard-link` to tint just its own link.
   */
  linkClassName?: string;
};

export function SectionHeader({
  title,
  linkText,
  linkHref,
  className,
  linkClassName,
}: SectionHeaderProps) {
  return (
    <div
      className={cn(
        "mb-4 flex items-center justify-between",
        className,
      )}
    >
      <h2 className="text-section font-bold text-card-foreground">
        {title}
      </h2>
      {linkText && linkHref && (
        <Link
          href={linkHref}
          className={cn(
            "text-sm font-medium transition-colors hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            linkClassName ?? "text-primary-accent",
          )}
        >
          {linkText} →
        </Link>
      )}
    </div>
  );
}
