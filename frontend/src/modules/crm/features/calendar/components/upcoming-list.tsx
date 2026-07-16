"use client";

import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { UpcomingItem } from "@/modules/crm/features/calendar/components/upcoming-item";
import type { UpcomingItem as UpcomingItemType } from "@/modules/crm/features/calendar/types/calendar-types";

type UpcomingListProps = {
  items: UpcomingItemType[];
};

export function UpcomingList({ items }: UpcomingListProps) {
  return (
    <aside className="w-full border-t border-border-subtle bg-card md:w-[305px] md:shrink-0 md:border-l md:border-t-0" aria-label="Upcoming events">
      <div className="px-4 pt-4">
        <SectionHeader title="Upcoming" className="mb-0" />
      </div>
      <div className="px-4 pb-4">
        {items.map((item) => (
          <UpcomingItem key={item.id} item={item} />
        ))}
      </div>
    </aside>
  );
}
