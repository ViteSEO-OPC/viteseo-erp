"use client";

import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";
import { useReportsAnalytics } from "@/modules/crm/features/reports/hooks/use-reports-analytics";

/**
 * Decline reason analysis — PENDING. The mockup breaks declined leads down by *why* (No Response / Not
 * Interested / No Budget / …), but the CRM does not yet capture a decline reason on the lead, so there is
 * no real breakdown to show. Per the frozen no-fabrication rule we render the section's slot with an
 * honest Pending state (and the real declined total from the status mix) rather than inventing reasons.
 */
export function DeclineReasonCard({ className }: { className?: string }) {
  const { data, isLoading, isError } = useReportsAnalytics();
  const declined = data?.statusMix?.declined ?? 0;

  return (
    <ChartCard
      title="Decline reason analysis"
      subtitle="Why did we lose them?"
      className={className}
      isLoading={isLoading}
      isError={isError}
      filter={<PendingBadge reason="Decline reasons aren't captured on the lead yet — awaiting a decline-reason field" />}
    >
      <div className="flex flex-col items-center justify-center gap-2 py-6 text-center">
        <span className="text-3xl font-extrabold tabular-nums text-red-500">{declined}</span>
        <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          Declined leads
        </span>
        <p className="max-w-[16rem] text-xs leading-relaxed text-muted-foreground">
          The reason breakdown becomes live once leads record a decline reason. No reasons are shown until
          they are captured.
        </p>
      </div>
    </ChartCard>
  );
}
