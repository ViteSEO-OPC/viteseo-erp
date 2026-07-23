"use client";

import { useState } from "react";
import { Clock, Phone } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { ActivityTimelineList } from "@/modules/crm/features/leads/components/activity-timeline-list";
import { CallHistoryList } from "@/modules/crm/features/leads/components/call-history-list";
import { useLeadCalls } from "@/modules/crm/features/leads/hooks/use-lead-calls";
import type { TimelineEvent } from "@/modules/crm/features/leads/types/lead-detail-types";

type PanelTab = "activity" | "calls";

type Props = {
  leadId: string;
  activities?: TimelineEvent[];
  activitiesLoading?: boolean;
  activitiesError?: boolean;
};

/**
 * The right-rail tabbed panel: Activity Timeline | Call History. Replaces the standalone
 * ActivityTimeline card — the Activity tab reuses the exact same {@link ActivityTimelineList} feed,
 * the Call History tab renders {@link CallHistoryList}. The segmented control follows the existing
 * OutreachTabs pattern (there is no shadcn Tabs primitive in this codebase).
 */
export function LeadTimelinePanel({ leadId, activities, activitiesLoading, activitiesError }: Props) {
  const [tab, setTab] = useState<PanelTab>("activity");
  const { calls, isLoading, isError } = useLeadCalls(leadId);

  const tabs = [
    { key: "activity" as const, label: "Activity Timeline", icon: Clock },
    { key: "calls" as const, label: "Call History", icon: Phone },
  ];

  return (
    <div className="rounded-2xl border border-border bg-card p-6 shadow-card">
      <div
        className="mb-5 flex w-full items-center gap-1 rounded-xl border border-border bg-card p-1"
        role="group"
        aria-label="Timeline views"
      >
        {tabs.map(({ key, label, icon: Icon }) => {
          const isActive = tab === key;
          return (
            <button
              key={key}
              type="button"
              aria-pressed={isActive}
              onClick={() => setTab(key)}
              className={cn(
                // Secondary-navigation tabs: an icon + a single-line, medium-weight label (no count
                // badge). Same token colors + rounded segmented control; whitespace-nowrap guarantees
                // the title never wraps. `flex-1 basis-0 min-w-0` + `justify-center` splits the
                // full-width control into two EQUAL, centered halves (min-w-0 lets the longer label's
                // half shrink to exactly 50% instead of its content width).
                "flex flex-1 basis-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-transparent px-2 py-1 text-sm font-medium transition-colors min-w-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
                isActive
                  ? "border-border bg-card text-teal-900 shadow-sm"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              <Icon
                className={cn("h-4 w-4", isActive ? "text-teal-500" : "text-muted-foreground")}
                aria-hidden
              />
              <span>{label}</span>
            </button>
          );
        })}
      </div>

      {tab === "activity" ? (
        <ActivityTimelineList
          events={activities}
          isLoading={activitiesLoading}
          isError={activitiesError}
        />
      ) : (
        <CallHistoryList calls={calls} isLoading={isLoading} isError={isError} />
      )}
    </div>
  );
}
