import { ChevronRight } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatPeso } from "@/core/lib/format";
import type { MyBenefit } from "@/modules/hr/types/benefits";
import { benefitVisual, isEnrolledStatus } from "@/modules/hr/lib/benefit-visuals";

/**
 * The employee-facing benefit card (My Benefits page).
 *
 * SLICE B12 — IT NOW SAYS WHAT THE BENEFIT IS WORTH. It used to render a name, an icon, a badge
 * and three HR-authored fields that are empty on every programme, so an employee's only benefits
 * screen told them less than their payslip did.
 *
 * THEIR FIGURE, NOT THE COMPANY'S. Where someone has their own allowance amount (B14), this shows
 * what they are paid and never what the standard is — that comparison is a conversation with HR,
 * not something a self-service page should publish.
 */
export function BenefitCard({ benefit }: { benefit: MyBenefit }) {
  const { Icon, swatch } = benefitVisual(benefit.category);
  const enrolled = isEnrolledStatus(benefit.status);
  const isAllowance = benefit.kind === "allowance";
  const share = benefit.employee_share !== null ? Number(benefit.employee_share) : null;

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
            enrolled ? "bg-success-subtle text-success" : "bg-muted text-muted-foreground",
          )}
        >
          {benefit.status}
        </span>
      </div>

      {/* The headline figure. An allowance is money added to pay; a plan's headline is what it
          costs the employee, because that is the part that reaches their payslip. */}
      {isAllowance ? (
        <div className="mt-4">
          <p className="text-3xl font-bold text-foreground">
            {formatPeso(benefit.monthly_amount ?? 0)}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            Per month · added to your pay ·{" "}
            {benefit.tax_treatment === "taxable" ? (
              <span className="font-medium text-warning">taxed as compensation</span>
            ) : (
              "tax-free"
            )}
          </p>
        </div>
      ) : (
        <div className="mt-4">
          <p className="text-3xl font-bold text-foreground">
            {share && share > 0 ? formatPeso(share) : "No cost to you"}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {share && share > 0
              ? "Your share, deducted from your pay"
              : "Covered by the company"}
            {benefit.tier && ` · ${benefit.tier}`}
          </p>
        </div>
      )}

      {benefit.description && (
        <p className="mt-3 text-sm text-muted-foreground">{benefit.description}</p>
      )}

      {/* HR-authored extras. Kept, but no longer the only thing on the card. */}
      {benefit.details.length > 0 && (
        <dl className="mt-4 space-y-2.5">
          {benefit.details.map((d, i) => (
            <div key={i} className="flex items-center justify-between gap-4 text-sm">
              <dt className="text-muted-foreground">{d.label}</dt>
              <dd className="text-right font-medium text-foreground">{d.value}</dd>
            </div>
          ))}
        </dl>
      )}

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
