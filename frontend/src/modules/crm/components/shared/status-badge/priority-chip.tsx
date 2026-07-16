import { cn } from "@/modules/crm/lib/utils";
import type { PriorityLevel } from "@/modules/crm/features/dashboard/types/dashboard-types";

const priorityConfig: Record<
  PriorityLevel,
  { dot: string; label: string }
> = {
  high: { dot: "bg-priority-high", label: "High" },
  medium: { dot: "bg-priority-medium", label: "Medium" },
  low: { dot: "bg-priority-low", label: "Low" },
};

type Props = {
  priority: PriorityLevel;
};

export function PriorityChip({ priority }: Props) {
  const config = priorityConfig[priority];

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium",
        priority === "high" && "bg-red-500/10 text-red-500",
        priority === "medium" && "bg-chart-3/10 text-yellow-800 dark:text-chart-3",
        priority === "low" && "bg-teal-400/10 text-teal-900 dark:text-teal-400",
      )}
    >
      <span
        className={cn("h-1.5 w-1.5 rounded-full", config.dot)}
        aria-hidden
      />
      <span>{config.label}</span>
    </span>
  );
}
