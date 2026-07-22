"use client";

import Link from "next/link";
import { cn } from "@/modules/crm/lib/utils";
import { useLeadStatusSummary } from "@/modules/crm/features/leads/hooks/use-leads-data";

/**
 * Lead Summary tabs — status count strip (TOTAL/NEW/CONTACTED/QUALIFIED/DECLINED).
 * Live counts from /dashboard/kpis. Each tab is a link that filters the Leads list via the
 * existing ?status= URL mechanism (TOTAL clears it); the active tab reflects the current URL.
 * Labels are presentation-only pending BD terminology; the label→status mapping lives in
 * STATUS_TAB_MAP (single source, shared with the counts).
 */
export function LeadsSummaryTabs() {
  const { data: items } = useLeadStatusSummary();

  return (
    // Per the approved design: individually-bordered pills rather than one joined segmented
    // control, with sentence-case labels and the count carrying the colour. Links, hrefs, active
    // state, and the ?status= mechanism are unchanged.
    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Lead status summary">
      {items.map((item) => (
        <Link
          key={item.key}
          href={item.href}
          aria-current={item.active ? "true" : undefined}
          className={cn(
            "flex items-center gap-2 rounded-lg border px-3.5 py-2 transition-colors",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            item.active
              ? "border-primary-accent/50 bg-primary/5"
              : "border-border bg-card hover:bg-muted",
          )}
        >
          <span className="text-sm font-medium text-secondary-foreground">{item.label}</span>
          <span className={cn("text-sm font-bold tabular-nums", item.className)}>
            {item.count.toLocaleString()}
          </span>
        </Link>
      ))}
    </div>
  );
}
