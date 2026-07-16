import { LeadsPriorityCard } from "@/modules/crm/features/leads/components/leads-priority-card";
import { PrioritySkeleton } from "@/modules/crm/features/dashboard/components/priority-skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
// Leads-page Priority Summary cards: High/Medium/Low counts aggregated from leads.priority
// (GET /leads/priority-summary) — the SAME column the table's Priority badge reads, so the cards
// and the table can never disagree. Filter-aware via `filters` (the current table filters minus
// priority), so the cards describe the same dataset the table shows.
import { useLeadsPrioritySummary } from "@/modules/crm/features/leads/hooks/use-leads-priority-summary";

type Props = {
  filters: Record<string, string>;
};

export function LeadsKpiRow({ filters }: Props) {
  const { data, isLoading, isError } = useLeadsPrioritySummary(filters);

  if (isLoading) return <PrioritySkeleton />;
  if (isError) return <ErrorState message="Failed to load priority summary" />;
  if (!data) return null;
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      {data.map((bucket) => (
        <LeadsPriorityCard key={bucket.priority} bucket={bucket} />
      ))}
    </div>
  );
}
