import { cn } from "@/lib/utils";
import { Eyebrow } from "@/core/components/editorial";

/**
 * Standard page title block: big bold heading + optional gray subtitle line
 * (used for the date / context line on dashboards). Right-side `actions` slot
 * holds buttons like "Add Department".
 */
export function PageHeader({
  title,
  subtitle,
  eyebrow,
  actions,
  className,
}: {
  title: string;
  subtitle?: string;
  /** Small label above the title — the section this page belongs to. */
  eyebrow?: string;
  actions?: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex items-start justify-between gap-4", className)}>
      <div>
        {eyebrow ? <Eyebrow className="mb-1.5">{eyebrow}</Eyebrow> : null}
        <h1 className="text-h1 font-bold tracking-tight text-foreground">
          {title}
        </h1>
        {subtitle ? (
          <p className="mt-1 text-caption text-muted-foreground">{subtitle}</p>
        ) : null}
      </div>
      {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
    </div>
  );
}
