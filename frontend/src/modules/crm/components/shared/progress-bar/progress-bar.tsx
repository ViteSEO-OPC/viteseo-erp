import { cn } from "@/modules/crm/lib/utils";
import { Progress, ProgressTrack, ProgressIndicator } from "@/modules/crm/components/ui/progress";
import type { PriorityLevel } from "@/modules/crm/features/dashboard/types/dashboard-types";

const toneColor: Record<PriorityLevel, string> = {
  high: "bg-red-500",
  medium: "bg-dashboard-priority-medium-fill",
  // Low tone is rendered only by the Lead Priority Overview "low" card.
  low: "bg-dashboard-priority-low-fill",
};

type Props = {
  value: number;
  max?: number;
  tone: PriorityLevel;
  className?: string;
};

export function ProgressBar({ value, max = 100, tone, className }: Props) {
  return (
    <Progress
      value={value}
      className={cn("flex-wrap", className)}
    >
      <ProgressTrack className="h-2 rounded-full bg-secondary">
        <ProgressIndicator
          className={cn("rounded-full transition-all", toneColor[tone])}
          style={{ width: `${(value / max) * 100}%` }}
        />
      </ProgressTrack>
    </Progress>
  );
}
