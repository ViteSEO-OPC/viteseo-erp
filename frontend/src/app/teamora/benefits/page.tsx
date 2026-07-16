"use client";

import { Headset, Info } from "lucide-react";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { BenefitCard } from "@/modules/hr/components/benefit-card";
import { useMyBenefits } from "@/modules/hr/hooks/use-benefit-programs";

export default function BenefitsPage() {
  const { data: benefits, isLoading, isError } = useMyBenefits();
  const list = benefits ?? [];

  return (
    <div className="p-8">
      <PageHeader
        title="My Benefits"
        subtitle="Overview of your active insurance and allowance programs."
      />

      {/* Open-enrolment notice */}
      <div className="mt-6 flex items-start gap-3 rounded-card border border-brand/40 bg-brand-subtle px-4 py-3">
        <Info className="mt-0.5 size-4 shrink-0 text-brand" />
        <p className="text-sm text-foreground">
          Your enrolled plans are shown below. Changes can only be made during open
          enrollment (Nov 1–30, 2026).
        </p>
      </div>

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
              Need help with your benefits?
            </p>
            <p className="text-caption text-muted-foreground">
              Our support team is available Mon–Fri, 9am – 6pm PHT.
            </p>
          </div>
        </div>
        <Button asChild variant="outline">
          <a href="mailto:hr@viteseo.ph?subject=Benefits%20question">Contact Benefits HR</a>
        </Button>
      </div>
    </div>
  );
}
