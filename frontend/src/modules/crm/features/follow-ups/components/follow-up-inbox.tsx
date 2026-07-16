"use client";

import { useState, useMemo } from "react";
import { cn } from "@/modules/crm/lib/utils";
import { Badge } from "@/modules/crm/components/ui/badge";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { FollowUpListItem, statusChipConfig } from "@/modules/crm/features/follow-ups/components/follow-up-list-item";
import type { FollowUpItem, FollowUpStatus } from "@/modules/crm/features/follow-ups/types/follow-ups-types";

// Status keys shown in the filter dropdown, in display order (pending PlannerEvent statuses).
const STATUS_FILTER_ORDER: FollowUpStatus[] = [
  "todo",
  "scheduled",
  "in_progress",
];

type StatusFilter = FollowUpStatus | "all";

type FollowUpInboxProps = {
  items: FollowUpItem[];
  selectedId?: string;
  onSelectItem?: (id: string) => void;
  className?: string;
};

export function FollowUpInbox({ items, selectedId, onSelectItem, className }: FollowUpInboxProps) {
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");

  // Client-side filter over the already-loaded inbox (no additional request).
  const visibleItems = useMemo(
    () => (statusFilter === "all" ? items : items.filter((item) => item.status === statusFilter)),
    [items, statusFilter],
  );

  return (
    <section
      className={cn(
        "w-full flex-col overflow-hidden rounded-xl border border-border bg-card shadow-card md:w-[384px] md:shrink-0",
        className,
      )}
      aria-label="Follow-up inbox"
    >
      <div className="flex items-center gap-2 border-b border-border-subtle px-4 py-4 md:px-6">
        <SectionHeader title="Follow-ups Pending" className="mb-0" />
        <Badge className="bg-[var(--inbox-badge-bg)] text-white hover:bg-[var(--inbox-badge-bg)]">
          {visibleItems.length}
        </Badge>
        <div className="ml-auto">
          <Select value={statusFilter} onValueChange={(v) => setStatusFilter((v as StatusFilter) ?? "all")}>
            <SelectTrigger className="h-7 rounded-lg border border-border bg-card px-2 py-1 text-xs">
              <SelectValue>
                {(value) =>
                  value === "all" || !value
                    ? "All"
                    : statusChipConfig[value as FollowUpStatus].label
                }
              </SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All</SelectItem>
              {STATUS_FILTER_ORDER.map((status) => (
                <SelectItem key={status} value={status}>
                  {statusChipConfig[status].label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto" role="list">
        {visibleItems.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-muted-foreground md:px-6">
            {items.length === 0 ? "No pending follow-ups." : "No matching follow-ups."}
          </p>
        ) : (
          visibleItems.map((item) => (
            <div key={item.id} role="listitem">
              <FollowUpListItem
                item={item}
                selected={item.id === selectedId}
                onSelect={() => onSelectItem?.(item.id)}
              />
            </div>
          ))
        )}
      </div>
    </section>
  );
}
