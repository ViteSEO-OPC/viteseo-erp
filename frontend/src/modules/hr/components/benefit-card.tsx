import { ChevronRight } from "lucide-react";

import { cn } from "@/lib/utils";
import type { MyBenefit } from "@/modules/hr/types/benefits";
import { benefitVisual, isEnrolledStatus } from "@/modules/hr/lib/benefit-visuals";

/**
 * The employee-facing benefit card (My Benefits page). Purely presentational —
 * every value is authored by HR, so we render the stored strings as-is.
 */
export function BenefitCard({ benefit }: { benefit: MyBenefit }) {
  const { Icon, swatch } = benefitVisual(benefit.category);
  const enrolled = isEnrolledStatus(benefit.status);

  return (
    <div className="flex flex-col rounded-card border border-border bg-card p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className={cn("flex size-10 items-center justify-center rounded-lg", swatch)}>
            <Icon className="size-5" />
          </span>
          <h3 className="text-h3 font-bold text-foreground">{benefit.name}</h3>
        </div>
        <span
          className={cn(
            "shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold",
            enrolled
              ? "bg-success-subtle text-success"
              : "bg-muted text-muted-foreground",
          )}
        >
          {benefit.status}
        </span>
      </div>

      <dl className="mt-4 space-y-2.5">
        {benefit.details.map((d, i) => (
          <div key={i} className="flex items-center justify-between gap-4 text-sm">
            <dt className="text-muted-foreground">{d.label}</dt>
            <dd className="text-right font-medium text-foreground">{d.value}</dd>
          </div>
        ))}
      </dl>

      {benefit.progress && (
        <div className="mt-4">
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
              className="h-full rounded-full bg-warning"
              style={{ width: `${Math.min(100, Math.max(0, benefit.progress.percent))}%` }}
            />
          </div>
          {benefit.progress.caption && (
            <p className="mt-1.5 text-caption text-muted-foreground">
              {benefit.progress.caption}
            </p>
          )}
        </div>
      )}

      {benefit.footer_label && (
        <div className="mt-4 flex items-center justify-between border-t border-border pt-3 text-sm font-medium text-brand">
          <span>{benefit.footer_label}</span>
          <ChevronRight className="size-4" />
        </div>
      )}
    </div>
  );
}
