import { cn } from "@/modules/crm/lib/utils";
import type { LeadStatus } from "@/modules/crm/features/dashboard/types/dashboard-types";

const statusConfig: Record<
  LeadStatus,
  { dot: string; label: string; chipBg: string; chipText: string }
> = {
  interested: {
    dot: "bg-teal-500",
    label: "Interested",
    chipBg: "bg-teal-400/10",
    chipText: "text-teal-900 dark:text-teal-400",
  },
  "awaiting-reply": {
    dot: "bg-gray-500",
    label: "Awaiting Reply",
    chipBg: "bg-muted",
    chipText: "text-secondary-foreground",
  },
  declined: {
    dot: "bg-red-500",
    label: "Declined",
    chipBg: "bg-red-500/10",
    chipText: "text-red-800 dark:text-red-500",
  },
  "call-back-req": {
    dot: "bg-teal-500",
    label: "Call-back Req.",
    chipBg: "bg-teal-400/10",
    chipText: "text-teal-900 dark:text-teal-400",
  },
};

type Props = {
  status: LeadStatus;
};

export function StatusChip({ status }: Props) {
  // Defensive fallback for statuses outside the four mapped values (e.g. a
  // freshly-created lead defaults to "new"). Renders in the Info/Blue family
  // (Design-Tokens §17.2) rather than crashing. Does not change the status model.
  const config =
    statusConfig[status] ??
    {
      dot: "bg-blue-500",
      label: String(status).charAt(0).toUpperCase() + String(status).slice(1).replace(/-/g, " "),
      chipBg: "bg-blue-500/10",
      chipText: "text-blue-500",
    };

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium",
        config.chipBg,
        config.chipText,
      )}
    >
      <span className={cn("h-1.5 w-1.5 rounded-full", config.dot)} aria-hidden />
      <span>{config.label}</span>
    </span>
  );
}
