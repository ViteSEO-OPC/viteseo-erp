import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";

type SectionHeaderProps = {
  title: string;
  linkText?: string;
  linkHref?: string;
  className?: string;
};

export function SectionHeader({
  title,
  linkText,
  linkHref,
  className,
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
          className="text-sm font-medium text-primary-accent transition-colors hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {linkText} →
        </Link>
      )}
    </div>
  );
}
