"use client";

import { Clock, Mail } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";

export type OutreachTab = "followups" | "drafts";

type OutreachTabsProps = {
  active: OutreachTab;
  onChange: (tab: OutreachTab) => void;
  followUpsCount: number;
  draftsCount: number;
};

// Segmented control switching the Outreach workspace between the Follow-ups and Drafts views.
export function OutreachTabs({ active, onChange, followUpsCount, draftsCount }: OutreachTabsProps) {
  const tabs = [
    { key: "followups" as const, label: "Follow-ups", icon: Clock, count: followUpsCount },
    { key: "drafts" as const, label: "Drafts", icon: Mail, count: draftsCount },
  ];

  return (
    <div
      className="inline-flex items-center gap-1 rounded-xl border border-border bg-card p-1"
      role="group"
      aria-label="Outreach views"
    >
      {tabs.map((tab) => {
        const isActive = active === tab.key;
        const Icon = tab.icon;
        return (
          <button
            key={tab.key}
            type="button"
            aria-pressed={isActive}
            onClick={() => onChange(tab.key)}
            className={cn(
              "flex items-center gap-2 rounded-lg border border-transparent px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
              isActive
                ? "border-border bg-card text-teal-900 shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            <Icon
              className={cn("h-4 w-4", isActive ? "text-teal-500" : "text-muted-foreground")}
              aria-hidden
            />
            <span>{tab.label}</span>
            <span
              className={cn(
                "inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold",
                isActive
                  ? "bg-[var(--chip-teal-bg)] text-teal-800"
                  : "bg-muted text-muted-foreground",
              )}
            >
              {tab.count}
            </span>
          </button>
        );
      })}
    </div>
  );
}
