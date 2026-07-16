import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";

type TextLinkProps = {
  href: string;
  text: string;
  className?: string;
};

export function TextLink({ href, text, className }: TextLinkProps) {
  return (
    <Link
      href={href}
      className={cn(
        "text-sm font-medium text-primary-accent transition-colors hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        className,
      )}
    >
      {text} →
    </Link>
  );
}
