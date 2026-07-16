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
          : "bg-cream text-gray-900",
      )}
    >
      <span className={cn("text-sm font-bold leading-none", isToday ? "text-white" : "text-gray-600")}>
        {day}
      </span>
      <span className={cn("text-[10px] font-medium uppercase leading-none", isToday ? "text-white" : "text-gray-500")}>
        {weekday}
      </span>
    </div>
  );
}
