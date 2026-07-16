import { cn } from "@/modules/crm/lib/utils";
import type { DeltaIndicator as DeltaIndicatorType } from "@/modules/crm/features/dashboard/types/dashboard-types";

const toneStyles: Record<
  DeltaIndicatorType["tone"],
  string
> = {
  positive: "text-green-700",
  warning: "text-red-800",
  neutral: "text-kpi-caption",
};

const arrowMap = {
  up: "▲",
  down: "▼",
  flat: "—",
} as const;

type Props = {
  delta: DeltaIndicatorType;
};

export function DeltaIndicator({ delta }: Props) {
  return (
    <span
      className={cn(
        "mt-5 inline-flex items-center gap-1 text-xs font-medium",
        toneStyles[delta.tone],
      )}
    >
      {delta.tone !== "warning" && (
        <span className="text-[10px]" aria-hidden>
          {arrowMap[delta.direction]}
        </span>
      )}
      <span>{delta.text}</span>
    </span>
  );
}
