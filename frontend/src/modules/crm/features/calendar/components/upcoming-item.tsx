"use client";

import { DateChip } from "@/modules/crm/features/calendar/components/date-chip";
import type { UpcomingItem as UpcomingItemType } from "@/modules/crm/features/calendar/types/calendar-types";

const TYPE_DOT_COLORS: Record<string, string> = {
  email: "var(--chart-1, #4FD8D7)",
  call: "var(--chart-5, #7B9EF9)",
  meeting: "var(--chart-3, #F9C74F)",
  task: "var(--chart-2, #F08080)",
};

type UpcomingItemProps = {
  item: UpcomingItemType;
};

export function UpcomingItem({ item }: UpcomingItemProps) {
  return (
    <div className="flex items-start gap-3 border-b border-border-subtle py-3 last:border-b-0">
      <DateChip day={item.day} weekday={item.weekday} isToday={item.isToday} />
      <div className="flex items-start gap-2 pt-1">
        <span
          className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full"
          style={{ backgroundColor: TYPE_DOT_COLORS[item.type] ?? TYPE_DOT_COLORS.task }}
          aria-hidden
        />
        <div>
          <p className="text-sm font-bold text-foreground">{item.title}</p>
          <p className="text-xs text-muted-foreground">{item.time}</p>
        </div>
      </div>
    </div>
  );
}
