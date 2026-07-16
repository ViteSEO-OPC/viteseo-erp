import { cn } from "@/modules/crm/lib/utils";

/**
 * Visible marker for a presentation-only widget whose real data does not yet exist (it awaits
 * the KPI Engine and its upstream data providers). It renders sample content **honestly** — the
 * badge makes clear the numbers are not live, so no one mistakes demo data for computed metrics.
 *
 * See `BD-PERF-FE-ARCH-REVIEW §10` (Demo / placeholder classification). No business rule or
 * computation lives behind this badge; it is a labeling affordance only.
 */
export function DemoBadge({ className }: { className?: string }) {
  return (
    <span
      title="Sample data — populated by the KPI Engine"
      className={cn(
        "inline-flex shrink-0 items-center rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700",
        className,
      )}
    >
      Demo
    </span>
  );
}
