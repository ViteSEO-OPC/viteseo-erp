import { cn } from "@/modules/crm/lib/utils";
import type { PriorityLevel } from "@/modules/crm/features/dashboard/types/dashboard-types";

const priorityConfig: Record<
  PriorityLevel,
  { dot: string; label: string }
> = {
  high: { dot: "bg-priority-high", label: "High" },
  medium: { dot: "bg-dashboard-priority-medium-fill", label: "Medium" },
  low: { dot: "bg-dashboard-priority-low-fill", label: "Low" },
};

type Props = {
  priority: PriorityLevel;
  /**
   * Label form.
   *  - "compact" (default) → "High". Used in the Leads table, where the column header already
   *    says PRIORITY, so repeating the word would be redundant.
   *  - "full" → "High Priority". Used by the Dashboard's Lead Priority Overview cards, which
   *    carry no surrounding column header.
   * Colour, dot, and sizing are identical in both forms.
   */
  variant?: "compact" | "full";
};

export function PriorityChip({ priority, variant = "compact" }: Props) {
  const config = priorityConfig[priority];
  const label = variant === "full" ? `${config.label} Priority` : config.label;

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium",
        priority === "high" && "bg-dashboard-priority-high-badge text-red-500",
        priority === "medium" && "bg-dashboard-priority-medium-badge text-dashboard-priority-medium-fill",
        priority === "low" && "bg-dashboard-priority-low-badge text-dashboard-priority-low-fill",
      )}
    >
      <span
        className={cn("h-1.5 w-1.5 rounded-full", config.dot)}
        aria-hidden
      />
      <span className="whitespace-nowrap">{label}</span>
    </span>
  );
}
