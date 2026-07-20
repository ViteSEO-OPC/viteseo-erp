import { PageHeader as CorePageHeader } from "@/core/components/page-header";
import { cn } from "@/lib/utils";

/**
 * TeamOra's page header — the core one with responsive stacking added.
 *
 * `core/components/page-header` lays the title and its `actions` slot out as a
 * single non-wrapping row, so on narrow screens the action buttons get pushed
 * off the right edge (this affected ~30 TeamOra pages — "New requisition" on
 * Recruitment was the visible symptom). Below `sm` the actions drop onto their
 * own line beneath the title instead.
 *
 * This wraps rather than forks: the markup still comes from core, so nav/title
 * changes there flow through. It lives in `modules/hr/` only to keep the shared
 * component untouched for the other pods — the fix arguably belongs in core, and
 * this file should collapse into it if that ever gets approved.
 */
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
