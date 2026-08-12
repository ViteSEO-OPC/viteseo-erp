"use client";

import { cn } from "@/modules/crm/lib/utils";

type DateChipProps = {
  day: number;
  weekday: string;
  isToday: boolean;
};

export function DateChip({ day, weekday, isToday }: DateChipProps) {
  return (
    <div
      className={cn(
        "flex w-10 flex-col items-center rounded-[8px] px-2 py-1.5",
        isToday
          ? "bg-[var(--upcoming-chip-today)] text-white"
          // Neutral subtle surface in both themes (it was a cream tint, the one warm note in an
          // otherwise white rail); the fixed gray ink needs a dark counterpart.
          : "bg-secondary text-gray-900 dark:text-foreground",
      )}
    >
      <span className={cn("text-sm font-bold leading-none", isToday ? "text-white" : "text-gray-600 dark:text-foreground")}>
        {day}
      </span>
      <span className={cn("text-[10px] font-medium uppercase leading-none", isToday ? "text-white" : "text-gray-500 dark:text-muted-foreground")}>
        {weekday}
      </span>
    </div>
  );
}
