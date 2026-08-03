"use client";

import Link from "next/link";
import { Headset } from "lucide-react";

import { Button } from "@/components/ui/button";
import { formatPeso } from "@/core/lib/format";
import { PageHeader } from "@/modules/hr/components/page-header";
import { BenefitCard } from "@/modules/hr/components/benefit-card";
import { useMyBenefits } from "@/modules/hr/hooks/use-benefit-programs";

export default function BenefitsPage() {
  const { data: benefits, isLoading, isError } = useMyBenefits();
  const list = benefits ?? [];

  // Slice B12 — the figure an employee actually wants: what their allowances add up to, so they
  // can check it against the allowances line on their payslip. Company plans are excluded because
  // a plan is not money added to pay; its share is a deduction and is shown on its own card.
  const monthlyAllowances = list
    .filter((b) => b.kind === "allowance")
    .reduce((sum, b) => sum + (b.monthly_amount ?? 0), 0);

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="My Benefits"
        subtitle="Overview of your active insurance and allowance programs."
      />

      {/* Slice B12 — what the allowances are worth in total, which is the number that should
          match the payslip. The banner that used to sit here announced an open-enrolment window
          (Nov 1–30, 2026) that nothing in the system enforces and no screen can act on; a date
          invented in the markup is worse than no date. */}
      {monthlyAllowances > 0 && (
        <div className="mt-6 rounded-card border border-border bg-card px-4 py-3">
          <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
            Your allowances
          </p>
          <p className="mt-1 text-2xl font-bold text-foreground">
            {formatPeso(monthlyAllowances)}
            <span className="ml-1.5 text-sm font-normal text-muted-foreground">per month</span>
          </p>
          <p className="mt-1 text-caption text-muted-foreground">
            Added to your pay and spread across your payslips — this is the total behind the
            allowances line on each one.
          </p>
        </div>
      )}

      {/* Benefit cards */}
      {isLoading ? (
        <p className="mt-6 text-sm text-muted-foreground">Loading your benefits…</p>
      ) : isError ? (
        <p className="mt-6 text-sm text-destructive">
          Could not load your benefits. Please refresh.
        </p>
      ) : list.length === 0 ? (
        <div className="mt-6 rounded-card border border-dashed border-border bg-card p-10 text-center">
          <p className="text-sm font-medium text-foreground">
            You&apos;re not enrolled in any benefit programs yet.
          </p>
          <p className="mt-1 text-caption text-muted-foreground">
            When HR enrols you in a plan, it will appear here.
          </p>
        </div>
      ) : (
        <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
          {list.map((b) => (
            <BenefitCard key={b.id} benefit={b} />
          ))}
        </div>
      )}

      {/* Help footer */}
      <div className="mt-6 flex flex-col items-start justify-between gap-4 rounded-card border border-border bg-card p-5 sm:flex-row sm:items-center">
        <div className="flex items-center gap-3">
          <span className="flex size-10 items-center justify-center rounded-lg bg-brand-subtle text-brand">
            <Headset className="size-5" />
          </span>
          <div>
            <p className="text-sm font-bold text-foreground">
              Something look wrong?
            </p>
            <p className="text-caption text-muted-foreground">
              Amounts here are what payroll uses. Ask HR if one does not match your payslip.
            </p>
          </div>
        </div>
        {/* Slice B12 — raise it in the app, where it gets a record and a reply, rather than a
            mailto address hardcoded in the markup. Concerns already exist as a feature. */}
        <Button asChild variant="outline">
          <Link href="/teamora/concerns">Raise a concern</Link>
        </Button>
      </div>
    </div>
  );
}
