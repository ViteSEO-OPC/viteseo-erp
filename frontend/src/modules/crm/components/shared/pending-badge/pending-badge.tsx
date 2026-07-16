import { cn } from "@/modules/crm/lib/utils";

/**
 * Honest status marker for a widget whose metric depends on a backend module that is not yet
 * available (e.g. a Follow-up / Appointment / Planner module, or a personal-target configuration).
 * Unlike a Demo badge, it shows **no sample numbers** — the widget renders a "Pending …" state and
 * no value is fabricated. The `reason` is surfaced as the tooltip so the specific dependency is clear.
 *
 * Neutral slate tone (distinct from the amber Demo badge it replaces) — this is a real, blocked
 * metric awaiting a dependency, not illustrative sample data.
 */
export function PendingBadge({
  reason,
  className,
}: {
  reason?: string;
  className?: string;
}) {
  return (
    <span
      title={reason ?? "Pending — awaiting a backend dependency"}
      className={cn(
        "inline-flex shrink-0 items-center rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600",
        className,
      )}
    >
      Pending
    </span>
  );
}
