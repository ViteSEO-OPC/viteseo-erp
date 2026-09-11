import { PageHeader as CorePageHeader } from "@/core/components/page-header";
import { cn } from "@/lib/utils";

/** TeamOra's page header — the core one with responsive stacking added. */
export function PageHeader({
  className,
  actions,
  ...props
}: React.ComponentProps<typeof CorePageHeader>) {
  return (
    <CorePageHeader
      {...props}
      /*
       * `items-stretch` below sm is what makes the wrap below WORK. Stacked, the core header is
       * `flex-col items-start`, which sizes each child to its own content — so the actions slot
       * was as wide as its buttons laid end to end, with no boundary to wrap against, and simply
       * ran off the side of a 320px screen. Stretched, it is the width of the page, and the
       * wrapper inside it wraps exactly as intended.
       */
      className={cn("flex-col items-stretch sm:flex-row sm:items-start", className)}
      // Inner wrapper so multiple buttons wrap onto a second line at 320px
      // rather than overflowing (core's own actions slot doesn't wrap).
      actions={
        actions ? (
          <div className="flex flex-wrap items-center gap-2 sm:justify-end">{actions}</div>
        ) : undefined
      }
    />
  );
}
