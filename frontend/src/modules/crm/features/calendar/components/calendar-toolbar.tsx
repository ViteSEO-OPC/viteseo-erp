"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";
import type { PlannerView } from "@/modules/crm/components/shared/calendar-grid/calendar-grid";

const VIEWS: { value: PlannerView; label: string }[] = [
  { value: "month", label: "Month" },
  { value: "week", label: "Week" },
  { value: "day", label: "Day" },
  { value: "year", label: "Year" },
  { value: "schedule", label: "Schedule" },
];

type CalendarToolbarProps = {
  title: string;
  view: PlannerView;
  onViewChange: (view: PlannerView) => void;
  onPrev: () => void;
  onNext: () => void;
  onToday: () => void;
};

export function CalendarToolbar({
  title,
  view,
  onViewChange,
  onPrev,
  onNext,
  onToday,
}: CalendarToolbarProps) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-2">
        <h2 className="text-[20px] font-bold text-foreground">{title}</h2>
        <Button variant="outline" size="icon-sm" onClick={onPrev} aria-label="Previous period">
          <ChevronLeft className="h-4 w-4" />
        </Button>
        <Button variant="outline" size="icon-sm" onClick={onNext} aria-label="Next period">
          <ChevronRight className="h-4 w-4" />
        </Button>
        <Button variant="secondary" size="sm" onClick={onToday}>
          Today
        </Button>
      </div>

      <div className="flex items-center gap-2">
        {/* View switcher — a segmented control across the five Planner presentations. All views
            read the same shared dataset, so switching is instant and never refetches. */}
        <div
          role="tablist"
          aria-label="Calendar view"
          className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-0.5"
        >
          {VIEWS.map((v) => (
            <button
              key={v.value}
              type="button"
              role="tab"
              aria-selected={view === v.value}
              onClick={() => onViewChange(v.value)}
              className={cn(
                "rounded-md px-3 py-1 text-[13px] font-medium transition-colors",
                view === v.value
                  ? "bg-card text-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {v.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
