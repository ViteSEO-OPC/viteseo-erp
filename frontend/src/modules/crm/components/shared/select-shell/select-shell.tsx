import type { ReactNode } from "react";
import { ChevronDown } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

/**
 * The dropdown indicator for NATIVE `<select>` fields — Zerem's single owner of that affordance.
 *
 * Every native select in the CRM is styled `appearance-none`, which removes the browser's own
 * arrow. Without a replacement the control renders pixel-identically to a read-only text input, so
 * a selectable field looks inert — the Edit Lead drawer's Website Status / Priority / Email
 * Outreach / Cold Calling / Lead Status all read as plain text for exactly this reason.
 *
 * The chevron matches the one `SelectTrigger` draws for the rich base-ui selects (`ChevronDown`,
 * `h-4 w-4`, `text-muted-foreground`), so the Business Niche selector — the visual reference — and
 * a native select present the same affordance despite being different primitives. It inherits the
 * `--muted-foreground` token, so light/dark are handled with no per-theme rule.
 *
 * `pointer-events-none` is load-bearing: the overlay sits ON TOP of the select, and without it a
 * click on the chevron would land on the div instead of opening the menu.
 *
 * Wrap a select — never an `<input>`. A chevron on a text or read-only field advertises a menu that
 * does not exist, which is the inverse of the bug this fixes.
 *
 * The select still owns its own right padding (`pr-9`); this only draws the glyph, so it adds no
 * height, margin or layout of its own beyond the relative positioning context.
 */
export function SelectShell({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("relative", className)}>
      {children}
      <ChevronDown
        className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden
      />
    </div>
  );
}
