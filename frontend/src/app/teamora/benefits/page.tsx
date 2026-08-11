"use client";

import { PageHeader } from "@/modules/hr/components/page-header";
import { BenefitCard } from "@/modules/hr/components/benefit-card";
import { GovernmentContributionCard } from "@/modules/hr/components/government-contribution-card";
import { useMyBenefits } from "@/modules/hr/hooks/use-benefit-programs";
import { useContributionSettings } from "@/modules/hr/hooks/use-benefits";

/**
 * The employee's own benefits, in the same two sections HR sees on Company → Benefits:
 * the statutory contributions first, then the programs they are enrolled in.
 *
 * DISPLAY ONLY. The government card is the same component HR uses, minus the edit action — the
 * rates are legislation and system-managed, and there is nothing here an employee may change.
 * Sharing the component rather than copying it is deliberate: the employee is being shown the
 * rate that produced the deduction on their own payslip, so a second copy of that markup would
 * eventually disagree with HR's.
 */
export default function BenefitsPage() {
  const { data: benefits, isLoading, isError } = useMyBenefits();
  const { data: contributions } = useContributionSettings();

  const list = benefits ?? [];
  const govSettings = contributions ?? [];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="My Benefits"
        subtitle="Your statutory contributions and the programs you're enrolled in."
      />

      <div className="mt-8 space-y-10">
        {/* Government mandated — mirrors the HR screen, without the edit action. */}
        <section className="space-y-3">
          <div className="flex items-center gap-2">
            <h2 className="text-h3 font-bold text-foreground">Government mandated</h2>
            <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">
              System managed
            </span>
          </div>
          {govSettings.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Contribution rates are not configured yet.
            </p>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {govSettings.map((s) => (
                <GovernmentContributionCard key={s.id} setting={s} />
              ))}
            </div>
          )}
          <p className="text-caption text-muted-foreground">
            Set by law and deducted automatically. These are the rates behind the SSS, PhilHealth
            and Pag-IBIG lines on your payslip.
          </p>
        </section>

        {/* Allowances and plans — what HR has enrolled this person in. */}
        <section className="space-y-3">
          <h2 className="text-h3 font-bold text-foreground">Allowances &amp; benefits</h2>

          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading your benefits…</p>
          ) : isError ? (
            <p className="text-sm text-destructive">
              Could not load your benefits. Please refresh.
            </p>
          ) : list.length === 0 ? (
            <div className="rounded-card border border-dashed border-border bg-card p-10 text-center">
              <p className="text-sm font-medium text-foreground">
                You&apos;re not enrolled in any benefit programs yet.
              </p>
              <p className="mt-1 text-caption text-muted-foreground">
                When HR enrols you in a plan, it will appear here.
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              {list.map((b) => (
                <BenefitCard key={b.id} benefit={b} />
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  );
}
