"use client";

import { cn } from "@/modules/crm/lib/utils";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import type { FollowUpItem, FollowUpStatus } from "@/modules/crm/features/follow-ups/types/follow-ups-types";

// Pending PlannerEvent statuses (the `completed` state never reaches the inbox — Mark Done removes
// it). Labels/colors mirror the Planner Board so a follow-up reads the same in both surfaces.
export const statusChipConfig: Record<FollowUpStatus, { label: string; bg: string; text: string; dot: string }> = {
  "todo": { label: "To Do", bg: "bg-[var(--chip-blue-bg)]", text: "text-blue-600", dot: "bg-blue-500" },
  "scheduled": { label: "Scheduled", bg: "bg-[var(--chip-teal-bg)]", text: "text-teal-900", dot: "bg-teal-500" },
  "in_progress": { label: "In Progress", bg: "bg-[var(--chip-amber-bg)]", text: "text-yellow-800", dot: "bg-yellow-800" },
};

type FollowUpListItemProps = {
  item: FollowUpItem;
  selected?: boolean;
  onSelect?: () => void;
};

export function FollowUpListItem({ item, selected, onSelect }: FollowUpListItemProps) {
  const chip = statusChipConfig[item.status];

  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        "flex w-full items-start gap-3 border-b border-border-subtle px-4 py-3.5 text-left transition-colors md:px-6",
        "min-h-[44px]",
        "hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
        selected && "bg-[var(--chip-teal-bg)] hover:bg-[var(--chip-teal-bg)]",
      )}
      aria-current={selected ? "true" : undefined}
    >
      <AvatarInitials initials={item.initials} size="sm" />
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            <span className="truncate text-sm font-bold text-gray-900">
              {item.name}
            </span>
            <span className="shrink-0 text-[11px] text-gray-500">
              {item.timestamp}
            </span>
          </div>
          <span
            className={cn(
              "inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium",
              chip.bg,
              chip.text,
            )}
          >
            <span className={cn("h-1.5 w-1.5 rounded-full", chip.dot)} aria-hidden />
            <span>{chip.label}</span>
          </span>
        </div>
        <p className="truncate text-[11px] text-gray-500">{item.company}</p>
        <p className="mt-1 truncate text-[12px] text-gray-600">{item.preview}</p>
      </div>
    </button>
  );
}
