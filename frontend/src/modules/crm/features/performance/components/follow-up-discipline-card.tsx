import { ChartCard } from "@/modules/crm/components/shared/chart-card/chart-card";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";

/**
 * Follow-up discipline — **Pending Planner integration.** Missed follow-ups, appointments, and calls
 * require the Planner module (implemented in a separate workstream) to expose missed/overdue events.
 * Those APIs are not available to this surface yet, so nothing is fabricated — the card shows a
 * Pending state until the Planner integration lands.
 */
export function FollowUpDisciplineCard({ className }: { className?: string }) {
  return (
    <ChartCard
      title="Follow-up discipline"
      subtitle="Missed follow-ups, appointments, and calls this month"
      filter={<PendingBadge reason="Pending Planner integration — missed-event data not yet available" />}
      className={className}
    >
      <div className="flex h-full min-h-[8rem] flex-col items-center justify-center gap-1 text-center">
        <p className="text-sm font-semibold text-card-foreground">Pending Planner integration</p>
        <p className="max-w-xs text-xs text-muted-foreground">
          Missed follow-ups, calls, and appointments will appear once the Planner module exposes
          overdue events.
        </p>
      </div>
    </ChartCard>
  );
}
