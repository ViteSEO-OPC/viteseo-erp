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
      className={cn("flex-col sm:flex-row", className)}
      // Inner wrapper so multiple buttons wrap onto a second line at 320px
      // rather than overflowing (core's own actions slot doesn't wrap).
      actions={
        actions ? (
          <div className="flex flex-wrap items-center gap-2">{actions}</div>
        ) : undefined
      }
    />
  );
}
